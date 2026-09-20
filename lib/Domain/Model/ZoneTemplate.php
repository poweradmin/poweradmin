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

namespace Poweradmin\Domain\Model;

use Exception;
use Poweradmin\Application\Service\DnsBackendProviderFactory;
use Poweradmin\Domain\Service\DnsBackendProviderInterface;
use Poweradmin\Domain\Service\DnsFormatter;
use Poweradmin\Domain\Service\DomainParsingService;
use Poweradmin\Domain\Service\DnsValidation\DnsValidatorRegistry;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Domain\Service\ZoneTemplateRecordValidationService;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Repository\ZoneTemplateRepositoryInterface;
use LogicException;
use PDO;
use Poweradmin\Infrastructure\Repository\DbUserRepository;
use Poweradmin\Infrastructure\Service\MessageService;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Zone template records and the placeholder expansion that turns them into real records.
 */
class ZoneTemplate
{
    private ConfigurationInterface $config;
    private PDO $db;
    private DnsFormatter $dnsFormatter;
    private MessageService $messageService;
    private ?DnsBackendProviderInterface $backendProvider;
    private LoggerInterface $logger;
    private ?PermissionService $permissionService = null;
    private ?ZoneTemplateRecordValidationService $recordValidationService = null;
    private ?ZoneTemplateRepositoryInterface $repository;

    public function __construct(PDO $db, ConfigurationInterface $config, ?DnsBackendProviderInterface $backendProvider = null, ?LoggerInterface $logger = null, ?ZoneTemplateRepositoryInterface $repository = null)
    {
        $this->db = $db;
        $this->config = $config;
        $this->dnsFormatter = new DnsFormatter($config);
        $this->messageService = new MessageService();
        $this->backendProvider = $backendProvider;
        $this->logger = $logger ?? new NullLogger();
        $this->repository = $repository;
    }

    /**
     * Builds a repository for a bare connection. Registered once at start-up so
     * this model never names a persistence class; see AppInitializer.
     *
     * @var (callable(object, ?ConfigurationInterface, ?DnsBackendProviderInterface): ZoneTemplateRepositoryInterface)|null
     */
    private static $repositoryResolver = null;

    /**
     * Teaches the model how to build its repository. The composition root calls
     * this; the static accessors below are handed a bare connection and have no
     * other way to reach persistence.
     *
     * @param callable(object, ?ConfigurationInterface, ?DnsBackendProviderInterface): ZoneTemplateRepositoryInterface $resolver
     */
    public static function useRepositoryResolver(callable $resolver): void
    {
        self::$repositoryResolver = $resolver;
    }

    /**
     * Persistence for zone_templ and zone_templ_records. Resolved on demand so
     * the paths that only expand placeholders never touch the database layer.
     */
    private function repository(): ZoneTemplateRepositoryInterface
    {
        return $this->repository ??= self::resolveRepository($this->db, $this->config, $this->backendProvider);
    }

    /**
     * Repository for the static accessors, which are handed a bare connection.
     * Those are read-only lookups, so no configuration is needed.
     */
    private static function readRepository(object $db): ZoneTemplateRepositoryInterface
    {
        return self::resolveRepository($db, null, null);
    }

    /**
     * @param ConfigurationInterface|null $config Needed by the write paths; the
     *        read-only static accessors are handed a bare connection
     * @param DnsBackendProviderInterface|null $backendProvider Decides how the zone
     *        listings read PowerDNS state; the static accessors never list zones
     */
    private static function resolveRepository(
        object $db,
        ?ConfigurationInterface $config,
        ?DnsBackendProviderInterface $backendProvider
    ): ZoneTemplateRepositoryInterface {
        if (self::$repositoryResolver === null) {
            throw new LogicException(
                'No zone template repository resolver registered; call ZoneTemplate::useRepositoryResolver() at start-up.'
            );
        }

        return (self::$repositoryResolver)($db, $config, $backendProvider);
    }

    /**
     * Check if the logged-in user has the given permission (admins always pass)
     */
    private function currentUserHasPermission(string $permission): bool
    {
        $userId = (new UserContextService())->getLoggedInUserId();
        if ($userId === null) {
            return false;
        }
        $this->permissionService ??= new PermissionService(new DbUserRepository($this->db, $this->config));
        return $this->permissionService->hasPermission($userId, $permission);
    }

    /**
     * Callers that construct the model without a provider can still validate records,
     * so resolve one from config. Not stored: the null-probes above keep their meaning.
     */
    private function backendProvider(): DnsBackendProviderInterface
    {
        return $this->backendProvider ?? DnsBackendProviderFactory::create($this->db, $this->config, $this->logger);
    }

