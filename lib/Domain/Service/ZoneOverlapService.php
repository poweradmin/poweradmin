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
        [$table, $nameCol, $idCol] = $this->zoneSource();

        // Escape LIKE wildcards with '=' (not backslash, which MySQL mangles in
        // string literals) so an underscore matches literally; escape '=' first.
        $escaped = str_replace(['=', '%', '_'], ['==', '=%', '=_'], $name);
        $pattern = '%.' . $escaped;

        $stmt = $this->db->prepare(
            "SELECT $idCol AS id, $nameCol AS name FROM $table WHERE LOWER($nameCol) LIKE :pattern ESCAPE '=' ORDER BY $nameCol"
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
     * Table and columns holding existing zone names for the active backend.
     *
     * SQL backend: the authoritative source is the domains table. API backend:
     * domains is not maintained, so zone names are read from the Poweradmin-native
     * zones.zone_name - no PowerDNS API call needed. Poweradmin writes zone_name
     * synchronously on create, so every zone that carries an owner is present here;
     * only zones created out-of-band in PowerDNS are absent, and those have no
     * owner for this owner-based guard to compare against.
     *
     * @return array{0:string,1:string,2:string} [table, nameColumn, idColumn]
     */
    private function zoneSource(): array
    {
        if ($this->config->get('dns', 'backend') === 'api') {
            return ['zones', 'zone_name', 'domain_id'];
        }

        return [$this->tableNameService->getTable(PdnsTable::DOMAINS), 'name', 'id'];
    }

    /**
     * @param list<string> $names
     * @return array<string,int> Existing zone name => domain id, for names that exist.
     */
    private function findExistingZonesByName(array $names): array
    {
        [$table, $nameCol, $idCol] = $this->zoneSource();
        $placeholders = implode(',', array_fill(0, count($names), '?'));

        // LOWER(name) so the match is case-insensitive on every backend; the
        // ancestor names are already lowercased.
        $stmt = $this->db->prepare("SELECT $idCol AS id, $nameCol AS name FROM $table WHERE LOWER($nameCol) IN ($placeholders)");
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
