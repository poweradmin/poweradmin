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

namespace Poweradmin\Application\Service;

use Poweradmin\Domain\Model\ZoneType;
use Poweradmin\Domain\Service\Auth\ApiPermissionService;
use Poweradmin\Domain\Utility\DnsIdnService;
use Poweradmin\Domain\Service\Zone\ZoneManagementService;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipResolution;
use Poweradmin\Domain\Utility\DnsHelper;

/**
 * The zone creation flow shared by the add-zone forms, bulk registration and
 * the secondary zone import: the name is normalised the same way, the owner
 * controls are resolved by the same rules, the zone is created and audited
 * the same way, and every refusal is worded for the form.
 */
class ZoneCreateService
{
    public function __construct(
        private readonly ZoneOwnershipFormResolver $ownership,
        private readonly ZoneManagementService $zones,
        private readonly ApiPermissionService $permissions,
        private readonly AuditService $audit
    ) {
    }

    public function create(ZoneCreateRequest $request): ZoneCreateOutcome
    {
        $zoneName = $this->zoneName($request, $request->name);
        if ($zoneName === null) {
            return ZoneCreateOutcome::refused(ZoneCreateFormMessages::invalidReverseNetwork(), $request->name);
        }

        $ownership = $this->ownership->resolveInputs($request->ownerInput, $request->groupsInput, $request->callerUserId);
        if ($ownership->hasError()) {
            return ZoneCreateOutcome::refused(ZoneOwnershipFormResolver::errorMessage($ownership), $zoneName);
        }

        return $this->createOwned($request, $zoneName, $ownership);
    }

    /**
     * Creates one zone per name with the request's kind, template and owners.
     * The owners are resolved once; a refusal there stops the batch before any
     * name is tried, while a refused name only fails that name.
     *
     * @param list<string> $names
     */
    public function createMany(ZoneCreateRequest $request, array $names): ZoneBatchCreateOutcome
    {
        $ownership = $this->ownership->resolveInputs($request->ownerInput, $request->groupsInput, $request->callerUserId);
        if ($ownership->hasError()) {
            return ZoneBatchCreateOutcome::refused(ZoneOwnershipFormResolver::errorMessage($ownership));
        }

        $outcomes = [];
        foreach ($names as $name) {
            $zoneName = $this->zoneName($request, $name);
            $outcomes[] = $zoneName === null
                ? ZoneCreateOutcome::refused(ZoneCreateFormMessages::invalidReverseNetwork(), $name)
                : $this->createOwned($request, $zoneName, $ownership);
        }

        return ZoneBatchCreateOutcome::tried($outcomes);
    }

    /**
     * The stored name for what was typed: on the reverse form a network
     * (192.168.1.0/24, 2001:db8::/48) becomes the matching arpa zone rather
     * than a forward zone with that literal name.
     */
    private function zoneName(ZoneCreateRequest $request, string $name): ?string
    {
        $name = trim($name);
        if ($request->reverseNetwork) {
            $name = DnsHelper::resolveReverseZoneName($name);
            if ($name === null) {
                return null;
            }
        }

        return DnsIdnService::toPunycode($name);
    }

    private function createOwned(ZoneCreateRequest $request, string $zoneName, ZoneOwnershipResolution $ownership): ZoneCreateOutcome
    {
        if (
            $request->signRequested
            && !$this->permissions->canManageDnssecForNewZone($request->callerUserId, $ownership->owner, $ownership->groupIds)
        ) {
            return ZoneCreateOutcome::refused(ZoneCreateFormMessages::dnssecForbidden(), $zoneName);
        }

        $created = $this->zones->createZone(
            $zoneName,
            $request->type,
            $ownership->owner,
            $request->slaveMaster,
            $request->template,
            $request->signRequested,
            $ownership->groupIds,
            $request->callerUserId,
            $request->soaEditApi
        );
        if (!$created['success']) {
            return ZoneCreateOutcome::refused(ZoneCreateFormMessages::errorMessage($created), $zoneName);
        }
        $zoneId = (int)$created['zone_id'];

        if ($request->importedFromPrimary) {
            $this->audit->logSecondaryZoneImport($zoneId, $zoneName, $request->slaveMaster);
        } else {
            // A zone that replicates from a primary takes no template, so none is logged for it
            $this->audit->logZoneAdd(
                $zoneId,
                $zoneName,
                $request->type,
                ZoneType::replicatesFromPrimary($request->type) ? null : $request->template,
                $request->slaveMaster !== '' ? $request->slaveMaster : null
            );
        }

        return ZoneCreateOutcome::created($zoneId, $zoneName, $created['dnssec'] ?? null);
    }
}