    /**
     * Check a template record against the validator for its type.
     *
     * Built on demand because the validator registry instantiates every record-type
     * validator, which is wasted work on the paths that never store a record.
     */
    private function validateTemplateRecord(string $name, string $type, string $content, mixed $ttl, mixed $prio): ValidationResult
    {
        $this->recordValidationService ??= new ZoneTemplateRecordValidationService(
            new DnsValidatorRegistry($this->config, $this->backendProvider())
        );

        return $this->recordValidationService->validate(
            $name,
            $type,
            $content,
            $ttl,
            $prio,
            (int)$this->config->get('dns', 'ttl', 3600)
        );
    }

    /**
     * Replace domain and specific placeholders in DNS records with template placeholders
     *
     * @param string $domain
     * @param array $record
     * @param array $options
     * @return array
     */
    public static function replaceWithTemplatePlaceholders(string $domain, array $record, array $options = []): array
    {
        if (empty($domain)) {
            return [$record['name'], $record['content']];
        }

        // Parse domain into its components
        $domainComponents = DomainParsingService::parseDomain($domain);
        $domainName = $domainComponents['domain']; // Example: 'example' from 'example.com'
        $tld = $domainComponents['tld'];           // Example: 'com' from 'example.com'

        // Replace domain in name field
        $pattern = '/(\.)?' . preg_quote($domain, '/') . '$/';
        $name = preg_replace($pattern, '$1[ZONE]', $record['name']);

        // Replace domain in content field - first handle direct matches
        $content = preg_replace($pattern, '$1[ZONE]', $record['content']);

        // Now handle cases like example-com.mail.protection.outlook.com
        // Look for domain parts that might be used in content like example-com or example-net
        if (!empty($domainName) && !empty($tld)) {
            $domainHyphenatedPattern = $domainName . '-' . $tld;

            // Replace example-com with [DOMAIN]-[TLD]
            if (strpos($content, $domainHyphenatedPattern) !== false) {
                $content = str_replace($domainHyphenatedPattern, '[DOMAIN]-[TLD]', $content);
            }

            // We'll only use [DOMAIN] and [TLD] placeholders for specific patterns
            // where we can't use [ZONE] directly, like domain-tld formats
            // We won't replace standalone domain and TLD components by default
        }

        // Special handling for SOA records
        if (isset($record['type']) && $record['type'] === 'SOA') {
            $parts = explode(' ', $content);

            if (isset($options['NS1']) && $parts[0] === $options['NS1']) {
                $parts[0] = '[NS1]';
            }

            if (isset($options['HOSTMASTER']) && $parts[1] === $options['HOSTMASTER']) {
                $parts[1] = '[HOSTMASTER]';
            }

            // Any numeric serial becomes [SERIAL]; a literal serial in a template
            // would only stamp stale values into zones created from it. Serial 0
            // is kept: it means autoserial and getNextSerial() preserves it.
            if (isset($parts[2]) && ctype_digit($parts[2]) && $parts[2] !== '0') {
                $parts[2] = '[SERIAL]';
            }

            $content = implode(' ', $parts);
        }

        return [$name, $content];
    }

    /**
     * Get a list of all available zone templates
     *
     * @param int $userid User ID
     *
     * @return array array of zone templates [id,name,descr]
     */
    public function getListZoneTempl(int $userid): array
    {
        return $this->repository()->listZoneTemplates(
            $userid,
            $this->currentUserHasPermission(Permission::PERM_USER_IS_UEBERUSER)
        );
    }

    /**
     * Resolve the system-wide default zone template
     *
     * Resolution order:
     *  1. The template with `is_default = 1` (global only, `owner = 0`)
     *  2. The `dns.default_zone_template` config setting (id or name)
     *  3. null (no default; the form falls back to "none")
     *
     * Names that resolve to multiple templates cause the config setting to be
     * skipped with a warning logged - operators must use the id or rename one
     * of the duplicates.
     *
     * @return int|null Template id, or null if no default is configured
     */
    public function getDefaultTemplateId(): ?int
    {
        $dbId = $this->repository()->findFlaggedDefaultTemplateId();
        if ($dbId !== null) {
            return $dbId;
        }

        $configured = $this->config->get('dns', 'default_zone_template', null);
        if ($configured === null || $configured === '') {
            return null;
        }

        if (is_int($configured) || (is_string($configured) && ctype_digit($configured))) {
            $id = (int) $configured;
            if (!$this->repository()->globalTemplateExists($id)) {
                $this->logger->warning(
                    'Poweradmin: dns.default_zone_template = {id} does not match any global zone template; falling back to "none".',
                    ['id' => $id]
                );
                return null;
            }
            return $id;
        }

        if (is_string($configured)) {
            $matches = $this->repository()->findGlobalTemplateIdsByName($configured);
            if (count($matches) === 0) {
                $this->logger->warning(
                    'Poweradmin: dns.default_zone_template = "{name}" does not match any global zone template; falling back to "none".',
                    ['name' => $configured]
                );
                return null;
            }
            if (count($matches) > 1) {
                $this->logger->warning(
                    'Poweradmin: dns.default_zone_template = "{name}" matches {count} global zone templates; ignoring the setting. Use the template id or rename one of the duplicates.',
                    ['name' => $configured, 'count' => count($matches)]
                );
                return null;
            }
            return (int) $matches[0];
        }

        return null;
    }

