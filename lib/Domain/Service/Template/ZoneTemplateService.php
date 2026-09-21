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

namespace Poweradmin\Domain\Service\Template;

use Exception;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\RecordType;
use Poweradmin\Domain\Port\DnsBackendProviderInterface;
use Poweradmin\Domain\Repository\ZoneTemplateRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Auth\UserContextService;
use Poweradmin\Domain\Service\DnsFormatter;
use Poweradmin\Domain\Service\DnsValidation\DnsValidatorRegistry;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use Psr\Log\LoggerInterface;

/**
 * Zone template use cases for the acting user: creating, editing and deleting
 * templates and their records, the default template, and which zones a
 * template is linked to. Writes report their outcome instead of flashing it.
 */
class ZoneTemplateService
{
    private ZoneTemplateRepositoryInterface $repository;
    private ConfigurationInterface $config;
    private DnsBackendProviderInterface $backendProvider;
    private PermissionService $permissionService;
    private UserContextService $userContext;
    private LoggerInterface $logger;
    private DnsFormatter $dnsFormatter;
    private ?ZoneTemplateRecordValidationService $recordValidationService = null;

    public function __construct(
        ZoneTemplateRepositoryInterface $repository,
        ConfigurationInterface $config,
        DnsBackendProviderInterface $backendProvider,
        PermissionService $permissionService,
        UserContextService $userContext,
        LoggerInterface $logger
    ) {
        $this->repository = $repository;
        $this->config = $config;
        $this->backendProvider = $backendProvider;
        $this->permissionService = $permissionService;
        $this->userContext = $userContext;
        $this->logger = $logger;
        $this->dnsFormatter = new DnsFormatter($config);
    }

    /**
     * Check if the logged-in user has the given permission (admins always pass)
     */
    private function currentUserHasPermission(string $permission): bool
    {
        $userId = $this->userContext->getLoggedInUserId();
        if ($userId === null) {
            return false;
        }
        return $this->permissionService->hasPermission($userId, $permission);
    }

