<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2026 Poweradmin Development Team
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace Poweradmin\Domain\Service\Dns;

use Exception;
use PDO;
use Poweradmin\Application\Service\DnsBackendProviderFactory;
use Poweradmin\Domain\Error\RecordIdNotFoundException;
use Poweradmin\Application\Service\DnssecProviderFactory;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Domain\Service\DnsFormatter;
use Poweradmin\Domain\Service\DnsRecordValidationServiceInterface;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\RecordChangeLogger;
use Poweradmin\Infrastructure\Service\MessageService;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Service class for managing DNS records
 */
class RecordManager implements RecordManagerInterface
{
    private PDO $db;
    private ConfigurationManager $config;
    private MessageService $messageService;
    private DnsFormatter $dnsFormatter;
    private DnsRecordValidationServiceInterface $validationService;
    private SOARecordManagerInterface $soaRecordManager;
    private DomainRepositoryInterface $domainRepository;
    private DnsBackendProvider $backendProvider;
    private LoggerInterface $logger;
    private RecordChangeLogger $changeLogger;

    /**
     * Constructor
     *
     * @param PDO $db Database connection
     * @param ConfigurationManager $config Configuration manager
     * @param DnsRecordValidationServiceInterface $validationService DNS record validation service
     * @param SOARecordManagerInterface $soaRecordManager SOA record manager
     * @param DomainRepositoryInterface $domainRepository Domain repository
     * @param DnsBackendProvider|null $backendProvider DNS backend provider (auto-created if null)
     */
    public function __construct(
        PDO $db,
        ConfigurationManager $config,
        DnsRecordValidationServiceInterface $validationService,
        SOARecordManagerInterface $soaRecordManager,
        DomainRepositoryInterface $domainRepository,
        ?DnsBackendProvider $backendProvider = null,
        ?LoggerInterface $logger = null,
        ?RecordChangeLogger $changeLogger = null
    ) {
        $this->db = $db;
        $this->config = $config;
        $this->messageService = new MessageService();
        $this->dnsFormatter = new DnsFormatter($config);
        $this->validationService = $validationService;
        $this->soaRecordManager = $soaRecordManager;
        $this->domainRepository = $domainRepository;
        $this->backendProvider = $backendProvider ?? DnsBackendProviderFactory::create($db, $config);
        $this->logger = $logger ?? new NullLogger();
        $this->changeLogger = $changeLogger ?? new RecordChangeLogger($db);
    }