    /**
     * Mark a template as the system-wide default. Clears `is_default` from all
     * other templates so only one is flagged at a time. Personal templates
     * (`owner != 0`) cannot be marked default.
     *
     * @param int $zone_templ_id Template id to flag default
     * @return bool True on success, false if the template is not global or does not exist
     */
    public function setDefaultTemplate(int $zone_templ_id): bool
    {
        $ownerVal = $this->repository()->getOwner($zone_templ_id);
        if ($ownerVal === null) {
            $this->messageService->addSystemError(_('Zone template not found.'));
            return false;
        }
        if ($ownerVal !== 0) {
            $this->messageService->addSystemError(_('Only global zone templates can be set as the default.'));
            return false;
        }

        try {
            $this->repository()->flagDefaultTemplate($zone_templ_id);
            return true;
        } catch (Exception $e) {
            $this->messageService->addSystemError(_('Error setting default zone template: ') . $e->getMessage());
            return false;
        }
    }

    /**
     * Clear the system-wide default zone template flag.
     *
     * @return bool True on success
     */
    public function unsetDefaultTemplate(): bool
    {
        try {
            $this->repository()->clearDefaultTemplate();
            return true;
        } catch (Exception $e) {
            $this->messageService->addSystemError(_('Error clearing default zone template: ') . $e->getMessage());
            return false;
        }
    }

    /**
     * Add a zone template
     *
     * @param array $details zone template details
     * @param int $userid User ID that owns template
     *
     * @return boolean true on success, false otherwise
     */
    public function addZoneTempl(array $details, int $userid): bool
    {
        $zone_name_exists = $this->zoneTemplNameExists($details['templ_name']);

        if (!($this->currentUserHasPermission(Permission::PERM_ZONE_TEMPL_ADD))) {
            $this->messageService->addSystemError(_("You do not have the permission to add a zone template."));
            return false;
        } elseif ($zone_name_exists != '0') {
            $this->messageService->addSystemError(_('Zone template with this name already exists, please choose another one.'));
        } else {
            try {
                // The repository writes the template and its default SOA record in
                // one transaction. Only ueberusers may create a global template;
                // others get a personal one. created_by is always the current user.
                $this->repository()->createZoneTemplate(
                    $details['templ_name'],
                    $details['templ_descr'],
                    $this->resolveTemplateOwner(isset($details['templ_global']), $userid),
                    $userid
                );

                return true;
            } catch (Exception $e) {
                $this->messageService->addSystemError(_('Error creating zone template: ') . $e->getMessage());
                return false;
            }
        }
        return false;
    }

    public static function getZoneTemplName($db, $zone_id)
    {
        return self::readRepository($db)->getTemplateNameForZone($zone_id);
    }

    /**
     * Get name and description of template based on template ID
     *
     * @param PDO $db Database connection
     * @param int $zone_templ_id Zone template ID
     *
     * @return array zone template details
     */
    public static function getZoneTemplDetails($db, int $zone_templ_id): array
    {
        return self::readRepository($db)->getZoneTemplateDetails($zone_templ_id) ?: [];
    }

    /** Delete a zone template
     *
     * @param int $zone_templ_id Zone template ID
     *
     * @return boolean true on success, false otherwise
     */
    public function deleteZoneTempl(int $zone_templ_id): bool
    {
        if (!($this->currentUserHasPermission(Permission::PERM_ZONE_TEMPL_EDIT))) {
            $this->messageService->addSystemError(_("You do not have the permission to delete zone templates."));
            return false;
        } else {
            try {
                return $this->repository()->deleteZoneTemplate($zone_templ_id);
            } catch (Exception $e) {
                $this->messageService->addSystemError(_('Error deleting zone template: ') . $e->getMessage());
                return false;
            }
        }
    }

