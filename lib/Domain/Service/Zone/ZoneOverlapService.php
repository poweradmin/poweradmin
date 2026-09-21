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
 *
 */

namespace Poweradmin\Domain\Service\Zone;

use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;

/**
 * Blocks creating a zone that overlaps an existing zone owned by another user.
 * The most-specific zone wins in PowerDNS, so an overlapping zone shadows the
 * other owner's data. Covers forward and reverse zones.
 */
class ZoneOverlapService
{
    private DomainRepositoryInterface $zones;
    private ConfigurationInterface $config;
    private PermissionService $permissionService;

    public function __construct(
        DomainRepositoryInterface $zones,
        ConfigurationInterface $config,
        PermissionService $permissionService
    ) {
        $this->zones = $zones;
        $this->config = $config;
        $this->permissionService = $permissionService;
    }

    /**
     * Return the existing zone (owned by another user) that the new zone would
     * overlap as parent or child, or null when creation is allowed. Gated on
     * dns.parent_zone_ownership_check; ueberusers are exempt. Ownership is
     * evaluated for the acting user - the potential overrider.
     */
    public function findConflictingZone(string $zoneName, int $userId): ?string
    {
        if (!$this->config->get('dns', 'parent_zone_ownership_check', true)) {
            return null;
        }

        if ($this->permissionService->isAdmin($userId)) {
            return null;
        }

        return $this->findConflictingAncestor($zoneName, $userId)
            ?? $this->findConflictingDescendant($zoneName, $userId);
    }

    /**
     * Closest ancestor zone owned by another user. Owning it is legitimate
     * sub-delegation; anyone else means the new zone would override it.
     */
    private function findConflictingAncestor(string $zoneName, int $userId): ?string
    {
        $ancestors = $this->ancestorNames($zoneName);
        if ($ancestors === []) {
            return null;
        }

        // Key by the normalized name so a case-insensitive DB collation returning
        // a mixed-case row still matches the lowercased ancestor lookup.
        $existing = [];
        foreach ($this->zones->findZoneIdsByNames($ancestors) as $name => $id) {
            $existing[$this->normalizeName((string)$name)] = $id;
        }

        // Closest-first, so the first existing ancestor is the one that shadows.
        foreach ($ancestors as $name) {
            if (isset($existing[$name])) {
                return $this->permissionService->userOwnsZone($userId, $existing[$name]) ? null : $name;
            }
        }

        return null;
    }

    /**
     * First descendant zone under the new zone owned by another user. The user's
     * own descendant zones are legitimate.
     */
    private function findConflictingDescendant(string $zoneName, int $userId): ?string
    {
        foreach ($this->zones->findZonesUnder($this->normalizeName($zoneName)) as $zone) {
            if (!$this->permissionService->userOwnsZone($userId, $zone['id'])) {
                return $zone['name'];
            }
        }

        return null;
    }

    /**
     * Ancestor names from the closest parent up to the top-level label.
     *
     * @return list<string>
     */
    private function ancestorNames(string $zoneName): array
    {
        $labels = explode('.', $this->normalizeName($zoneName));
        $ancestors = [];

        for ($i = 1, $count = count($labels); $i < $count; $i++) {
            $ancestors[] = implode('.', array_slice($labels, $i));
        }

        return $ancestors;
    }

    private function normalizeName(string $name): string
    {
        return strtolower(rtrim($name, '.'));
    }
}
