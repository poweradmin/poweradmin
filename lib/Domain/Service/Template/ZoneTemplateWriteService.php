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
use Poweradmin\Domain\Repository\ZoneTemplateRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Creating, editing and deleting zone templates, saving a zone as a template,
 * and the system-wide default template. Writes report their outcome instead
 * of flashing it.
 */
class ZoneTemplateWriteService
{
    private ZoneTemplateRepositoryInterface $repository;
    private ZoneTemplateAccessPolicy $access;
    private ConfigurationInterface $config;
    private LoggerInterface $logger;

    public function __construct(
        ZoneTemplateRepositoryInterface $repository,
        ZoneTemplateAccessPolicy $access,
        ConfigurationInterface $config,
        LoggerInterface $logger
    ) {
        $this->repository = $repository;
        $this->access = $access;
        $this->config = $config;
        $this->logger = $logger;
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
        if (!$this->access->currentUserHasPermission(Permission::PERM_ZONE_TEMPL_ADD)) {
            return ZoneTemplateWriteResult::forbidden(_("You do not have the permission to add a zone template."));
        }
        if ($this->repository->zoneTemplateNameExists($details['templ_name'])) {
            return ZoneTemplateWriteResult::failure(_('Zone template with this name already exists, please choose another one.'), 409);
        }

        try {
            // The repository writes the template and its default SOA record in
            // one transaction. Only ueberusers may create a global template;
            // others get a personal one. created_by is always the current user.
            $this->repository->createZoneTemplate(
                $details['templ_name'],
                $details['templ_descr'],
                $this->access->resolveTemplateOwner(isset($details['templ_global']), $userid),
                $userid
            );

            return ZoneTemplateWriteResult::ok();
        } catch (Exception $e) {
            return ZoneTemplateWriteResult::backendFailure(_('Error creating zone template: ') . $e->getMessage());
        }
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
        if (!$this->access->currentUserHasPermission(Permission::PERM_ZONE_TEMPL_EDIT)) {
            return ZoneTemplateWriteResult::forbidden(_("You do not have the permission to edit a zone template."));
        }
        if ($this->repository->zoneTemplateNameExists($details['templ_name'], $zone_templ_id)) {
            return ZoneTemplateWriteResult::failure(_('Zone template with this name already exists, please choose another one.'), 409);
        }

        // Making a template global (owner 0) is reserved for ueberusers; keep
        // created_by intact. A private template also loses the default flag,
        // which the repository clears along with a non-zero owner.
        $this->repository->updateZoneTemplate(
            $zone_templ_id,
            $details['templ_name'],
            $details['templ_descr'],
            $this->access->resolveTemplateOwner(isset($details['templ_global']), $user_id)
        );

        return ZoneTemplateWriteResult::ok();
    }

    /**
     * Delete a zone template
     */
    public function deleteZoneTempl(int $zone_templ_id): ZoneTemplateWriteResult
    {
        if (!$this->access->currentUserHasPermission(Permission::PERM_ZONE_TEMPL_EDIT)) {
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
        if (!$this->access->currentUserHasPermission(Permission::PERM_ZONE_TEMPL_ADD)) {
            return ZoneTemplateWriteResult::forbidden(_("You do not have the permission to add a zone template."));
        }

        try {
            // A global template (owner 0) is reserved for ueberusers.
            $isGlobal = isset($options['global']) && $options['global'] === true;
            $owner = $this->access->resolveTemplateOwner($isGlobal, $userid);

            $skippedTypes = [];
            $templateRecords = [];

            foreach ($records as $record) {
                // Skip rather than fail: a saved zone legitimately carries records
                // the caller may read but not author. Skipped types are reported below.
                if (!$this->access->canStoreTemplateRecordType((string)$record['type'])) {
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
}