    /**
     * Count zone template records
     *
     * @param $db
     * @param int $zone_templ_id Zone template ID
     *
     * @return int number of records
     */
    public static function countZoneTemplRecords($db, int $zone_templ_id): int
    {
        return self::readRepository($db)->countZoneTemplateRecords($zone_templ_id);
    }

    /**
     * Check if zone template exist
     *
     * @param PDO $db Database connection
     * @param int $zone_templ_id Zone template ID
     *
     * @return boolean true on success, false otherwise
     */
    public static function zoneTemplIdExists($db, int $zone_templ_id): bool
    {
        return self::readRepository($db)->zoneTemplateExists($zone_templ_id);
    }

    /**
     * Get a zone template record from an id
     *
     * Retrieve all fields of the record and send it back to the function caller.
     *
     * @param PDO $db Database connection
     * @param int $id zone template record id
     * @param int|null $zone_templ_id restrict the lookup to this template; callers that
     *                                authorised a template must pass it so a record id
     *                                from another template cannot be read
     *
     * @return array zone template record
     * [id,zone_templ_id,name,type,content,ttl,prio] or an empty array if nothing is found
     */
    public static function getZoneTemplRecordFromId($db, int $id, ?int $zone_templ_id = null): array
    {
        return self::readRepository($db)->getZoneTemplateRecordById($id, $zone_templ_id);
    }

    /**
     * Get all zone template records from a zone template id
     *
     * Retrieve all fields of the records and send it back to the function caller.
     *
     * @param PDO $db Database connection
     * @param int $id zone template ID
     * @param int $rowstart Starting row (default=0)
     * @param int $rowamount Number of rows per query (default=9999)
     * @param string $sortby Column to sort by (default='name')
     *
     * @return array zone template records numerically indexed
     * [id,zone_templd_id,name,type,content,ttl,pro] or empty array if nothing is found
     */
    public static function getZoneTemplRecords($db, int $id, int $rowstart = 0, int $rowamount = Constants::DEFAULT_MAX_ROWS, string $sortby = 'name'): array
    {
        return self::readRepository($db)->getZoneTemplateRecords($id, $rowstart, $rowamount, $sortby);
    }

    /**
     * Add a record for a zone template
     *
     * This function validates and if correct it inserts it into the database.
     *
     * @param int $zone_templ_id zone template ID
     * @param string $name name part of record
     * @param string $type record type
     * @param string $content record content
     * @param int $ttl TTL
     * @param int $prio Priority
     *
     * @return boolean true if successful, false otherwise
     */
    public function addZoneTemplRecord(int $zone_templ_id, string $name, string $type, string $content, int $ttl, int $prio): bool
    {
        if (!($this->currentUserHasPermission(Permission::PERM_ZONE_TEMPL_EDIT))) {
            $this->messageService->addSystemError(_("You do not have the permission to add a record to this zone template."));
            return false;
        }

        if (!$this->canStoreTemplateRecordType($type)) {
            $this->messageService->addSystemError(_('You do not have the permission to add this record type to a zone template.'));
            return false;
        }

        if ($content == '') {
            $this->messageService->addSystemError(_('Your content field doesnt have a legit value.'));
            return false;
        }

        if ($name == '') {
            $this->messageService->addSystemError(_('Invalid hostname.'));
            return false;
        }

        // Check if priority is valid for this record type
        if ($prio < 0 || $prio > 65535) {
            if ($type == 'MX' || $type == 'SRV') {
                $this->messageService->addSystemError(_('Priority for MX/SRV records must be a number between 0 and 65535.'));
                return false;
            }
        }

        // Add double quotes to content if it is a TXT record and dns_txt_auto_quote is enabled
        $content = $this->dnsFormatter->formatContent($type, $content);

        $validationResult = $this->validateTemplateRecord($name, $type, $content, $ttl, $prio);
        if (!$validationResult->isValid()) {
            $this->messageService->addSystemError($validationResult->getFirstError());
            return false;
        }

        try {
            $this->repository()->addRecord($zone_templ_id, $name, $type, $content, $ttl, $prio);
        } catch (Exception $e) {
            $this->messageService->addSystemError(_('Error adding zone template record: ') . $e->getMessage());
            return false;
        }

        return true;
    }

    /**
     * Confirm the current user may store this record type in a zone template.
     *
     * @param string $type DNS record type
     *
     * @return boolean true when the type is allowed
     */
    private function canStoreTemplateRecordType(string $type): bool
    {
        return !Permission::isTemplateRecordTypeRestricted($type, Permission::getEditPermission($this->db, $this->config));
    }

