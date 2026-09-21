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

namespace Poweradmin\Infrastructure\Service\Consistency;

use Poweradmin\Domain\Service\Consistency\ConsistencyCheckerInterface;
use Poweradmin\Domain\Service\Consistency\ConsistencyReport;

/**
 * The backend-independent half of a consistency checker: zone ownership lives in
 * the Poweradmin-native zones table under every backend, and the fix actions the
 * consistency page posts map onto the same repairs whichever backend runs them.
 */
abstract class AbstractConsistencyChecks implements ConsistencyCheckerInterface
{
    public function __construct(private readonly ZoneOwnerRepair $ownerRepair)
    {
    }

    public function fixZoneWithoutOwner(int $zoneId, int $currentUserId): bool
    {
        return $this->ownerRepair->assign($zoneId, $currentUserId);
    }

    public function fixAllZonesWithoutOwner(int $currentUserId): array
    {
        return ConsistencyReport::repairEach(
            ConsistencyReport::findingIds($this->checkZonesHaveOwners()),
            fn(int $zoneId): bool => $this->fixZoneWithoutOwner($zoneId, $currentUserId),
            'assigned'
        );
    }

    public function fixOne(string $type, int $id, int $currentUserId): array
    {
        [$repaired, $done, $failed] = match ($type) {
            'zones_without_owners' => [$this->fixZoneWithoutOwner($id, $currentUserId), _('Zone owner assigned successfully'), _('Failed to assign zone owner')],
            'zones_without_canonical_ids' => [$this->fixZoneCanonicalId($id), _('Zone canonical ID repaired'), _('Failed to repair zone canonical ID')],
            'slave_zones_without_masters' => [$this->deleteSlaveZone($id), _('Slave zone deleted successfully'), _('Failed to delete slave zone')],
            'orphaned_records' => [$this->deleteOrphanedRecord($id), _('Orphaned record deleted successfully'), _('Failed to delete orphaned record')],
            'duplicate_soa' => [$this->fixDuplicateSOA($id), _('Duplicate SOA records fixed successfully'), _('Failed to fix duplicate SOA records')],
            'zones_without_soa' => [$this->createDefaultSOA($id), _('Default SOA record created successfully'), _('Failed to create default SOA record')],
            default => [false, '', _('Invalid check type')],
        };

        return ['status' => $repaired ? 'success' : 'error', 'message' => $repaired ? $done : $failed];
    }

    public function fixAll(string $type, int $currentUserId): array
    {
        return match ($type) {
            'zones_without_owners' => ConsistencyReport::tally(
                $this->fixAllZonesWithoutOwner($currentUserId),
                'assigned',
                _('No zones without owners to fix'),
                _('Assigned ownership of %d zones'),
                _('Assigned %d zones; %d failed')
            ),
            'zones_without_canonical_ids' => ConsistencyReport::tally(
                $this->fixAllZonesWithCanonicalIdIssue(),
                'fixed',
                _('No zones without a canonical ID to fix'),
                _('Repaired the canonical ID of %d zones'),
                _('Repaired %d zones; %d failed')
            ),
            default => ['status' => 'error', 'message' => _('Invalid check type')],
        };
    }
}
