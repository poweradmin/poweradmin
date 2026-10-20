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

use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Repository\ZoneTemplateRepositoryInterface;

/**
 * Facade over the zone template use cases, kept so existing callers keep one
 * entry point: ZoneTemplateWriteService (templates and the default flag),
 * ZoneTemplateRecordService (template records) and ZoneTemplateAccessPolicy
 * (who may use or owns a template). Lookups and linked-zone listings read the
 * repository directly. New code should depend on the specific service.
 */
class ZoneTemplateService
{
    private ZoneTemplateRepositoryInterface $repository;
    private ZoneTemplateAccessPolicy $access;
    private ZoneTemplateWriteService $writes;
    private ZoneTemplateRecordService $records;

    public function __construct(
        ZoneTemplateRepositoryInterface $repository,
        ZoneTemplateAccessPolicy $access,
        ZoneTemplateWriteService $writes,
        ZoneTemplateRecordService $records
    ) {
        $this->repository = $repository;
        $this->access = $access;
        $this->writes = $writes;
        $this->records = $records;
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
            $this->access->currentUserHasPermission(Permission::PERM_USER_IS_UEBERUSER)
        );
    }

    /**
     * @see ZoneTemplateWriteService::getDefaultTemplateId()
     */
    public function getDefaultTemplateId(): ?int
    {
        return $this->writes->getDefaultTemplateId();
    }

    /**
     * @see ZoneTemplateWriteService::setDefaultTemplate()
     */
    public function setDefaultTemplate(int $zone_templ_id): ZoneTemplateWriteResult
    {
        return $this->writes->setDefaultTemplate($zone_templ_id);
    }

    /**
     * @see ZoneTemplateWriteService::unsetDefaultTemplate()
     */
    public function unsetDefaultTemplate(): ZoneTemplateWriteResult
    {
        return $this->writes->unsetDefaultTemplate();
    }

    /**
     * @see ZoneTemplateWriteService::addZoneTempl()
     */
    public function addZoneTempl(array $details, int $userid): ZoneTemplateWriteResult
    {
        return $this->writes->addZoneTempl($details, $userid);
    }

    /**
     * @see ZoneTemplateWriteService::deleteZoneTempl()
     */
    public function deleteZoneTempl(int $zone_templ_id): ZoneTemplateWriteResult
    {
        return $this->writes->deleteZoneTempl($zone_templ_id);
    }

    /**
     * @see ZoneTemplateRecordService::addZoneTemplRecord()
     */
    public function addZoneTemplRecord(int $zone_templ_id, string $name, string $type, string $content, int $ttl, int $prio): ZoneTemplateWriteResult
    {
        return $this->records->addZoneTemplRecord($zone_templ_id, $name, $type, $content, $ttl, $prio);
    }

    /**
     * @see ZoneTemplateRecordService::editZoneTemplRecord()
     */
    public function editZoneTemplRecord(array $record, int $zone_templ_id): ZoneTemplateWriteResult
    {
        return $this->records->editZoneTemplRecord($record, $zone_templ_id);
    }

    /**
     * @see ZoneTemplateRecordService::deleteZoneTemplRecord()
     */
    public function deleteZoneTemplRecord(int $rid, int $zone_templ_id): ZoneTemplateWriteResult
    {
        return $this->records->deleteZoneTemplRecord($rid, $zone_templ_id);
    }

    /**
     * @see ZoneTemplateAccessPolicy::canUseTemplate()
     */
    public function canUseTemplate(mixed $zone_templ_id, int $userid, bool $isAdmin): bool
    {
        return $this->access->canUseTemplate($zone_templ_id, $userid, $isAdmin);
    }

    /**
     * @see ZoneTemplateAccessPolicy::canCurrentUserUseTemplate()
     */
    public function canCurrentUserUseTemplate(mixed $zone_templ_id): bool
    {
        return $this->access->canCurrentUserUseTemplate($zone_templ_id);
    }

    /**
     * @see ZoneTemplateAccessPolicy::isUserOwnerOfTemplate()
     */
    public function isUserOwnerOfTemplate(int $zone_templ_id, int $userid): bool
    {
        return $this->access->isUserOwnerOfTemplate($zone_templ_id, $userid);
    }

    /**
     * @see ZoneTemplateWriteService::addZoneTemplSaveAs()
     */
    public function addZoneTemplSaveAs(string $template_name, string $description, int $userid, array $records, array $options, string $domain = ''): ZoneTemplateWriteResult
    {
        return $this->writes->addZoneTemplSaveAs($template_name, $description, $userid, $records, $options, $domain);
    }

    /**
     * Get list of all zones using template
     *
     * @return array array of zones ids
     */
    public function getListZoneUseTempl(int $zone_templ_id, int $userid): array
    {
        return $this->repository->listLinkedZoneIds($zone_templ_id, $this->access->linkedZoneOwnerFilter($userid));
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
        return $this->repository->listLinkedZoneIdPairs($zone_templ_id, $this->access->linkedZoneOwnerFilter($userid));
    }

    /**
     * Get detailed information about zones using a specific template
     *
     * @return array array of zone details
     */
    public function getZonesUsingTemplate(int $zone_templ_id, int $userid): array
    {
        return $this->repository->listLinkedZones($zone_templ_id, $this->access->linkedZoneOwnerFilter($userid));
    }

    /**
     * @see ZoneTemplateWriteService::editZoneTempl()
     */
    public function editZoneTempl(array $details, int $zone_templ_id, int $user_id): ZoneTemplateWriteResult
    {
        return $this->writes->editZoneTempl($details, $zone_templ_id, $user_id);
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