    /**
     * The logged-in user's edit level: "all", "own", "own_as_client" or "none".
     */
    private function currentUserEditPermissionLevel(): string
    {
        $userId = $this->userContext->getLoggedInUserId();
        if ($userId === null) {
            return 'none';
        }
        return $this->permissionService->getEditPermissionLevel($userId);
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
            new DnsValidatorRegistry($this->config, $this->backendProvider)
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
     * Get a list of all available zone templates
     *
     * @param int $userid User ID
     *
     * @return array array of zone templates [id,name,descr]
     */
    public function getListZoneTempl(int $userid): array
    {
        return $this->repository->listZoneTemplates(
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
        $dbId = $this->repository->findFlaggedDefaultTemplateId();
        if ($dbId !== null) {
            return $dbId;
        }

        $configured = $this->config->get('dns', 'default_zone_template', null);
        if ($configured === null || $configured === '') {
            return null;
        }

        if (is_int($configured) || (is_string($configured) && ctype_digit($configured))) {
            $id = (int) $configured;
            if (!$this->repository->globalTemplateExists($id)) {
                $this->logger->warning(
                    'Poweradmin: dns.default_zone_template = {id} does not match any global zone template; falling back to "none".',
                    ['id' => $id]
                );
                return null;
            }
            return $id;
        }

        if (is_string($configured)) {
            $matches = $this->repository->findGlobalTemplateIdsByName($configured);
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
     */
    public function setDefaultTemplate(int $zone_templ_id): ZoneTemplateWriteResult
    {
        $ownerVal = $this->repository->getOwner($zone_templ_id);
        if ($ownerVal === null) {
            return ZoneTemplateWriteResult::failure(_('Zone template not found.'), 404);
        }
        if ($ownerVal !== 0) {
            return ZoneTemplateWriteResult::failure(_('Only global zone templates can be set as the default.'));
        }

        try {
            $this->repository->flagDefaultTemplate($zone_templ_id);
            return ZoneTemplateWriteResult::ok();
        } catch (Exception $e) {
            return ZoneTemplateWriteResult::backendFailure(_('Error setting default zone template: ') . $e->getMessage());
        }
    }

    /**
     * Clear the system-wide default zone template flag.
     */
    public function unsetDefaultTemplate(): ZoneTemplateWriteResult
    {
        try {
            $this->repository->clearDefaultTemplate();
            return ZoneTemplateWriteResult::ok();
        } catch (Exception $e) {
            return ZoneTemplateWriteResult::backendFailure(_('Error clearing default zone template: ') . $e->getMessage());
        }
    }

    /**
     * Add a zone template
     *
     * @param array $details zone template details
     * @param int $userid User ID that owns template
     */
    public function addZoneTempl(array $details, int $userid): ZoneTemplateWriteResult
    {
        if (!$this->currentUserHasPermission(Permission::PERM_ZONE_TEMPL_ADD)) {
            return ZoneTemplateWriteResult::forbidden(_("You do not have the permission to add a zone template."));
        }
        if ($this->zoneTemplNameExists($details['templ_name'])) {
            return ZoneTemplateWriteResult::failure(_('Zone template with this name already exists, please choose another one.'), 409);
        }

        try {
            // The repository writes the template and its default SOA record in
            // one transaction. Only ueberusers may create a global template;
            // others get a personal one. created_by is always the current user.
            $this->repository->createZoneTemplate(
                $details['templ_name'],
                $details['templ_descr'],
                $this->resolveTemplateOwner(isset($details['templ_global']), $userid),
                $userid
            );

            return ZoneTemplateWriteResult::ok();
        } catch (Exception $e) {
            return ZoneTemplateWriteResult::backendFailure(_('Error creating zone template: ') . $e->getMessage());
        }
    }

    /**
     * Delete a zone template
     */
    public function deleteZoneTempl(int $zone_templ_id): ZoneTemplateWriteResult
    {
        if (!$this->currentUserHasPermission(Permission::PERM_ZONE_TEMPL_EDIT)) {
            return ZoneTemplateWriteResult::forbidden(_("You do not have the permission to delete zone templates."));
        }

        try {
            $this->repository->deleteZoneTemplate($zone_templ_id);
            return ZoneTemplateWriteResult::ok();
        } catch (Exception $e) {
            return ZoneTemplateWriteResult::backendFailure(_('Error deleting zone template: ') . $e->getMessage());
        }
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
     */
    public function addZoneTemplRecord(int $zone_templ_id, string $name, string $type, string $content, int $ttl, int $prio): ZoneTemplateWriteResult
    {
        if (!$this->currentUserHasPermission(Permission::PERM_ZONE_TEMPL_EDIT)) {
            return ZoneTemplateWriteResult::forbidden(_("You do not have the permission to add a record to this zone template."));
        }

        if (!$this->canStoreTemplateRecordType($type)) {
            return ZoneTemplateWriteResult::forbidden(_('You do not have the permission to add this record type to a zone template.'));
        }

        if ($content == '') {
            return ZoneTemplateWriteResult::failure(_('Your content field doesnt have a legit value.'));
        }

        if ($name == '') {
            return ZoneTemplateWriteResult::failure(_('Invalid hostname.'));
        }

        if (($prio < 0 || $prio > 65535) && RecordType::hasPriority($type)) {
            return ZoneTemplateWriteResult::failure(_('Priority for MX/SRV records must be a number between 0 and 65535.'));
        }

        // Add double quotes to content if it is a TXT record and dns_txt_auto_quote is enabled
        $content = $this->dnsFormatter->formatContent($type, $content);

        $validationResult = $this->validateTemplateRecord($name, $type, $content, $ttl, $prio);
        if (!$validationResult->isValid()) {
            return ZoneTemplateWriteResult::failure((string)$validationResult->getFirstError());
        }

        try {
            $this->repository->addRecord($zone_templ_id, $name, $type, $content, $ttl, $prio);
        } catch (Exception $e) {
            return ZoneTemplateWriteResult::backendFailure(_('Error adding zone template record: ') . $e->getMessage());
        }

        return ZoneTemplateWriteResult::ok();
    }

    /**
     * Confirm the current user may store this record type in a zone template.
     */
    private function canStoreTemplateRecordType(string $type): bool
    {
        return !Permission::isTemplateRecordTypeRestricted($type, $this->currentUserEditPermissionLevel());
    }

    /**
     * Resolve the owner column for a template. A global template (owner 0) is
     * reserved for ueberusers; anyone else owns the template personally.
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
     * @param array $record zone record array
     * @param int $zone_templ_id template the caller is authorized to edit
     */
    public function editZoneTemplRecord(array $record, int $zone_templ_id): ZoneTemplateWriteResult
    {
        if (!$this->currentUserHasPermission(Permission::PERM_ZONE_TEMPL_EDIT)) {
            return ZoneTemplateWriteResult::forbidden(_("You do not have permission to edit this record."));
        }

        // Reject a record id that lives in another template, even when the caller owns this one.
        $storedRecord = $this->repository->getZoneTemplateRecordById((int)($record['rid'] ?? 0), $zone_templ_id);
        if (empty($storedRecord)) {
            return ZoneTemplateWriteResult::failure(_('The record does not belong to this zone template.'), 404);
        }

        // Both types are gated: checking only the submitted one would let a
        // protected record be overwritten by relabelling it as an allowed type.
        if (
            !$this->canStoreTemplateRecordType((string)$storedRecord['type'])
            || !$this->canStoreTemplateRecordType($record['type'] ?? '')
        ) {
            return ZoneTemplateWriteResult::forbidden(_('You do not have the permission to add this record type to a zone template.'));
        }

        if ($record['name'] == "") {
            return ZoneTemplateWriteResult::failure(_('Invalid hostname.'));
        }

        if (
            (!is_numeric($record['prio']) || $record['prio'] < 0 || $record['prio'] > 65535)
            && RecordType::hasPriority((string)$record['type'])
        ) {
            return ZoneTemplateWriteResult::failure(_('Priority for MX/SRV records must be a number between 0 and 65535.'));
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
            return ZoneTemplateWriteResult::failure((string)$validationResult->getFirstError());
        }

        try {
            $this->repository->updateRecord(
                (int)$record['rid'],
                (string)$record['name'],
                (string)$record['type'],
                (string)$record['content'],
                (int)$record['ttl'],
                (int)($record['prio'] ?? 0)
            );
        } catch (Exception $e) {
            return ZoneTemplateWriteResult::backendFailure(_('Error updating zone template record: ') . $e->getMessage());
        }

        return ZoneTemplateWriteResult::ok();
    }

    /**
     * Delete a record for a zone template by a given id
     *
     * @param int $rid template record id
     * @param int $zone_templ_id template the caller is authorized to edit
     */
    public function deleteZoneTemplRecord(int $rid, int $zone_templ_id): ZoneTemplateWriteResult
    {
        if (!$this->currentUserHasPermission(Permission::PERM_ZONE_TEMPL_EDIT)) {
            return ZoneTemplateWriteResult::forbidden(_("You do not have the permission to delete this record."));
        }

        // Reject a record id that lives in another template, even when the caller owns this one.
        $storedRecord = $this->repository->getZoneTemplateRecordById($rid, $zone_templ_id);
        if (empty($storedRecord)) {
            return ZoneTemplateWriteResult::failure(_('The record does not belong to this zone template.'), 404);
        }

        // A caller who may not create this type may not remove one either.
        if (!$this->canStoreTemplateRecordType((string)$storedRecord['type'])) {
            return ZoneTemplateWriteResult::forbidden(_('You do not have the permission to delete this record type from a zone template.'));
        }

        try {
            $this->repository->deleteRecord($rid);
        } catch (Exception $e) {
            return ZoneTemplateWriteResult::backendFailure(_('Error deleting zone template record: ') . $e->getMessage());
        }

        return ZoneTemplateWriteResult::ok();
    }

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

        $owner = $this->repository->getOwner($zone_templ_id);
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
        $userId = (int)($this->userContext->getLoggedInUserId() ?? 0);

        return $this->canUseTemplate($zone_templ_id, $userId, $this->currentUserHasPermission(Permission::PERM_USER_IS_UEBERUSER));
    }

    /**
     * Check if the user is the owner of the zone template
     */
    public function isUserOwnerOfTemplate(int $zone_templ_id, int $userid): bool
    {
        return $this->repository->isOwner($zone_templ_id, $userid);
    }

    /**
     * Add a zone template from zone / another template
     *
     * @param string $template_name template name
     * @param string $description description
     * @param int $userid user id
     * @param array $records array of zone records
     * @param array $options
     * @param string $domain domain to substitute with '[ZONE]' (optional)
     *
     * @return ZoneTemplateWriteResult Success carries a warning naming the record types left out
     */
    public function addZoneTemplSaveAs(string $template_name, string $description, int $userid, array $records, array $options, string $domain = ''): ZoneTemplateWriteResult
    {
        if (!$this->currentUserHasPermission(Permission::PERM_ZONE_TEMPL_ADD)) {
            return ZoneTemplateWriteResult::forbidden(_("You do not have the permission to add a zone template."));
        }

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

                [$name, $content] = ZoneTemplatePlaceholders::replaceWithTemplatePlaceholders($domain, $record, $options);

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
            $this->repository->createZoneTemplateWithRecords(
                $template_name,
                $description,
                $owner,
                $userid,
                $templateRecords
            );

            if ($skippedTypes !== []) {
                return ZoneTemplateWriteResult::ok(sprintf(
                    _('These record types were left out of the template because you may not add them: %s'),
                    implode(', ', array_keys($skippedTypes))
                ));
            }

            return ZoneTemplateWriteResult::ok();
        } catch (Exception $e) {
            // The repository already rolled its transaction back.
            return ZoneTemplateWriteResult::backendFailure(_('Error creating zone template: ') . $e->getMessage());
        }
    }

    /**
     * Get list of all zones using template
     *
     * @return array array of zones ids
     */
    public function getListZoneUseTempl(int $zone_templ_id, int $userid): array
    {
        return $this->repository->listLinkedZoneIds($zone_templ_id, $this->linkedZoneOwnerFilter($userid));
    }

    /**
     * Owner the zone listings are narrowed to: null when the user may edit every
     * zone, otherwise the user themselves.
     */
    private function linkedZoneOwnerFilter(int $userid): ?int
    {
        return $this->currentUserEditPermissionLevel() !== 'all' ? $userid : null;
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
     * @return array<int, array{zone_id:int, domain_id:int}>
     */
    public function getZoneAndDomainIdsByTemplate(int $zone_templ_id, int $userid): array
    {
        return $this->repository->listLinkedZoneIdPairs($zone_templ_id, $this->linkedZoneOwnerFilter($userid));
    }

    /**
     * Get detailed information about zones using a specific template
     *
     * @return array array of zone details
     */
    public function getZonesUsingTemplate(int $zone_templ_id, int $userid): array
    {
        return $this->repository->listLinkedZones($zone_templ_id, $this->linkedZoneOwnerFilter($userid));
    }

    /**
     * Modify zone template
     *
     * @param array $details array of new zone template details
     * @param int $zone_templ_id zone template id
     * @param int $user_id User ID that is editing the template
     */
    public function editZoneTempl(array $details, int $zone_templ_id, int $user_id): ZoneTemplateWriteResult
    {
        if (!$this->currentUserHasPermission(Permission::PERM_ZONE_TEMPL_EDIT)) {
            return ZoneTemplateWriteResult::forbidden(_("You do not have the permission to edit a zone template."));
        }
        if ($this->zoneTemplNameAndIdExists($details['templ_name'], $zone_templ_id)) {
            return ZoneTemplateWriteResult::failure(_('Zone template with this name already exists, please choose another one.'), 409);
        }

        // Making a template global (owner 0) is reserved for ueberusers; keep
        // created_by intact. A private template also loses the default flag,
        // which the repository clears along with a non-zero owner.
        $this->repository->updateZoneTemplate(
            $zone_templ_id,
            $details['templ_name'],
            $details['templ_descr'],
            $this->resolveTemplateOwner(isset($details['templ_global']), $user_id)
        );

        return ZoneTemplateWriteResult::ok();
    }

    /**
     * Unlink a zone from its template
     */
    public function unlinkZoneFromTemplate(int $zone_id): bool
    {
        return $this->repository->unlinkZoneFromTemplate($zone_id);
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

        return $this->repository->getZonesByIds($zone_ids);
    }

    /**
     * Check if a zone template exists
     */
    public function zoneTemplIdExists(int $zone_templ_id): bool
    {
        return $this->repository->zoneTemplateExists($zone_templ_id);
    }

    /**
     * Check if zone template name exists
     */
    public function zoneTemplNameExists(string $zone_templ_name): bool
    {
        return $this->repository->zoneTemplateNameExists($zone_templ_name);
    }

    /**
     * Get zone template IDs by name
     *
     * Template names are not unique, so several IDs may come back.
     *
     * @return int[] Array of matching template IDs
     */
    public function getZoneTemplIdsByName(string $name): array
    {
        return $this->repository->findTemplateIdsByName($name);
    }

    /**
     * Check if another zone template carries this name
     */
    public function zoneTemplNameAndIdExists(string $zone_templ_name, int $zone_templ_id): bool
    {
        return $this->repository->zoneTemplateNameExists($zone_templ_name, $zone_templ_id);
    }
}
