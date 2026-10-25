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
use Poweradmin\Domain\Service\Dns\DefaultSoaBuilder;
use Poweradmin\Infrastructure\Repository\AccountOwnerLookup;

/**
 * The backend-independent half of a consistency checker: zone ownership lives in
 * the Poweradmin-native zones table under every backend, and the fix actions the
 * consistency page posts map onto the same repairs whichever backend runs them.
 */
abstract class AbstractConsistencyChecks implements ConsistencyCheckerInterface
{
    /**
     * @param AccountOwnerLookup|null $accountOwners Prefers the user a zone's PowerDNS account names (dns.adopt_zone_owner_from_account)
     */
    public function __construct(
        private readonly ZoneOwnerRepair $ownerRepair,
        protected readonly DefaultSoaBuilder $defaultSoa,
        private readonly ?AccountOwnerLookup $accountOwners = null
    ) {
    }

    public function fixZoneWithoutOwner(int $zoneId, int $currentUserId): bool
    {
        $accountOwner = $this->accountOwners?->userIdFor($this->zoneAccount($zoneId) ?? '');
        if ($accountOwner !== null) {
            // A user at the zone limit gets nothing; the zone goes to the acting admin instead
            $assigned = $this->accountOwners->assignWithinLimit($accountOwner, fn(): bool => $this->ownerRepair->assign($zoneId, $accountOwner), $zoneId);
            if ($assigned !== null) {
                return $assigned;
            }
        }

        return $this->ownerRepair->assign($zoneId, $currentUserId);
    }

    /**
     * The zone's PowerDNS account, or null where this backend does not read it.
     */
    protected function zoneAccount(int $zoneId): ?string
    {
        return null;
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
            'zones_without_names' => [$this->fixZoneName($id), _('Zone name stored'), _('Failed to store the zone name')],
            'api_key_scopes_on_missing_zones' => [$this->fixApiKeyScopesOnMissingZone($id), _('API key restriction no longer names the deleted zone'), _('Failed to repair the API key restriction')],
            'records_with_uppercase_names' => [$this->fixRecordNameCase($id), _('Record name lowercased'), _('Failed to lowercase the record name')],
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
            'zones_without_names' => ConsistencyReport::tally(
                $this->fixAllZonesWithoutName(),
                'fixed',
                _('No zones without a stored name to fix'),
                _('Stored the name of %d zones'),
                _('Stored %d zone names; %d failed')
            ),
            'api_key_scopes_on_missing_zones' => ConsistencyReport::tally(
                $this->fixAllApiKeyScopesOnMissingZones(),
                'fixed',
                _('No API key restrictions on deleted zones to repair'),
                _('Repaired the API key restrictions on %d deleted zones'),
                _('Repaired %d deleted zones; %d failed')
            ),
            'records_with_uppercase_names' => ConsistencyReport::tally(
                $this->fixAllRecordNamesCase(),
                'fixed',
                _('No record names to lowercase'),
                _('Lowercased the name of %d records'),
                _('Lowercased %d record names; %d failed')
            ),
            default => ['status' => 'error', 'message' => _('Invalid check type')],
        };
    }

    /**
     * One finding per zone name used by more than one zone in different letter case.
     *
     * @param list<array{id: int|string, name: string}> $zones
     * @return list<array{name: string, zones: string, ids: string}>
     */
    protected function groupByLowerName(array $zones): array
    {
        $groups = [];
        foreach ($zones as $zone) {
            $name = rtrim((string)$zone['name'], '.');
            $groups[strtolower($name)][] = ['id' => (int)$zone['id'], 'name' => $name];
        }

        $findings = [];
        foreach ($groups as $key => $members) {
            if (count($members) > 1) {
                $findings[] = [
                    'name' => $key,
                    'zones' => implode(', ', array_column($members, 'name')),
                    'ids' => implode(', ', array_column($members, 'id')),
                ];
            }
        }
        return $findings;
    }
}