    /**
     * Resolve the owner column for a template. A global template (owner 0) is
     * reserved for ueberusers; anyone else owns the template personally.
     *
     * @param bool $requestedGlobal whether the request asked for a global template
     * @param int $userid the current user id
     *
     * @return int 0 for a permitted global template, otherwise the user id
     */
    private function resolveTemplateOwner(bool $requestedGlobal, int $userid): int
    {
        if ($requestedGlobal && $this->currentUserHasPermission(Permission::PERM_USER_IS_UEBERUSER)) {
            return 0;
        }

        return $userid;
    }

    /**
     * Modify zone template record
     *
     * Edit a record for a zone template.
     * This function validates it if correct it inserts it into the database.
     *
     * @param array $record zone record array
     * @param int $zone_templ_id template the caller is authorized to edit
     *
     * @return boolean true on success, false otherwise
     */
    public function editZoneTemplRecord(array $record, int $zone_templ_id): bool
    {
        if (!($this->currentUserHasPermission(Permission::PERM_ZONE_TEMPL_EDIT))) {
            $this->messageService->addSystemError(_("You do not have permission to edit this record."));
            return false;
        }

        // Reject a record id that lives in another template, even when the caller owns this one.
        $storedRecord = self::getZoneTemplRecordFromId($this->db, (int)($record['rid'] ?? 0), $zone_templ_id);
        if (empty($storedRecord)) {
            $this->messageService->addSystemError(_('The record does not belong to this zone template.'));
            return false;
        }

        // Both types are gated: checking only the submitted one would let a
        // protected record be overwritten by relabelling it as an allowed type.
        if (
            !$this->canStoreTemplateRecordType((string)$storedRecord['type'])
            || !$this->canStoreTemplateRecordType($record['type'] ?? '')
        ) {
            $this->messageService->addSystemError(_('You do not have the permission to add this record type to a zone template.'));
            return false;
        }

        if ($record['name'] == "") {
            $this->messageService->addSystemError(_('Invalid hostname.'));
            return false;
        }

        // Check if priority is valid for this record type
        if (!is_numeric($record['prio']) || $record['prio'] < 0 || $record['prio'] > 65535) {
            if ($record['type'] == 'MX' || $record['type'] == 'SRV') {
                $this->messageService->addSystemError(_('Priority for MX/SRV records must be a number between 0 and 65535.'));
                return false;
            }
        }

        // Add double quotes to content if it is a TXT record and dns_txt_auto_quote is enabled
        $record['content'] = $this->dnsFormatter->formatContent($record['type'], $record['content']);

        $validationResult = $this->validateTemplateRecord(
            $record['name'],
            $record['type'],
            $record['content'],
            $record['ttl'],
            $record['prio'] ?? 0
        );
        if (!$validationResult->isValid()) {
            $this->messageService->addSystemError($validationResult->getFirstError());
            return false;
        }

        try {
            return $this->repository()->updateRecord(
                (int)$record['rid'],
                (string)$record['name'],
                (string)$record['type'],
                (string)$record['content'],
                (int)$record['ttl'],
                (int)($record['prio'] ?? 0)
            );
        } catch (Exception $e) {
            $this->messageService->addSystemError(_('Error updating zone template record: ') . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete a record for a zone template by a given id
     *
     * @param int $rid template record id
     * @param int $zone_templ_id template the caller is authorized to edit
     *
     * @return boolean true on success, false otherwise
     */
    public function deleteZoneTemplRecord(int $rid, int $zone_templ_id): bool
    {
        if (!($this->currentUserHasPermission(Permission::PERM_ZONE_TEMPL_EDIT))) {
            $this->messageService->addSystemError(_("You do not have the permission to delete this record."));
            return false;
        }

        // Reject a record id that lives in another template, even when the caller owns this one.
        $storedRecord = self::getZoneTemplRecordFromId($this->db, $rid, $zone_templ_id);
        if (empty($storedRecord)) {
            $this->messageService->addSystemError(_('The record does not belong to this zone template.'));
            return false;
        }

        // A caller who may not create this type may not remove one either.
        if (!$this->canStoreTemplateRecordType((string)$storedRecord['type'])) {
            $this->messageService->addSystemError(_('You do not have the permission to delete this record type from a zone template.'));
            return false;
        }

        try {
            return $this->repository()->deleteRecord($rid);
        } catch (Exception $e) {
            $this->messageService->addSystemError(_('Error deleting zone template record: ') . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if the session user is the owner for the zone template
     *
     * @param int $zone_templ_id zone template id
     * @param int $userid user id
     *
     * @return boolean true on success, false otherwise
     */
    /**
     * Whether a posted template id may be applied to a zone.
     *
     * Follows the listing scope: no template, a global template (owner 0), the
     * user's own template, or any template for an administrator.
     *
     * @param mixed $zone_templ_id Posted template id ("none", "", 0 or an id)
     */
    public function canUseTemplate(mixed $zone_templ_id, int $userid, bool $isAdmin): bool
    {
        if ($zone_templ_id === null || $zone_templ_id === '' || $zone_templ_id === 'none') {
            return true;
        }
        if (!is_numeric($zone_templ_id)) {
            return false;
        }
        $zone_templ_id = (int)$zone_templ_id;
        if ($zone_templ_id === 0) {
            return true;
        }
        if ($isAdmin) {
            return true;
        }

        $owner = $this->repository()->getOwner($zone_templ_id);
        if ($owner === null) {
            return false;
        }

        return $owner === 0 || $owner === $userid;
    }

    /**
     * canUseTemplate() for the logged-in user.
     */
    public function canCurrentUserUseTemplate(mixed $zone_templ_id): bool
    {
        $userId = (int)((new UserContextService())->getLoggedInUserId() ?? 0);

        return $this->canUseTemplate($zone_templ_id, $userId, $this->currentUserHasPermission(Permission::PERM_USER_IS_UEBERUSER));
    }

    public function isUserOwnerOfTemplate(int $zone_templ_id, int $userid): bool
    {
        try {
            return $this->repository()->isOwner($zone_templ_id, $userid);
        } catch (Exception $e) {
            $this->messageService->addSystemError(_('Error checking template ownership: ') . $e->getMessage());
            return false;
        }
    }

    /**
     * Add a zone template from zone / another template
     *
     * @param string $template_name template name
     * @param string $description description
     * @param int $userid user id
     * @param array $records array of zone records
     * @param array $options
     * @param string $domain domain to substitute with '[ZONE]' (optional) [default=null]
     *
     * @return boolean true on success, false otherwise
     */
    public function addZoneTemplSaveAs(string $template_name, string $description, int $userid, array $records, array $options, string $domain = ''): bool
    {
        if (!($this->currentUserHasPermission(Permission::PERM_ZONE_TEMPL_ADD))) {
            $this->messageService->addSystemError(_("You do not have the permission to add a zone template."));
            return false;
        } else {
            try {
                // A global template (owner 0) is reserved for ueberusers.
                $isGlobal = isset($options['global']) && $options['global'] === true;
                $owner = $this->resolveTemplateOwner($isGlobal, $userid);

                $skippedTypes = [];
                $templateRecords = [];

                foreach ($records as $record) {
                    // Skip rather than fail: a saved zone legitimately carries records
                    // the caller may read but not author. Skipped types are reported below.
                    if (!$this->canStoreTemplateRecordType((string)$record['type'])) {
                        $skippedTypes[strtoupper((string)$record['type'])] = true;
                        continue;
                    }

                    list($name, $content) = self::replaceWithTemplatePlaceholders($domain, $record, $options);

                    $templateRecords[] = [
                        'name' => $name,
                        'type' => $record['type'],
                        'content' => $content,
                        'ttl' => $record['ttl'],
                        'prio' => $record['prio'] ?? 0,
                    ];
                }

                // The repository writes the template, its records and - when the
                // records carry no SOA - a default SOA, in one transaction.
                $this->repository()->createZoneTemplateWithRecords(
                    $template_name,
                    $description,
                    $owner,
                    $userid,
                    $templateRecords
                );

                if ($skippedTypes !== []) {
                    $this->messageService->addSystemError(sprintf(
                        _('These record types were left out of the template because you may not add them: %s'),
                        implode(', ', array_keys($skippedTypes))
                    ));
                }

                return true;
            } catch (Exception $e) {
                // The repository already rolled its transaction back.
                $this->messageService->addSystemError(_('Error creating zone template: ') . $e->getMessage());
                return false;
            }
        }
    }

    /**
     * Get list of all zones using template
     *
     * @param int $zone_templ_id zone template id
     * @param int $userid user id
     *
     * @return array array of zones ids
     */
    public function getListZoneUseTempl(int $zone_templ_id, int $userid): array
    {
        $ownerFilter = $this->linkedZoneOwnerFilter($userid);

        try {
            return $this->repository()->listLinkedZoneIds($zone_templ_id, $ownerFilter);
        } catch (Exception $e) {
            $this->messageService->addSystemError(_('Error retrieving zones using template: ') . $e->getMessage());
            return [];
        }
    }

    /**
     * Owner the zone listings are narrowed to: null when the user may edit every
     * zone, otherwise the user themselves.
     */
    private function linkedZoneOwnerFilter(int $userid): ?int
    {
        return Permission::getEditPermission($this->db, $this->config) != "all" ? $userid : null;
    }

    /**
     * Get id pairs for zones using a template.
     *
     * Returns rows of ['zone_id' => zones.id, 'domain_id' => zones.domain_id] so callers
     * can pass the right value to PowerDNS-side operations (domain_id) and to
     * Poweradmin-native sync tracking (zone_id). On SQL backends the result is
     * inner-joined against the PowerDNS domains table to skip orphaned mappings
     * whose domain_id no longer exists (those would crash later in updateZoneRecords).
     *
     * @param int $zone_templ_id zone template id
     * @param int $userid user id
     *
     * @return array<int, array{zone_id:int, domain_id:int}>
     */
    public function getZoneAndDomainIdsByTemplate(int $zone_templ_id, int $userid): array
    {
        $ownerFilter = $this->linkedZoneOwnerFilter($userid);

        try {
            return $this->repository()->listLinkedZoneIdPairs($zone_templ_id, $ownerFilter);
        } catch (Exception $e) {
            $this->messageService->addSystemError(_('Error retrieving zones using template: ') . $e->getMessage());
            return [];
        }
    }

    /**
     * Get detailed information about zones using a specific template
     *
     * @param int $zone_templ_id zone template id
     * @param int $userid user id
     *
     * @return array array of zone details
     */
    public function getZonesUsingTemplate(int $zone_templ_id, int $userid): array
    {
        $ownerFilter = $this->linkedZoneOwnerFilter($userid);

        try {
            return $this->repository()->listLinkedZones($zone_templ_id, $ownerFilter);
        } catch (Exception $e) {
            $this->messageService->addSystemError(_('Failed to get list of zones using template: ') . $e->getMessage());
            return [];
        }
    }

    /**
     * Modify zone template
     *
     * @param array $details array of new zone template details
     * @param int $zone_templ_id zone template id
     * @param int $user_id User ID that is editing the template
     *
     * @return boolean true on success, false otherwise
     */
    public function editZoneTempl(array $details, int $zone_templ_id, int $user_id): bool
    {
        $zone_name_exists = $this->zoneTemplNameAndIdExists($details['templ_name'], $zone_templ_id);
        if (!($this->currentUserHasPermission(Permission::PERM_ZONE_TEMPL_EDIT))) {
            $this->messageService->addSystemError(_("You do not have the permission to edit a zone template."));
            return false;
        } elseif ($zone_name_exists != '0') {
            $this->messageService->addSystemError(_('Zone template with this name already exists, please choose another one.'));
            return false;
        } else {
            // Making a template global (owner 0) is reserved for ueberusers; keep
            // created_by intact. A private template also loses the default flag,
            // which the repository clears along with a non-zero owner.
            return $this->repository()->updateZoneTemplate(
                $zone_templ_id,
                $details['templ_name'],
                $details['templ_descr'],
                $this->resolveTemplateOwner(isset($details['templ_global']), $user_id)
            );
        }
    }


    /**
     * Unlink a zone from its template
     *
     * @param int $zone_id Zone ID to unlink
     * @return bool True on success, false on failure
     */
    public function unlinkZoneFromTemplate(int $zone_id): bool
    {
        try {
            return $this->repository()->unlinkZoneFromTemplate($zone_id);
        } catch (Exception $e) {
            $this->messageService->addSystemError(_('Error unlinking zone from template: ') . $e->getMessage());
            return false;
        }
    }

    /**
     * Get zone details by zone IDs
     *
     * @param array $zone_ids Array of zone IDs
     * @return array Array of zone details
     */
    public function getZonesByIds(array $zone_ids): array
    {
        if (empty($zone_ids)) {
            return [];
        }

        try {
            return $this->repository()->getZonesByIds($zone_ids);
        } catch (Exception $e) {
            $this->messageService->addSystemError(_('Error retrieving zones: ') . $e->getMessage());
            return [];
        }
    }

    /**
     * Check if zone template name exists
     *
     * @param string $zone_templ_name zone template name
     *
     * @return bool number of matching templates
     */
    public function zoneTemplNameExists(string $zone_templ_name): bool
    {
        try {
            return $this->repository()->zoneTemplateNameExists($zone_templ_name);
        } catch (Exception $e) {
            $this->messageService->addSystemError(_('Error checking template name existence: ') . $e->getMessage());
            return false;
        }
    }

    /**
     * Get zone template IDs by name
     *
     * Returns all template IDs matching the given name. Since template names
     * are not unique in the database, multiple IDs may be returned.
     *
     * @param string $name Zone template name
     * @return int[] Array of matching template IDs
     */
    public function getZoneTemplIdsByName(string $name): array
    {
        try {
            return $this->repository()->findTemplateIdsByName($name);
        } catch (Exception $e) {
            $this->messageService->addSystemError(_('Error looking up template by name: ') . $e->getMessage());
            return [];
        }
    }

    /**
     * Check if zone template name and id exists
     *
     * @param string $zone_templ_name zone template name
     * @param int $zone_templ_id zone template id
     *
     * @return bool number of matching templates
     */
    public function zoneTemplNameAndIdExists(string $zone_templ_name, int $zone_templ_id): bool
    {
        try {
            return $this->repository()->zoneTemplateNameExists($zone_templ_name, $zone_templ_id);
        } catch (Exception $e) {
            $this->messageService->addSystemError(_('Error checking template existence: ') . $e->getMessage());
            return false;
        }
    }

    /**
     * Parse string and substitute domain and serial
     *
     * @param string $val string to parse containing tokens like '[ZONE]', '[SERIAL]', '[UNIXTIME]' or '[COUNTER]'
     * @param string $domain domain to substitute for '[ZONE]'
     * @param string|null $recordType record type of $val; when given it authoritatively
     *        decides SOA-timer completion. Legacy 2-arg callers fall back to a heuristic.
     *
     * @return string interpolated/parsed string
     */
    public function parseTemplateValue(string $val, string $domain, ?string $recordType = null): string
    {
        $dns_ns1 = $this->config->get('dns', 'ns1');
        $dns_ns2 = $this->config->get('dns', 'ns2');
        $dns_ns3 = $this->config->get('dns', 'ns3');
        $dns_ns4 = $this->config->get('dns', 'ns4');
        $dns_hostmaster = $this->config->get('dns', 'hostmaster');

        // Get SOA parameters for SOA records
        $soa_refresh = $this->config->get('dns', 'soa_refresh');
        $soa_retry = $this->config->get('dns', 'soa_retry');
        $soa_expire = $this->config->get('dns', 'soa_expire');
        $soa_minimum = $this->config->get('dns', 'soa_minimum');

        $serial = date("Ymd");
        $serial .= "00";

        // Parse domain components
        $domainComponents = DomainParsingService::parseDomain($domain);
        $domainName = $domainComponents['domain'];
        $tld = $domainComponents['tld'];

        $val = str_replace('[ZONE]', $domain, $val);
        $val = str_replace('[DOMAIN]', $domainName, $val);
        $val = str_replace('[TLD]', $tld, $val);
        $val = str_replace('[SERIAL]', $serial, $val);
        // Alternative SOA serial formats: both stay below 1979999999, so
        // getNextSerial() treats them as plain counters and bumps them by 1.
        $val = str_replace('[UNIXTIME]', (string)time(), $val);
        $val = str_replace('[COUNTER]', '1', $val);
        $val = str_replace('[NS1]', $dns_ns1, $val);
        $val = str_replace('[NS2]', $dns_ns2, $val);
        $val = str_replace('[NS3]', $dns_ns3, $val);
        $val = str_replace('[NS4]', $dns_ns4, $val);
        $val = str_replace('[HOSTMASTER]', $dns_hostmaster, $val);

        // Add SOA value placeholders
        $val = str_replace('[SOA_REFRESH]', $soa_refresh, $val);
        $val = str_replace('[SOA_RETRY]', $soa_retry, $val);
        $val = str_replace('[SOA_EXPIRE]', $soa_expire, $val);
        $val = str_replace('[SOA_MINIMUM]', $soa_minimum, $val);

        // Only SOA content gets timer completion. With an explicit record type we
        // decide precisely; without one (legacy 2-arg callers) we keep the old
        // substring heuristic so behavior is unchanged for them.
        $isSoaValue = $recordType !== null
            ? $recordType === RecordType::SOA
            : str_contains($val, 'SOA');
        if ($isSoaValue) {
            // A complete SOA rdata has at least 7 fields:
            // primary hostmaster serial refresh retry expire minimum
            if (count(explode(' ', $val)) < 7) {
                $val .= " $soa_refresh $soa_retry $soa_expire $soa_minimum";
            }
        }

        return $val;
    }
}
