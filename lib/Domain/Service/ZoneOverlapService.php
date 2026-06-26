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
 *
 */

namespace Poweradmin\Domain\Service;

use PDO;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;
use Poweradmin\Infrastructure\Database\PdnsTable;
use Poweradmin\Infrastructure\Database\TableNameService;

/**
 * Blocks creating a zone that overlaps an existing zone owned by another user.
 * The most-specific zone wins in PowerDNS, so an overlapping zone shadows the
 * other owner's data. Covers forward and reverse zones.
 */
class ZoneOverlapService
{
    private PDO $db;
    private ConfigurationInterface $config;
    private ApiPermissionService $permissionService;
    private TableNameService $tableNameService;

    public function __construct(
        object $db,
        ConfigurationInterface $config,
        ?ApiPermissionService $permissionService = null
    ) {
        $this->db = $db;
        $this->config = $config;
        $this->permissionService = $permissionService ?? new ApiPermissionService($db);
        $this->tableNameService = new TableNameService($config);
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

        if ($this->permissionService->userHasPermission($userId, 'user_is_ueberuser')) {
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

        $existing = $this->findExistingZonesByName($ancestors);

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
        $name = $this->normalizeName($zoneName);
        $domainsTable = $this->tableNameService->getTable(PdnsTable::DOMAINS);

        // Escape LIKE wildcards with '=' (not backslash, which MySQL mangles in
        // string literals) so an underscore matches literally; escape '=' first.
        $escaped = str_replace(['=', '%', '_'], ['==', '=%', '=_'], $name);
        $pattern = '%.' . $escaped;

        $stmt = $this->db->prepare(
            "SELECT id, name FROM $domainsTable WHERE LOWER(name) LIKE :pattern ESCAPE '=' ORDER BY name"
        );
        $stmt->execute([':pattern' => $pattern]);

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!$this->permissionService->userOwnsZone($userId, (int)$row['id'])) {
                return $row['name'];
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

    /**
     * @param list<string> $names
     * @return array<string,int> Existing zone name => domain id, for names that exist.
     */
    private function findExistingZonesByName(array $names): array
    {
        $domainsTable = $this->tableNameService->getTable(PdnsTable::DOMAINS);
        $placeholders = implode(',', array_fill(0, count($names), '?'));

        // LOWER(name) so the match is case-insensitive on every backend; the
        // ancestor names are already lowercased.
        $stmt = $this->db->prepare("SELECT id, name FROM $domainsTable WHERE LOWER(name) IN ($placeholders)");
        $stmt->execute($names);

        // Key by the normalized name so a case-insensitive DB collation returning
        // a mixed-case row still matches the lowercased ancestor lookup.
        $result = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $result[$this->normalizeName($row['name'])] = (int)$row['id'];
        }

        return $result;
    }
}