    private function captureChange(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            $this->logger->warning('Failed to write record change log: {error}', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Add a record
     *
     * This function validates it if correct it inserts it into the database.
     *
     * @param int $zone_id Zone ID
     * @param string $name Name part of record
     * @param string $type Type of record
     * @param string $content Content of record
     * @param int $ttl Time-To-Live of record
     * @param mixed $prio Priority of record
     *
     * @return boolean true if successful
     * @throws Exception
     */
    public function addRecord(int $zone_id, string $name, string $type, string $content, int $ttl, mixed $prio): bool
    {
        $perm_edit = Permission::getEditPermission($this->db);

        $user_is_zone_owner = UserManager::verifyUserIsOwnerZoneId($this->db, $zone_id);
        $zone_type = $this->domainRepository->getDomainType($zone_id);

        if ($type == 'SOA' && $perm_edit == "own_as_client") {
            throw new Exception(_("You do not have the permission to add SOA record."));
        }

        if ($type == 'NS' && $perm_edit == "own_as_client") {
            throw new Exception(_("You do not have the permission to add NS record."));
        }

        if ($zone_type == "SLAVE" || $perm_edit == "none" || (($perm_edit == "own" || $perm_edit == "own_as_client") && $user_is_zone_owner == "0")) {
            throw new Exception(_("You do not have the permission to add a record to this zone."));
        }

        $dns_hostmaster = $this->config->get('dns', 'hostmaster');
        $dns_ttl = $this->config->get('dns', 'ttl');

        // Add double quotes to content if it is a TXT record and dns_txt_auto_quote is enabled
        $content = $this->dnsFormatter->formatContent($type, $content);

        // Normalize the name BEFORE validation
        $zone = $this->domainRepository->getDomainNameById($zone_id);
        $hostnameValidator = new HostnameValidator($this->config);
        $name = $hostnameValidator->normalizeRecordName($name, $zone);

        // Now validate the input with normalized name using the validation service
        $validationResult = $this->validationService->validateRecord(
            -1,
            $zone_id,
            $type,
            $content,
            $name,
            $prio,
            $ttl,
            $dns_hostmaster,
            (int)$dns_ttl
        );
        if (!$validationResult->isValid()) {
            $this->messageService->addSystemError($validationResult->getFirstError());
            return false;
        }

        // Extract validated values
        $validatedData = $validationResult->getData();
        $content = $validatedData['content'];
        $name = strtolower($validatedData['name']); // powerdns only searches for lower case records
        $validatedTtl = $validatedData['ttl'];
        $validatedPrio = $validatedData['prio'];

        // Create RecordRepository to check if record exists
        $recordRepository = (new \Poweradmin\Application\Service\RepositoryFactory($this->db, $this->config, $this->backendProvider))->createRecordRepository();
        if ($recordRepository->recordExists($zone_id, $name, $type, $content)) {
            $this->messageService->addSystemError(_('A record with this hostname, type, and content already exists.'));
            return false;
        }

        if (!$this->backendProvider->addRecord($zone_id, $name, $type, $content, $validatedTtl, $validatedPrio)) {
            $this->messageService->addSystemError(_('Failed to add record to DNS backend.'));
            return false;
        }

        $this->captureChange(function () use ($zone_id, $name, $type, $content, $validatedTtl, $validatedPrio): void {
            $zone_name = $this->domainRepository->getDomainNameById($zone_id);
            $this->changeLogger->logRecordCreate([
                'name' => $name,
                'type' => $type,
                'content' => $content,
                'ttl' => $validatedTtl,
                'prio' => $validatedPrio,
                'zone_name' => is_string($zone_name) ? $zone_name : null,
            ], $zone_id);
        });

        if ($type != 'SOA') {
            $this->soaRecordManager->updateSOASerial($zone_id);
        }

        $pdnssec_use = $this->config->get('dnssec', 'enabled');
        if ($pdnssec_use) {
            $dnssecProvider = DnssecProviderFactory::create($this->db, $this->config);
            $zone_name = $this->domainRepository->getDomainNameById($zone_id);
            if (is_string($zone_name)) {
                $dnssecProvider->rectifyZone($zone_name);
            }
        }

        return true;
    }

    /**
     * Add a record and return its ID
     *
     * This function validates and inserts a record into the database,
     * returning the new record's ID for use with per-record comments.
     *
     * @param int $zone_id Zone ID
     * @param string $name Name part of record
     * @param string $type Type of record
     * @param string $content Content of record
     * @param int $ttl Time-To-Live of record
     * @param mixed $prio Priority of record
     *
     * @return int|string|null The new record ID, or null on failure
     * @throws Exception
     */
    public function addRecordGetId(int $zone_id, string $name, string $type, string $content, int $ttl, mixed $prio): int|string|null
    {
        $perm_edit = Permission::getEditPermission($this->db);

        $user_is_zone_owner = UserManager::verifyUserIsOwnerZoneId($this->db, $zone_id);
        $zone_type = $this->domainRepository->getDomainType($zone_id);

        if ($type == 'SOA' && $perm_edit == "own_as_client") {
            throw new Exception(_("You do not have the permission to add SOA record."));
        }

        if ($type == 'NS' && $perm_edit == "own_as_client") {
            throw new Exception(_("You do not have the permission to add NS record."));
        }

        if ($zone_type == "SLAVE" || $perm_edit == "none" || (($perm_edit == "own" || $perm_edit == "own_as_client") && $user_is_zone_owner == "0")) {
            throw new Exception(_("You do not have the permission to add a record to this zone."));
        }

        $dns_hostmaster = $this->config->get('dns', 'hostmaster');
        $dns_ttl = $this->config->get('dns', 'ttl');

        // Add double quotes to content if it is a TXT record and dns_txt_auto_quote is enabled
        $content = $this->dnsFormatter->formatContent($type, $content);

        // Normalize the name BEFORE validation
        $zone = $this->domainRepository->getDomainNameById($zone_id);
        $hostnameValidator = new HostnameValidator($this->config);
        $name = $hostnameValidator->normalizeRecordName($name, $zone);

        // Now validate the input with normalized name using the validation service
        $validationResult = $this->validationService->validateRecord(
            -1,
            $zone_id,
            $type,
            $content,
            $name,
            $prio,
            $ttl,
            $dns_hostmaster,
            (int)$dns_ttl
        );
        if (!$validationResult->isValid()) {
            $this->messageService->addSystemError($validationResult->getFirstError());
            return null;
        }

        // Extract validated values
        $validatedData = $validationResult->getData();
        $content = $validatedData['content'];
        $name = strtolower($validatedData['name']); // powerdns only searches for lower case records
        $validatedTtl = $validatedData['ttl'];
        $validatedPrio = $validatedData['prio'];

        // Create RecordRepository to check if record exists
        $recordRepository = (new \Poweradmin\Application\Service\RepositoryFactory($this->db, $this->config, $this->backendProvider))->createRecordRepository();
        if ($recordRepository->recordExists($zone_id, $name, $type, $content)) {
            $this->messageService->addSystemError(_('A record with this hostname, type, and content already exists.'));
            return null;
        }

        try {
            $recordId = $this->backendProvider->addRecordGetId($zone_id, $name, $type, $content, $validatedTtl, $validatedPrio);
        } catch (RecordIdNotFoundException $e) {
            $this->logger->error('Failed to get record ID after creation: {error}', ['error' => $e->getMessage()]);
            return null;
        }

        $this->captureChange(function () use ($recordId, $zone_id, $name, $type, $content, $validatedTtl, $validatedPrio): void {
            $zone_name = $this->domainRepository->getDomainNameById($zone_id);
            $this->changeLogger->logRecordCreate([
                'id' => is_int($recordId) ? $recordId : (is_string($recordId) ? $recordId : null),
                'name' => $name,
                'type' => $type,
                'content' => $content,
                'ttl' => $validatedTtl,
                'prio' => $validatedPrio,
                'zone_name' => is_string($zone_name) ? $zone_name : null,
            ], $zone_id);
        });

        if ($type != 'SOA') {
            $this->soaRecordManager->updateSOASerial($zone_id);
        }

        $pdnssec_use = $this->config->get('dnssec', 'enabled');
        if ($pdnssec_use) {
            $dnssecProvider = DnssecProviderFactory::create($this->db, $this->config);
            $zone_name = $this->domainRepository->getDomainNameById($zone_id);
            if (is_string($zone_name)) {
                $dnssecProvider->rectifyZone($zone_name);
            }
        }

        return $recordId;
    }

    /**
     * Edit a record
     *
     * This function validates it if correct it inserts it into the database.
     *
     * @param array $record Record structure to update
     *
     * @return boolean true if successful
     */
    public function editRecord(array $record): bool
    {
        $dns_hostmaster = $this->config->get('dns', 'hostmaster');
        $perm_edit = Permission::getEditPermission($this->db);

        $user_is_zone_owner = UserManager::verifyUserIsOwnerZoneId($this->db, $record['zid']);
        $zone_type = $this->domainRepository->getDomainType($record['zid']);

        if ($record['type'] == 'SOA' && $perm_edit == "own_as_client") {
            $this->messageService->addSystemError(_("You do not have the permission to edit this SOA record."));

            return false;
        }
        if ($record['type'] == 'NS' && $perm_edit == "own_as_client") {
            $this->messageService->addSystemError(_("You do not have the permission to edit this NS record."));

            return false;
        }

        // Add double quotes to content if it is a TXT record and dns_txt_auto_quote is enabled
        $record['content'] = $this->dnsFormatter->formatContent($record['type'], $record['content']);

        $dns_ttl = $this->config->get('dns', 'ttl');

        if ($zone_type == "SLAVE" || $perm_edit == "none" || (($perm_edit == "own" || $perm_edit == "own_as_client") && $user_is_zone_owner == "0")) {
            $this->messageService->addSystemError(_("You do not have the permission to edit this record."));
        } else {
            $beforeRecord = null;
            try {
                $recordRepositoryForBefore = (new \Poweradmin\Application\Service\RepositoryFactory($this->db, $this->config, $this->backendProvider))->createRecordRepository();
                $beforeRecord = $recordRepositoryForBefore->getRecordDetailsFromRecordId($record['rid']);
            } catch (Throwable $e) {
                $this->logger->warning('Failed to fetch record before edit for change log: {error}', ['error' => $e->getMessage()]);
            }

            // Normalize the name BEFORE validation
            $zone = $this->domainRepository->getDomainNameById($record['zid']);
            $hostnameValidator = new HostnameValidator($this->config);
            $record['name'] = $hostnameValidator->normalizeRecordName($record['name'], $zone);

            // Now validate the input with normalized name using the validation service
            $validationResult = $this->validationService->validateRecord(
                $record['rid'],
                $record['zid'],
                $record['type'],
                $record['content'],
                $record['name'],
                (int)$record['prio'],
                (int)$record['ttl'],
                $dns_hostmaster,
                (int)$dns_ttl
            );
            if ($validationResult->isValid()) {
                // Extract validated values
                $validatedData = $validationResult->getData();
                $content = $validatedData['content'];
                $name = strtolower($validatedData['name']); // powerdns only searches for lower case records
                $validatedTtl = $validatedData['ttl'];
                $validatedPrio = $validatedData['prio'];

                if (
                    !$this->backendProvider->editRecord(
                        $record['rid'],
                        $name,
                        $record['type'],
                        $content,
                        $validatedTtl,
                        $validatedPrio,
                        $record['disabled']
                    )
                ) {
                    $this->messageService->addSystemError(_('Failed to update record in DNS backend.'));
                    return false;
                }

                if ($beforeRecord !== null) {
                    $afterRecord = [
                        'id' => $record['rid'],
                        'name' => $name,
                        'type' => $record['type'],
                        'content' => $content,
                        'ttl' => $validatedTtl,
                        'prio' => $validatedPrio,
                        'disabled' => $record['disabled'] ?? false,
                        'zone_name' => is_string($zone) ? $zone : null,
                    ];
                    $beforeForLog = $beforeRecord;
                    $beforeForLog['id'] = $record['rid'];
                    $beforeForLog['zone_name'] = is_string($zone) ? $zone : null;
                    $this->captureChange(function () use ($beforeForLog, $afterRecord, $record): void {
                        $this->changeLogger->logRecordEdit($beforeForLog, $afterRecord, (int) $record['zid']);
                    });
                }

                return true;
            } else {
                $this->messageService->addSystemError($validationResult->getFirstError());
            }
        }
        return false;
    }

    /**
     * Delete a record by a given record id
     *
     * @param int|string $rid Record ID
     *
     * @return boolean true on success
     */
    public function deleteRecord(int|string $rid): bool
    {
        $perm_edit = Permission::getEditPermission($this->db);

        // Create RecordRepository to get record details
        $recordRepository = (new \Poweradmin\Application\Service\RepositoryFactory($this->db, $this->config, $this->backendProvider))->createRecordRepository();
        $record = $recordRepository->getRecordDetailsFromRecordId($rid);
        $user_is_zone_owner = UserManager::verifyUserIsOwnerZoneId($this->db, $record['zid']);

        if ($perm_edit == "all" || (($perm_edit == "own" || $perm_edit == "own_as_client") && $user_is_zone_owner == "1")) {
            if ($record['type'] == "SOA" && $perm_edit == "own_as_client") {
                $this->messageService->addSystemError(_('You do not have the permission to delete SOA records.'));
                return false;
            }

            if ($record['type'] == "NS" && $perm_edit == "own_as_client") {
                $this->messageService->addSystemError(_('You do not have the permission to delete NS records.'));
                return false;
            }

            $deleted = $this->backendProvider->deleteRecord($rid);

            if ($deleted) {
                $this->captureChange(function () use ($record, $rid): void {
                    $zoneId = isset($record['zid']) ? (int) $record['zid'] : null;
                    $zoneName = null;
                    if ($zoneId !== null) {
                        $name = $this->domainRepository->getDomainNameById($zoneId);
                        $zoneName = is_string($name) ? $name : null;
                    }
                    $beforeForLog = $record;
                    $beforeForLog['id'] = $rid;
                    $beforeForLog['zone_name'] = $zoneName;
                    $this->changeLogger->logRecordDelete($beforeForLog, $zoneId);
                });
            }

            return $deleted;
        } else {
            $this->messageService->addSystemError(_("You do not have the permission to delete this record."));
            return false;
        }
    }

    /**
     * Delete record reference to zone template
     *
     * @param int|string $rid Record ID
     *
     * @return boolean true on success
     */
    public static function deleteRecordZoneTempl($db, int|string $rid): bool
    {
        // SQL record IDs live in records_zone_templ; API record IDs (encoded
        // RecordIdentifier strings) live in records_zone_templ_api. The two
        // tables segregate by ID type so at most one DELETE will match.
        $stmt = $db->prepare("DELETE FROM records_zone_templ WHERE record_id = ?");
        $stmt->execute([$rid]);

        $stmt = $db->prepare("DELETE FROM records_zone_templ_api WHERE record_id = ?");
        $stmt->execute([(string) $rid]);

        return true;
    }

    /**
     * Get Zone comment
     *
     * @param int $zone_id Zone ID
     *
     * @return string Zone Comment
     */
    public static function getZoneComment($db, int $zone_id): string
    {
        $stmt = $db->prepare("SELECT comment FROM zones WHERE domain_id = ?");
        $stmt->execute([$zone_id]);
        $comment = $stmt->fetchColumn();

        return $comment ?: '';
    }

    /**
     * Edit the zone comment
     *
     * This function validates it if correct it inserts it into the database.
     *
     * @param int $zone_id Zone ID
     * @param string $comment Comment to set
     *
     * @return boolean true on success
     */
    public function editZoneComment(int $zone_id, string $comment): bool
    {
        $perm_edit = Permission::getEditPermission($this->db);

        $user_is_zone_owner = UserManager::verifyUserIsOwnerZoneId($this->db, $zone_id);
        $zone_type = $this->domainRepository->getDomainType($zone_id);

        if ($zone_type == "SLAVE" || $perm_edit == "none" || (($perm_edit == "own" || $perm_edit == "own_as_client") && $user_is_zone_owner == "0")) {
            $this->messageService->addSystemError(_("You do not have the permission to edit this comment."));

            return false;
        } else {
            $query = "SELECT COUNT(*) FROM zones WHERE domain_id = :zone_id";
            $stmt = $this->db->prepare($query);
            $stmt->bindValue(':zone_id', $zone_id, PDO::PARAM_INT);
            $stmt->execute();

            $count = $stmt->fetchColumn();

            if ($count > 0) {
                $query = "UPDATE zones SET comment = :comment WHERE domain_id = :zone_id";
            } else {
                $query = "INSERT INTO zones (domain_id, owner, comment, zone_templ_id) VALUES (:zone_id, 1, :comment, 0)";
            }
            $stmt = $this->db->prepare($query);
            $stmt->bindValue(':zone_id', $zone_id, PDO::PARAM_INT);
            $stmt->bindValue(':comment', $comment, PDO::PARAM_STR);
            $stmt->execute();
        }
        return true;
    }
}
