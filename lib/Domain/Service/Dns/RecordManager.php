<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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
use Poweradmin\Application\Service\DnssecProviderFactory;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\RecordRepository;
use Poweradmin\Domain\Service\DnsFormatter;
use Poweradmin\Domain\Service\DnsRecordValidationServiceInterface;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOCommon;
use Poweradmin\Infrastructure\Service\MessageService;
use Poweradmin\Infrastructure\Database\TableNameService;
use Poweradmin\Infrastructure\Database\PdnsTable;

/**
 * Service class for managing DNS records
 */
class RecordManager implements RecordManagerInterface
{
    private PDOCommon $db;
    private ConfigurationManager $config;
    private MessageService $messageService;
    private HostnameValidator $hostnameValidator;
    private DnsFormatter $dnsFormatter;
    private DnsRecordValidationServiceInterface $validationService;
    private SOARecordManagerInterface $soaRecordManager;
    private DomainRepositoryInterface $domainRepository;

    /**
     * Constructor
     *
     * @param PDOCommon $db Database connection
     * @param ConfigurationManager $config Configuration manager
     * @param DnsRecordValidationServiceInterface $validationService DNS record validation service
     * @param SOARecordManagerInterface $soaRecordManager SOA record manager
     * @param DomainRepositoryInterface $domainRepository Domain repository
     */
    public function __construct(
        PDOCommon $db,
        ConfigurationManager $config,
        DnsRecordValidationServiceInterface $validationService,
        SOARecordManagerInterface $soaRecordManager,
        DomainRepositoryInterface $domainRepository
    ) {
        $this->db = $db;
        $this->config = $config;
        $this->messageService = new MessageService();
        $this->hostnameValidator = new HostnameValidator($config);
        $this->dnsFormatter = new DnsFormatter($config);
        $this->validationService = $validationService;
        $this->soaRecordManager = $soaRecordManager;
        $this->domainRepository = $domainRepository;
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
        if ($validationResult === null || !$validationResult->isValid()) {
            if ($validationResult !== null) {
                $this->messageService->addSystemError($validationResult->getFirstError());
            }
            return false;
        }

        // Extract validated values
        $validatedData = $validationResult->getData();
        $content = $validatedData['content'];
        $name = strtolower($validatedData['name']); // powerdns only searches for lower case records
        $validatedTtl = $validatedData['ttl'];
        $validatedPrio = $validatedData['prio'];

        // Create RecordRepository to check if record exists
        $recordRepository = new RecordRepository($this->db, $this->config);
        if ($recordRepository->recordExists($zone_id, $name, $type, $content)) {
            $this->messageService->addSystemError(_('A record with this hostname, type, and content already exists.'));
            return false;
        }

        $this->db->beginTransaction();

        $tableNameService = new TableNameService($this->config);
        $records_table = $tableNameService->getTable(PdnsTable::RECORDS);

        $query = "INSERT INTO $records_table (domain_id, name, type, content, ttl, prio) VALUES (:zone_id, :name, :type, :content, :ttl, :prio)";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':zone_id', $zone_id, PDO::PARAM_INT);
        $stmt->bindValue(':name', $name, PDO::PARAM_STR);
        $stmt->bindValue(':type', $type, PDO::PARAM_STR);
        $stmt->bindValue(':content', $content, PDO::PARAM_STR);
        $stmt->bindValue(':ttl', $validatedTtl, PDO::PARAM_INT);
        $stmt->bindValue(':prio', $validatedPrio, PDO::PARAM_INT);
        $stmt->execute();
        $this->db->commit();

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
     * @return int|null The new record ID, or null on failure
     * @throws Exception
     */
    public function addRecordGetId(int $zone_id, string $name, string $type, string $content, int $ttl, mixed $prio): ?int
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
        $recordRepository = new RecordRepository($this->db, $this->config);
        if ($recordRepository->recordExists($zone_id, $name, $type, $content)) {
            $this->messageService->addSystemError(_('A record with this hostname, type, and content already exists.'));
            return null;
        }

        $this->db->beginTransaction();

        $tableNameService = new TableNameService($this->config);
        $records_table = $tableNameService->getTable(PdnsTable::RECORDS);

        $query = "INSERT INTO $records_table (domain_id, name, type, content, ttl, prio) VALUES (:zone_id, :name, :type, :content, :ttl, :prio)";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':zone_id', $zone_id, PDO::PARAM_INT);
        $stmt->bindValue(':name', $name, PDO::PARAM_STR);
        $stmt->bindValue(':type', $type, PDO::PARAM_STR);
        $stmt->bindValue(':content', $content, PDO::PARAM_STR);
        $stmt->bindValue(':ttl', $validatedTtl, PDO::PARAM_INT);
        $stmt->bindValue(':prio', $validatedPrio, PDO::PARAM_INT);
        $stmt->execute();

        // Get the newly inserted record ID
        $recordId = (int)$this->db->lastInsertId();

        $this->db->commit();

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
            if ($validationResult !== null && $validationResult->isValid()) {
                // Extract validated values
                $validatedData = $validationResult->getData();
                $content = $validatedData['content'];
                $name = strtolower($validatedData['name']); // powerdns only searches for lower case records
                $validatedTtl = $validatedData['ttl'];
                $validatedPrio = $validatedData['prio'];

                $tableNameService = new TableNameService($this->config);
                $records_table = $tableNameService->getTable(PdnsTable::RECORDS);

                $stmt = $this->db->prepare("UPDATE $records_table
                SET name = ?, type = ?, content = ?, ttl = ?, prio = ?, disabled = ?
                WHERE id = ?");
                $stmt->execute([
                    $name,
                    $record['type'],
                    $content,
                    $validatedTtl,
                    $validatedPrio,
                    $record['disabled'],
                    $record['rid']
                ]);
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
     * @param int $rid Record ID
     *
     * @return boolean true on success
     */
    public function deleteRecord(int $rid): bool
    {
        $perm_edit = Permission::getEditPermission($this->db);

        // Create RecordRepository to get record details
        $recordRepository = new RecordRepository($this->db, $this->config);
        $record = $recordRepository->getRecordDetailsFromRecordId($rid);
        $user_is_zone_owner = UserManager::verifyUserIsOwnerZoneId($this->db, $record['zid']);

        if ($perm_edit == "all" || (($perm_edit == "own" || $perm_edit == "own_as_client") && $user_is_zone_owner == "1")) {
            if ($record['type'] == "SOA") {
                // SOA record deletion is based on permissions
                // Own_as_client users cannot delete SOA records
                if ($perm_edit == "own_as_client") {
                    $this->messageService->addSystemError(_('You do not have the permission to delete SOA records.'));
                    return false;
                }

                // Admins and regular zone owners can delete SOA records
                $tableNameService = new TableNameService($this->config);
                $records_table = $tableNameService->getTable(PdnsTable::RECORDS);

                $stmt = $this->db->prepare("DELETE FROM $records_table WHERE id = ?");
                $stmt->execute([$rid]);
                return true;
            } elseif ($record['type'] == "NS" && $perm_edit == "own_as_client") {
                // Users with own_as_client permission cannot delete NS records
                $this->messageService->addSystemError(_('You do not have the permission to delete NS records.'));
                return false;
            } else {
                $tableNameService = new TableNameService($this->config);
                $records_table = $tableNameService->getTable(PdnsTable::RECORDS);

                $stmt = $this->db->prepare("DELETE FROM $records_table WHERE id = ?");
                $stmt->execute([$rid]);
                return true;
            }
        } else {
            $this->messageService->addSystemError(_("You do not have the permission to delete this record."));
            return false;
        }
    }

    /**
     * Delete record reference to zone template
     *
     * @param int $rid Record ID
     *
     * @return boolean true on success
     */
    public static function deleteRecordZoneTempl($db, int $rid): bool
    {
        $stmt = $db->prepare("DELETE FROM records_zone_templ WHERE record_id = ?");
        $stmt->execute([$rid]);

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
