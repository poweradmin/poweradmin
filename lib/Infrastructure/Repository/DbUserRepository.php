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

namespace Poweradmin\Infrastructure\Repository;

use Poweradmin\Infrastructure\Database\BackendModeMarker;
use Poweradmin\Infrastructure\Database\SharedZoneIds;
use Poweradmin\Infrastructure\Database\SqlZoneNames;
use Poweradmin\Infrastructure\Database\PdoTransaction;
use Poweradmin\Domain\Port\TransactionInterface;
use PDO;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\User;
use Poweradmin\Domain\Repository\UserRepositoryInterface;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Database\CanonicalZoneSql;
use Poweradmin\Domain\Database\DbCompat;
use Poweradmin\Domain\Database\PdnsTable;
use Poweradmin\Domain\Database\TableNameService;
use Poweradmin\Domain\Enum\PermissionTemplateType;
use Poweradmin\Domain\Enum\AuthMethod;
use Poweradmin\Domain\Service\User\CreateUserCommand;
use Poweradmin\Domain\Service\User\UpdateUserCommand;
use Poweradmin\Infrastructure\Utility\SortHelper;

/**
 * SQL persistence for accounts in the users table, with permission lookups through perm_templ and perm_items.
 */
class DbUserRepository implements UserRepositoryInterface
{
    private object $db;
    private ?TransactionInterface $transactionPort = null;
    private ConfigurationInterface $config;
    private bool $isApiBackend;

    /**
     * @param object $db Database connection
     * @param ConfigurationInterface $config Application configuration
     * @param bool $isApiBackend Whether zone ids are allocated from the zones table, so a
     *                           row without a domain_id is keyed by zones.id instead
     */
    public function __construct($db, ConfigurationInterface $config, bool $isApiBackend)
    {
        $this->db = $db;
        $this->config = $config;
        $this->isApiBackend = $isApiBackend;
    }

    public function findByUsername(string $username): ?User
    {
        // Accent-exact match, so a look-alike username cannot resolve to another account.
        $match = DbCompat::accentSensitiveEquals($this->db->getAttribute(PDO::ATTR_DRIVER_NAME), 'username');
        $stmt = $this->db->prepare("SELECT id, password, use_ldap FROM users WHERE $match");
        $stmt->execute([$username]);

        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$data) {
            return null;
        }

        return new User($data['id'], $data['password'], (bool)$data['use_ldap']);
    }

    public function findActiveUsersByEmail(string $email): array
    {
        $query = "SELECT id, username, fullname, email, auth_method, active
                  FROM users
                  WHERE email = :email
                  AND active = 1
                  ORDER BY username ASC";

        $stmt = $this->db->prepare($query);
        $stmt->execute([':email' => $email]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findNotificationRecipient(int $userId): ?array
    {
        $stmt = $this->db->prepare('
            SELECT id, username, fullname, email
            FROM users
            WHERE id = :user_id
            LIMIT 1
        ');

        $stmt->execute(['user_id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        return $user ?: null;
    }

    public function listNotifiableUsers(): array
    {
        $stmt = $this->db->prepare('
            SELECT id, username, fullname, email
            FROM users
            WHERE active = 1 AND email IS NOT NULL AND email <> :empty
            ORDER BY id
        ');
        $stmt->execute(['empty' => '']);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listActiveUsers(): array
    {
        $stmt = $this->db->prepare('
            SELECT id, username, fullname, email
            FROM users
            WHERE active = 1
            ORDER BY id
        ');
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findSqlLoginUser(string $username): ?array
    {
        $stmt = $this->db->prepare("SELECT id, fullname, password, active, email FROM users WHERE username=:username AND use_ldap=0");
        $stmt->bindValue(':username', $username, PDO::PARAM_STR);
        $stmt->execute();
        $rowObj = $stmt->fetch(PDO::FETCH_ASSOC);

        return $rowObj ?: null;
    }

    public function findActiveLdapUser(string $username): ?array
    {
        // Accent-exact match, so a look-alike username cannot resolve to another account.
        $match = DbCompat::accentSensitiveEquals($this->db->getAttribute(PDO::ATTR_DRIVER_NAME), 'username', ':username');
        $stmt = $this->db->prepare("SELECT id, fullname, email FROM users WHERE $match AND active = 1 AND use_ldap = 1");
        $stmt->execute([
            'username' => $username
        ]);
        $rowObj = $stmt->fetch(PDO::FETCH_ASSOC);

        return $rowObj ?: null;
    }

    public function hasActiveLdapUser(string $username): bool
    {
        $match = DbCompat::accentSensitiveEquals($this->db->getAttribute(PDO::ATTR_DRIVER_NAME), 'username', ':username');
        $stmt = $this->db->prepare("SELECT id, fullname FROM users WHERE $match AND active = 1 AND use_ldap = 1");
        $stmt->execute(['username' => $username]);
        $rowObj = $stmt->fetch(PDO::FETCH_ASSOC);

        return (bool)$rowObj;
    }

    public function isActiveUser(int $userId): bool
    {
        $stmt = $this->db->prepare("SELECT id FROM users WHERE id = :id AND active = 1");
        $stmt->bindValue(':id', $userId, PDO::PARAM_INT);
        $stmt->execute();

        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function findBasicAuthUser(string $username): ?array
    {
        $query = $this->db->prepare("SELECT id, password, use_ldap FROM users WHERE username = :username AND active = 1");
        $query->execute(['username' => $username]);
        $user = $query->fetch(PDO::FETCH_ASSOC);

        return $user ?: null;
    }

    public function findAuthMethodRow(string $username): ?array
    {
        $stmt = $this->db->prepare("SELECT auth_method FROM users WHERE username = :username");
        $stmt->execute([
            'username' => $username
        ]);
        $rowObj = $stmt->fetch(PDO::FETCH_ASSOC);

        return $rowObj === false ? null : $rowObj;
    }

    public function updatePassword(int $userId, string $hashedPassword): bool
    {
        $stmt = $this->db->prepare('UPDATE users SET password = ? WHERE id = ?');
        return $stmt->execute([$hashedPassword, $userId]);
    }

    /**
     * Get user by ID
     *
     * @param int $userId The user ID to fetch
     * @return array|null User data or null if not found
     */
    public function getUserById(int $userId): ?array
    {
        // The join is restricted to user-type templates so a row pointing at a group
        // template reports a NULL name rather than that group template's name.
        $stmt = $this->db->prepare('SELECT users.id, users.username, users.fullname, users.email,
            users.description, users.active, users.perm_templ, users.use_ldap, users.auth_method,
            perm_templ.name AS perm_templ_name
            FROM users
            LEFT JOIN perm_templ ON users.perm_templ = perm_templ.id
                 AND perm_templ.template_type = \'user\'
            WHERE users.id = ?');
        $stmt->execute([$userId]);

        $userData = $stmt->fetch(PDO::FETCH_ASSOC);
        return $userData ?: null;
    }

    public function getFullNameById(int $userId): ?string
    {
        $stmt = $this->db->prepare("SELECT fullname FROM users WHERE id = :id");
        $stmt->execute([':id' => $userId]);
        $fullname = $stmt->fetchColumn();
        return $fullname !== false ? (string)$fullname : null;
    }

    public function getFullNameByUsername(string $username): ?string
    {
        $match = DbCompat::accentSensitiveEquals($this->db->getAttribute(PDO::ATTR_DRIVER_NAME), 'username', ':username');
        $stmt = $this->db->prepare("SELECT fullname FROM users WHERE $match");
        $stmt->execute([':username' => $username]);
        $fullname = $stmt->fetchColumn();
        return $fullname !== false ? (string)$fullname : null;
    }

    public function getZoneOwnerFullNames(int $domainId): string
    {
        if ($this->isApiBackend && SharedZoneIds::isShared($this->db, $domainId)) {
            $owner = SharedZoneIds::resolvedOwner($this->db, $domainId);
            if ($owner === null) {
                return '';
            }
            $stmt = $this->db->prepare("SELECT fullname FROM users WHERE id = :id");
            $stmt->bindValue(':id', $owner, PDO::PARAM_INT);
            $stmt->execute();

            return (string)($stmt->fetchColumn() ?: '');
        }

        // PARAM_INT: the canonical expression has no column affinity, so SQLite would compare as text
        $canonicalId = CanonicalZoneSql::canonicalIdColumn('zones', $this->isApiBackend);
        $stmt = $this->db->prepare("SELECT users.fullname FROM users, zones WHERE $canonicalId = :id AND zones.owner = users.id ORDER BY fullname");
        $stmt->bindValue(':id', $domainId, PDO::PARAM_INT);
        $stmt->execute();

        $names = [];
        while ($row = $stmt->fetch()) {
            $names[] = $row['fullname'];
        }
        return implode(', ', $names);
    }

    /**
     * Check if a user owns a zone directly or via group membership
     *
     * @param int $userId User ID to check
     * @param int $domainId Domain/zone ID
     * @return bool True if the user owns the zone
     */
    public function userOwnsZone(int $userId, int $domainId): bool
    {
        if ($this->isApiBackend && SharedZoneIds::isShared($this->db, $domainId)) {
            return SharedZoneIds::ownsResolvedZone($this->db, $userId, $domainId);
        }

        $canonicalId = CanonicalZoneSql::canonicalIdColumn('zones', $this->isApiBackend);
        $stmt = $this->db->prepare("SELECT zones.id FROM zones WHERE zones.owner = :userid AND $canonicalId = :zoneid");
        $stmt->bindValue(':userid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':zoneid', $domainId, PDO::PARAM_INT);
        $stmt->execute();
        if ($stmt->fetchColumn()) {
            return true;
        }

        $stmt = $this->db->prepare("
            SELECT zg.id
            FROM zones_groups zg
            INNER JOIN user_group_members ugm ON zg.group_id = ugm.group_id
            WHERE ugm.user_id = :userid AND zg.domain_id = :zoneid
        ");
        $stmt->execute([
            ':userid' => $userId,
            ':zoneid' => $domainId
        ]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * @return array<int, int>
     */
    public function getUserGroupIds(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT group_id FROM user_group_members WHERE user_id = :user_id'
        );
        $stmt->execute([':user_id' => $userId]);

        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return array_map('intval', $rows ?: []);
    }

    /**
     * @return array<int, int>
     */
    public function getUserOwnedZoneIds(int $userId): array
    {
        $canonicalId = CanonicalZoneSql::canonicalIdColumn('', $this->isApiBackend);
        $stmt = $this->db->prepare("
            SELECT $canonicalId FROM zones WHERE owner = :user_id
            UNION
            SELECT zg.domain_id FROM zones_groups zg
            INNER JOIN user_group_members ugm ON zg.group_id = ugm.group_id
            WHERE ugm.user_id = :user_id2
        ");
        $stmt->execute([':user_id' => $userId, ':user_id2' => $userId]);
        $zoneIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        return $this->isApiBackend ? SharedZoneIds::filterOwned($this->db, $userId, $zoneIds) : $zoneIds;
    }

    /**
     * @return array<int, int>
     */
    public function getDirectlyOwnedZoneIds(int $userId): array
    {
        $canonicalId = CanonicalZoneSql::canonicalIdColumn('', $this->isApiBackend);
        $stmt = $this->db->prepare("SELECT DISTINCT $canonicalId FROM zones WHERE owner = :user_id");
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $zoneIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        // A shared id belongs only to the direct owner of the row it resolves to
        return $this->isApiBackend ? SharedZoneIds::filterOwned($this->db, $userId, $zoneIds) : $zoneIds;
    }

    public function countDirectlyOwnedZones(array $userIds): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        $owned = array_fill_keys($userIds, []);
        if ($userIds === []) {
            return [];
        }

        $canonicalId = CanonicalZoneSql::canonicalIdColumn('', $this->isApiBackend);
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $this->db->prepare("SELECT DISTINCT owner, $canonicalId AS zone_id FROM zones WHERE owner IN ($placeholders)");
        foreach ($userIds as $i => $userId) {
            $stmt->bindValue($i + 1, $userId, PDO::PARAM_INT);
        }
        $stmt->execute();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $owned[(int)$row['owner']][] = (int)$row['zone_id'];
        }

        $counts = [];
        foreach ($owned as $userId => $zoneIds) {
            $counts[$userId] = count($this->isApiBackend ? SharedZoneIds::filterOwned($this->db, $userId, $zoneIds) : $zoneIds);
        }

        return $counts;
    }

    public function findZoneLimits(array $userIds): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        $limits = array_fill_keys($userIds, null);
        if ($userIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $this->db->prepare("SELECT id, max_zones FROM users WHERE id IN ($placeholders)");
        foreach ($userIds as $i => $userId) {
            $stmt->bindValue($i + 1, $userId, PDO::PARAM_INT);
        }
        $stmt->execute();
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $limits[(int)$row['id']] = $row['max_zones'] === null ? null : (int)$row['max_zones'];
        }

        return $limits;
    }

    public function lockForZoneLimit(int $userId): void
    {
        $lock = DbCompat::rowLock((string)$this->db->getAttribute(PDO::ATTR_DRIVER_NAME));
        $stmt = $this->db->prepare("SELECT id FROM users WHERE id = :id$lock");
        $stmt->bindValue(':id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $stmt->fetchAll();
    }

    public function findZoneLimit(int $userId): ?int
    {
        $stmt = $this->db->prepare('SELECT max_zones FROM users WHERE id = :id');
        $stmt->bindValue(':id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        $limit = $stmt->fetchColumn();

        return $limit === false || $limit === null ? null : (int)$limit;
    }

    public function setZoneLimit(int $userId, ?int $limit): bool
    {
        $stmt = $this->db->prepare('UPDATE users SET max_zones = :max_zones WHERE id = :id');
        $stmt->bindValue(':max_zones', $limit, $limit === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':id', $userId, PDO::PARAM_INT);

        return $stmt->execute();
    }

    /**
     * Get all permissions for a specific user
     *
     * @param int $userId User ID to get permissions for
     * @return array Array of permission names
     */
    public function getUserPermissions(int $userId): array
    {
        // Direct template and group permissions via UNION (dedupes overlaps, positional
        // params avoid PDO binding issues); a missing user naturally yields no rows

        $query = "
            SELECT perm_items.name AS permission
            FROM perm_templ_items
            INNER JOIN perm_items ON perm_items.id = perm_templ_items.perm_id
            INNER JOIN perm_templ ON perm_templ.id = perm_templ_items.templ_id
            INNER JOIN users ON perm_templ.id = users.perm_templ
            WHERE users.id = ?
                AND perm_items.name IS NOT NULL

            UNION

            SELECT pi.name AS permission
            FROM user_group_members ugm
            INNER JOIN user_groups ug ON ugm.group_id = ug.id
            INNER JOIN perm_templ pt ON ug.perm_templ = pt.id
            INNER JOIN perm_templ_items pti ON pt.id = pti.templ_id
            INNER JOIN perm_items pi ON pti.perm_id = pi.id
            WHERE ugm.user_id = ?
                AND pi.name IS NOT NULL

            ORDER BY permission
        ";

        $stmt = $this->db->prepare($query);
        $stmt->execute([$userId, $userId]);

        $userPermissions = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($row['permission'] !== null) {
                $userPermissions[] = $row['permission'];
            }
        }

        return $userPermissions;
    }

    /**
     * Check if a user has admin permissions
     *
     * @param int $userId User ID to check
     * @return bool True if the user is an admin
     */
    public function hasAdminPermission(int $userId): bool
    {
        // Check both direct user permissions and group permissions
        // Using positional parameters for UNION queries to avoid PDO binding issues
        $query = "
            SELECT perm_items.name AS permission
            FROM perm_templ_items
            INNER JOIN perm_items ON perm_items.id = perm_templ_items.perm_id
            INNER JOIN perm_templ ON perm_templ.id = perm_templ_items.templ_id
            INNER JOIN users ON perm_templ.id = users.perm_templ
            WHERE users.id = ?
                AND perm_items.name = '" . Permission::PERM_USER_IS_UEBERUSER . "'
                AND perm_items.name IS NOT NULL

            UNION

            SELECT pi.name AS permission
            FROM user_group_members ugm
            INNER JOIN user_groups ug ON ugm.group_id = ug.id
            INNER JOIN perm_templ pt ON ug.perm_templ = pt.id
            INNER JOIN perm_templ_items pti ON pt.id = pti.templ_id
            INNER JOIN perm_items pi ON pti.perm_id = pi.id
            WHERE ugm.user_id = ?
                AND pi.name = '" . Permission::PERM_USER_IS_UEBERUSER . "'
                AND pi.name IS NOT NULL
        ";

        $stmt = $this->db->prepare($query);
        $stmt->execute([$userId, $userId]);

        return $stmt->fetch() !== false;
    }

    /**
     * Get a paginated list of users with zone counts
     *
     * @param int $offset Starting offset for pagination
     * @param int $limit Maximum number of users to return
     * @param string|null $search Case-insensitive substring filter on username, full name, email or description
     * @param list<array{field: string, desc: bool}> $sort Sort keys (id, username, fullname or email); empty sorts by id
     * @return array Array of user data with zone counts
     */
    public function getUsersList(int $offset, int $limit, ?string $search = null, array $sort = []): array
    {
        [$searchCondition, $searchBindings] = $this->buildUserSearchFilter($search);

        $query = "SELECT users.id AS id,
            users.username AS username,
            users.fullname AS fullname,
            users.email AS email,
            users.description AS description,
            users.active AS active,
            users.perm_templ AS perm_templ,
            users.max_zones AS max_zones,
            perm_templ.name AS perm_templ_name,
            COUNT(zones.owner) AS zone_count
            FROM users
            LEFT JOIN zones ON users.id = zones.owner
            LEFT JOIN perm_templ ON users.perm_templ = perm_templ.id
                 AND perm_templ.template_type = 'user'
            WHERE 1=1" . $searchCondition . "
            GROUP BY
            users.id,
            users.username,
            users.fullname,
            users.email,
            users.description,
            users.perm_templ,
            users.max_zones,
            perm_templ.name,
            users.active
            ORDER BY " . self::userListOrder($sort) . "
            LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        foreach ($searchBindings as $placeholder => $value) {
            $stmt->bindValue($placeholder, $value, PDO::PARAM_STR);
        }
        $stmt->execute();

        $users = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $users[] = [
                'id' => $row['id'],
                'username' => $row['username'],
                'fullname' => $row['fullname'],
                'email' => $row['email'],
                'description' => $row['description'],
                'active' => $row['active'],
                'perm_templ' => $row['perm_templ'],
                'perm_templ_name' => $row['perm_templ_name'],
                'max_zones' => $row['max_zones'],
                'zone_count' => $row['zone_count']
            ];
        }

        return $users;
    }

    /**
     * Get all users with the number of zones each one owns
     *
     * @return array Array of user rows [id, username, fullname, email, description, active, numdomains]
     */
    public function getUsersWithZoneCounts(): array
    {
        $query = "SELECT users.id AS id,
            users.username AS username,
            users.fullname AS fullname,
            users.email AS email,
            users.description AS description,
            users.active AS active,
            COUNT(zones.owner) AS zone_count
            FROM users
            LEFT JOIN zones ON users.id = zones.owner
            GROUP BY users.id, users.username, users.fullname, users.email, users.description, users.active
            ORDER BY users.fullname";

        $stmt = $this->db->query($query);

        $users = [];
        while ($row = $stmt->fetch()) {
            $users[] = [
                'id' => $row['id'],
                'username' => $row['username'],
                'fullname' => $row['fullname'],
                'email' => $row['email'],
                'description' => $row['description'],
                'active' => $row['active'],
                'numdomains' => $row['zone_count'],
            ];
        }
        return $users;
    }

    /**
     * ORDER BY for the user list. Text columns compare lowercased so the order is
     * the same on every database; the id tiebreaker keeps pages stable.
     *
     * @param list<array{field: string, desc: bool}> $sort
     */
    private static function userListOrder(array $sort): string
    {
        return SortHelper::orderBy($sort, [
            'id' => 'users.id',
            'username' => 'LOWER(users.username)',
            'fullname' => 'LOWER(users.fullname)',
            'email' => 'LOWER(users.email)',
        ], 'users.id', 'users.id');
    }

    /**
     * Search predicate over the user columns shown in the list. LOWER() is applied on both
     * sides because PostgreSQL LIKE is case-sensitive while MySQL's default collation is not.
     * Each column gets its own placeholder: repeating one name breaks native prepares.
     *
     * @return array{0: string, 1: array<string, string>} WHERE fragment and its bindings
     */
    private function buildUserSearchFilter(?string $search): array
    {
        $search = $search === null ? '' : trim($search);
        if ($search === '') {
            return ['', []];
        }

        $pattern = '%' . DbCompat::escapeLike($search) . '%';
        $clauses = [];
        $bindings = [];

        foreach (['users.username', 'users.fullname', 'users.email', 'users.description'] as $index => $column) {
            $placeholder = ':search' . $index;
            $clauses[] = "LOWER($column) LIKE LOWER($placeholder) ESCAPE '!'";
            $bindings[$placeholder] = $pattern;
        }

        return [' AND (' . implode(' OR ', $clauses) . ')', $bindings];
    }

    /**
     * Get detailed user list with template, group, and MFA info
     *
     * @param bool $ldapUse Whether the LDAP column should be included
     * @param int|null $restrictToUserId Return only this user (for users without view-others permission)
     * @param int|null $specific User ID to fetch (overrides the restriction)
     * @param int|null $limit Number of records to return (optional)
     * @param int|null $offset Starting offset (optional)
     * @param string|null $search Filter on username, full name, email or description
     * @return array Array of user details
     */
    public function getUserDetailList(bool $ldapUse, ?int $restrictToUserId, ?int $specific = null, ?int $limit = null, ?int $offset = null, ?string $search = null): array
    {
        [$searchCondition, $searchBindings] = $this->buildUserSearchFilter($search);

        if ($specific) {
            $sql_add = "AND users.id = :specific";
        } elseif ($restrictToUserId === null) {
            $sql_add = "";
        } else {
            $sql_add = "AND users.id = :userid";
        }

        $query = "SELECT users.id AS uid,
        username,
        fullname,
        email,
        description AS descr,
        active,
        auth_method,";

        if ($ldapUse) {
            $query .= "use_ldap,";
        }

        // Restrict the join to user-type templates so users pointed at a deleted
        // template or a group template are surfaced with a NULL tpl_id and routed
        // through the broken-row fallback below.
        $query .= "perm_templ.id AS tpl_id,
        perm_templ.name AS tpl_name,
        perm_templ.descr AS tpl_descr
        FROM users
        LEFT JOIN perm_templ ON users.perm_templ = perm_templ.id
             AND perm_templ.template_type = 'user'
        WHERE 1=1 " . $sql_add . $searchCondition . "
        ORDER BY username";

        if ($limit !== null) {
            $query .= " LIMIT :limit OFFSET :offset";
        }

        $stmt = $this->db->prepare($query);

        if ($specific) {
            $stmt->bindValue(':specific', $specific, PDO::PARAM_INT);
        } elseif ($restrictToUserId !== null) {
            $stmt->bindValue(':userid', $restrictToUserId, PDO::PARAM_INT);
        }

        foreach ($searchBindings as $placeholder => $value) {
            $stmt->bindValue($placeholder, $value, PDO::PARAM_STR);
        }

        if ($limit !== null) {
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset ?? 0, PDO::PARAM_INT);
        }

        $stmt->execute();
        $response = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $userGroups = $this->getUserGroupsMap();
        $mfaStatus = $this->getUserMfaStatusMap();

        // Resolve the fallback template only when at least one row has a dangling perm_templ.
        // Using the minimum-permission user template keeps the dropdown's <option selected>
        // pointing somewhere safe: a stale save lands on minimum permissions rather than
        // letting the browser auto-pick the first <option> (which is typically Administrator).
        $fallbackTplId = null;
        $fallbackTplName = null;
        foreach ($response as $user) {
            if ($user['tpl_id'] === null) {
                $templateRepository = new DbPermissionTemplateRepository($this->db, $this->config);
                $fallbackTplId = $templateRepository->getMinimalPermissionTemplateId('user');
                if ($fallbackTplId !== null) {
                    $fallbackTplName = $this->getPermissionTemplateName($fallbackTplId);
                }
                break;
            }
        }

        $userList = [];
        foreach ($response as $user) {
            $tplId = $user['tpl_id'];
            $tplName = $user['tpl_name'];
            $tplDescr = $user['tpl_descr'];
            if ($tplId === null && $fallbackTplId !== null) {
                $tplId = $fallbackTplId;
                $tplName = $fallbackTplName;
                $tplDescr = null;
            }

            $userList[] = [
                "uid" => $user['uid'],
                "username" => $user['username'],
                "fullname" => $user['fullname'],
                "email" => $user['email'],
                "descr" => $user['descr'],
                "active" => $user['active'],
                "use_ldap" => $user['use_ldap'] ?? 0,
                "auth_type" => $user['auth_method'] ?? 'sql',
                "tpl_id" => $tplId,
                "tpl_name" => $tplName,
                "tpl_descr" => $tplDescr,
                "groups" => $userGroups[$user['uid']] ?? [],
                "mfa_enabled" => $mfaStatus[$user['uid']] ?? false
            ];
        }
        return $userList;
    }

    /**
     * Look up a permission template's display name by ID
     */
    private function getPermissionTemplateName(int $templId): ?string
    {
        $stmt = $this->db->prepare("SELECT name FROM perm_templ WHERE id = :id");
        $stmt->execute([':id' => $templId]);
        $name = $stmt->fetchColumn();
        return $name === false ? null : (string)$name;
    }

    /**
     * Get a map of user IDs to their group names
     *
     * @return array Map of user_id => array of group names
     */
    private function getUserGroupsMap(): array
    {
        $query = "SELECT ugm.user_id, ug.name AS group_name
                  FROM user_group_members ugm
                  INNER JOIN user_groups ug ON ugm.group_id = ug.id
                  ORDER BY ugm.user_id, ug.name";

        $stmt = $this->db->prepare($query);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $userGroups = [];
        foreach ($results as $row) {
            $userGroups[$row['user_id']][] = $row['group_name'];
        }

        return $userGroups;
    }

    /**
     * Get a map of user IDs to their MFA enabled status
     *
     * @return array Map of user_id => bool (true if MFA enabled)
     */
    private function getUserMfaStatusMap(): array
    {
        try {
            $stmt = $this->db->prepare("SELECT user_id, enabled FROM user_mfa WHERE enabled = 1");
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $mfaStatus = [];
            foreach ($results as $row) {
                $mfaStatus[$row['user_id']] = true;
            }

            return $mfaStatus;
        } catch (\PDOException $e) {
            // Table might not exist if MFA was never enabled
            return [];
        }
    }

    /**
     * Get total count of users in the system
     *
     * @param int|null $restrictToUserId Count only this user (for users without view-others permission)
     * @param string|null $search Filter on username, full name, email or description
     * @return int Total number of users
     */
    public function getTotalUserCount(?int $restrictToUserId = null, ?string $search = null): int
    {
        [$searchCondition, $searchBindings] = $this->buildUserSearchFilter($search);

        if ($restrictToUserId === null && $searchCondition === '') {
            $stmt = $this->db->query("SELECT COUNT(*) FROM users");
            return (int)$stmt->fetchColumn();
        }

        $query = "SELECT COUNT(*) FROM users WHERE 1=1";
        if ($restrictToUserId !== null) {
            $query .= " AND users.id = :id";
        }
        $query .= $searchCondition;

        $stmt = $this->db->prepare($query);
        if ($restrictToUserId !== null) {
            $stmt->bindValue(':id', $restrictToUserId, PDO::PARAM_INT);
        }
        foreach ($searchBindings as $placeholder => $value) {
            $stmt->bindValue($placeholder, $value, PDO::PARAM_STR);
        }
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    /**
     * Delete a user by ID
     *
     * @param int $userId User ID to delete
     * @return bool True if the user was deleted successfully
     */
    public function deleteUser(int $userId): bool
    {
        try {
            // Start transaction to ensure atomicity
            $this->transaction()->begin();

            // Delete related OIDC/SAML authentication links first
            $this->cleanupExternalAuthLinks($userId);

            // Private zone templates die with their owner, as they always did in the web UI.
            (new DbZoneTemplateRepository($this->db, $this->config))->deleteZoneTemplatesOwnedBy($userId);

            $stmt = $this->db->prepare("SELECT DISTINCT domain_id FROM zones WHERE owner = :userId AND domain_id IS NOT NULL");
            $stmt->execute([':userId' => $userId]);
            $ownedZoneIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

            // Memberships and ownership rows are cleared explicitly: the FK cascade
            // is not guaranteed on every schema.
            foreach (
                [
                    "DELETE FROM user_preferences WHERE user_id = :userId",
                    "DELETE FROM user_mfa WHERE user_id = :userId",
                    "DELETE FROM login_attempts WHERE user_id = :userId",
                    "DELETE FROM user_group_members WHERE user_id = :userId",
                    // The FK only nulls created_by. ApiKeyService refuses an ownerless
                    // key, so the row is dead, but it still lists as if it were usable.
                    "DELETE FROM api_keys WHERE created_by = :userId",
                    "DELETE FROM zones WHERE owner = :userId",
                    "DELETE FROM users WHERE id = :userId",
                ] as $query
            ) {
                $stmt = $this->db->prepare($query);
                $stmt->execute([':userId' => $userId]);
            }
            if (!$this->isApiBackend) {
                // The user's row may have carried the zone name, or been the zone's last row
                $domainsTable = (new TableNameService($this->config))->getTable(PdnsTable::DOMAINS);
                foreach ($ownedZoneIds as $zoneId) {
                    SqlZoneNames::ensureNamedAfterOwnerRemoval($this->db, $domainsTable, $zoneId);
                }
            }

            $this->transaction()->commit();
            return true;
        } catch (\Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Clean up external authentication links for a user
     *
     * This method removes all OIDC and SAML authentication links associated with a user.
     * It's called during user deletion to ensure referential integrity.
     *
     * @param int $userId User ID
     * @return void
     */
    private function cleanupExternalAuthLinks(int $userId): void
    {
        // Clean up OIDC links
        $stmt = $this->db->prepare("DELETE FROM oidc_user_links WHERE user_id = :userId");
        $stmt->execute([':userId' => $userId]);

        // Clean up SAML links if the table exists
        // Use database-agnostic approach: try the DELETE and catch table not found errors
        try {
            $stmt = $this->db->prepare("DELETE FROM saml_user_links WHERE user_id = :userId");
            $stmt->execute([':userId' => $userId]);
        } catch (\Exception $e) {
            // saml_user_links table doesn't exist yet - this is expected until SAML user linking is fully implemented
            // We silently ignore table-not-found errors but would still throw for other SQL errors
            $errorMessage = $e->getMessage();
            $isTableNotFound = (
                strpos($errorMessage, 'saml_user_links') !== false && (
                    strpos($errorMessage, "doesn't exist") !== false ||
                    strpos($errorMessage, 'does not exist') !== false ||
                    strpos($errorMessage, 'no such table') !== false ||
                    strpos($errorMessage, 'Unknown table') !== false
                )
            );

            if (!$isTableNotFound) {
                // Re-throw if it's not a table-not-found error
                throw $e;
            }
        }
    }

    /**
     * Get zones owned by a user
     *
     * @param int $userId User ID
     * @return array Array of zone data owned by the user
     */
    public function getUserZones(int $userId): array
    {
        $query = "SELECT z.id, z.domain_id
                  FROM zones z
                  WHERE z.owner = :userId";
        $stmt = $this->db->prepare($query);
        $stmt->execute([':userId' => $userId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Transfer zone ownership from one user to another
     *
     * @param int $fromUserId Source user ID
     * @param int $toUserId Target user ID
     * @return bool True if zones were transferred successfully
     */
    public function transferUserZones(int $fromUserId, int $toUserId): bool
    {
        $transaction = $this->transaction();
        $owns = !$transaction->inTransaction();
        if ($owns) {
            $transaction->begin();
        }

        try {
            $this->queueOwnershipWriters($fromUserId);
            $this->dropSharedOwnerships($fromUserId, $toUserId);
            $stmt = $this->db->prepare("UPDATE zones SET owner = :toUserId WHERE owner = :fromUserId");
            $moved = $stmt->execute([':toUserId' => $toUserId, ':fromUserId' => $fromUserId]);
            if (!$this->isApiBackend) {
                $this->dropReceiverDuplicates($toUserId);
            }
            if ($owns) {
                $transaction->commit();
            }

            return $moved;
        } catch (\Throwable $e) {
            if ($owns && $transaction->inTransaction()) {
                $transaction->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Takes the lock every zone owner writer queues on (the API marker row, or each of the
     * sender's domains rows in ascending order), so an owner added meanwhile cannot slip in
     * between the dedupe and the move. DELETE and UPDATE read current rows, not the snapshot.
     */
    private function queueOwnershipWriters(int $fromUserId): void
    {
        if ($this->isApiBackend) {
            BackendModeMarker::markApi($this->db);
            return;
        }

        $stmt = $this->db->prepare("SELECT DISTINCT domain_id FROM zones WHERE owner = :fromUserId AND domain_id IS NOT NULL ORDER BY domain_id");
        $stmt->execute([':fromUserId' => $fromUserId]);
        $zoneIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        $domains = (new TableNameService($this->config))->getTable(PdnsTable::DOMAINS);
        $lock = DbCompat::rowLock((string)$this->db->getAttribute(PDO::ATTR_DRIVER_NAME));
        $stmt = $this->db->prepare("SELECT id FROM $domains WHERE id = :id$lock");
        foreach ($zoneIds as $zoneId) {
            $stmt->execute([':id' => $zoneId]);
            $stmt->fetchAll();
        }
    }

    /**
     * On PostgreSQL a zone granted to the sender after its domains rows were locked can commit
     * between the dedupe and the move; this drops the receiver's resulting unnamed duplicate.
     */
    private function dropReceiverDuplicates(int $toUserId): void
    {
        $stmt = $this->db->prepare(
            "DELETE FROM zones
             WHERE owner = :toUserId AND zone_name IS NULL
               AND id IN (
                   SELECT id FROM (
                       SELECT z.id FROM zones z
                       WHERE z.owner = :dupOwner AND z.zone_name IS NULL
                         AND EXISTS (
                             SELECT 1 FROM zones k
                             WHERE k.owner = z.owner AND k.domain_id = z.domain_id AND k.id <> z.id
                               AND (k.zone_name IS NOT NULL OR k.id < z.id)
                         )
                   ) AS duplicates
               )"
        );
        $stmt->execute([':toUserId' => $toUserId, ':dupOwner' => $toUserId]);
    }

    /**
     * Clears the ownership a transfer would duplicate, so the receiver ends up with one row per zone.
     * The derived tables keep MySQL from refusing a subquery on the table being deleted from.
     */
    private function dropSharedOwnerships(int $fromUserId, int $toUserId): void
    {
        $canonicalId = CanonicalZoneSql::canonicalIdColumn('', $this->isApiBackend);
        // Extra rows (no zone_name) are the only ones API mode may delete; the canonical row carries the zone
        $senderRows = $this->isApiBackend ? ' AND zone_name IS NULL' : '';

        // The sender holds the named row: drop the receiver's unnamed row so the move below hands the name over
        $stmt = $this->db->prepare(
            "DELETE FROM zones
             WHERE owner = :toUserId AND zone_name IS NULL
               AND domain_id IN (
                   SELECT zone_id FROM (
                       SELECT $canonicalId AS zone_id FROM zones
                       WHERE owner = :fromUserId AND zone_name IS NOT NULL
                   ) AS sender_zones
               )"
        );
        $stmt->execute([':toUserId' => $toUserId, ':fromUserId' => $fromUserId]);

        $stmt = $this->db->prepare(
            "DELETE FROM zones
             WHERE owner = :fromUserId$senderRows
               AND $canonicalId IN (
                   SELECT zone_id FROM (
                       SELECT $canonicalId AS zone_id FROM zones WHERE owner = :toUserId
                   ) AS receiver_zones
               )"
        );
        $stmt->execute([':toUserId' => $toUserId, ':fromUserId' => $fromUserId]);
    }

    /**
     * Count total number of uberusers (super admins) in the system
     *
     * @return int Number of uberusers
     */
    public function countUberusers(): int
    {
        $query = "SELECT COUNT(DISTINCT users.id)
                  FROM users
                  JOIN perm_templ ON users.perm_templ = perm_templ.id
                  JOIN perm_templ_items ON perm_templ.id = perm_templ_items.templ_id
                  JOIN perm_items ON perm_templ_items.perm_id = perm_items.id
                  WHERE perm_items.name = '" . Permission::PERM_USER_IS_UEBERUSER . "'
                  AND users.active = 1";

        $stmt = $this->db->query($query);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Check if a specific user is an uberuser
     *
     * @param int $userId User ID to check
     * @return bool True if user is an uberuser
     */
    public function isLastUberuser(int $userId): bool
    {
        return $this->isUberuser($userId) && $this->countUberusers() <= 1;
    }

    public function isUberuser(int $userId): bool
    {
        $query = "SELECT COUNT(*)
                  FROM users
                  JOIN perm_templ ON users.perm_templ = perm_templ.id
                  JOIN perm_templ_items ON perm_templ.id = perm_templ_items.templ_id
                  JOIN perm_items ON perm_templ_items.perm_id = perm_items.id
                  WHERE perm_items.name = '" . Permission::PERM_USER_IS_UEBERUSER . "'
                  AND users.id = :userId
                  AND users.active = 1";

        $stmt = $this->db->prepare($query);
        $stmt->execute([':userId' => $userId]);
        return (bool)$stmt->fetchColumn();
    }

    public function getAdminUserIds(): array
    {
        $query = "
            SELECT users.id AS user_id
            FROM users
            INNER JOIN perm_templ ON perm_templ.id = users.perm_templ
            INNER JOIN perm_templ_items ON perm_templ_items.templ_id = perm_templ.id
            INNER JOIN perm_items ON perm_items.id = perm_templ_items.perm_id
            WHERE perm_items.name = '" . Permission::PERM_USER_IS_UEBERUSER . "'

            UNION

            SELECT ugm.user_id AS user_id
            FROM user_group_members ugm
            INNER JOIN user_groups ug ON ugm.group_id = ug.id
            INNER JOIN perm_templ pt ON ug.perm_templ = pt.id
            INNER JOIN perm_templ_items pti ON pt.id = pti.templ_id
            INNER JOIN perm_items pi ON pti.perm_id = pi.id
            WHERE pi.name = '" . Permission::PERM_USER_IS_UEBERUSER . "'
        ";

        $stmt = $this->db->query($query);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function templateGrantsUberuser(int $permTemplId): bool
    {
        $query = "SELECT COUNT(*)
                  FROM perm_templ_items
                  JOIN perm_items ON perm_templ_items.perm_id = perm_items.id
                  WHERE perm_templ_items.templ_id = :templId
                  AND perm_items.name = '" . Permission::PERM_USER_IS_UEBERUSER . "'";

        $stmt = $this->db->prepare($query);
        $stmt->execute([':templId' => $permTemplId]);
        return (bool)$stmt->fetchColumn();
    }

    public function findPermissionTemplateIdByName(string $name): ?int
    {
        $match = DbCompat::accentSensitiveEquals($this->db->getAttribute(PDO::ATTR_DRIVER_NAME), 'name');
        $stmt = $this->db->prepare("SELECT id FROM perm_templ WHERE $match");
        $stmt->execute([$name]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ? (int)$result['id'] : null;
    }

    public function getPermissionIdsByName(string $name): array
    {
        // Every row with this name grants it, matching templateGrantsUberuser()
        $stmt = $this->db->prepare("SELECT id FROM perm_items WHERE name = :name ORDER BY id");
        $stmt->execute([':name' => $name]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function createUser(CreateUserCommand $user): ?int
    {
        $useLdap = (int)$user->useLdap;

        // No implicit template: defaulting to id 1 handed the bundled
        // Administrator template to every caller that omitted perm_templ.
        $permTemplId = (int)$user->permissionTemplateId;
        if ($permTemplId <= 0) {
            return null;
        }

        $query = "INSERT INTO users (username, password, fullname, email, description, active, perm_templ, use_ldap, auth_method)
                  VALUES (:username, :password, :fullname, :email, :description, :active, :perm_templ, :use_ldap, :auth_method)";

        $stmt = $this->db->prepare($query);
        $result = $stmt->execute([
            ':username' => $user->username,
            ':password' => $user->password,
            ':fullname' => $user->fullname,
            ':email' => $user->email,
            ':description' => $user->description,
            ':active' => (int)$user->active,
            ':perm_templ' => $permTemplId,
            ':use_ldap' => $useLdap,
            ':auth_method' => $user->authMethod()->value
        ]);

        if ($result) {
            return (int)$this->db->lastInsertId('users_id_seq');
        }

        return null;
    }

    public function createProvisionedUser(array $userData): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO users (username, password, fullname, email, description, active, perm_templ, perm_templ_source, use_ldap, auth_method)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $success = $stmt->execute([
            $userData['username'],
            '', // No password for external auth users
            $userData['fullname'],
            $userData['email'],
            $userData['description'],
            1, // Active
            $userData['perm_templ'],
            $userData['perm_templ_source'],
            $userData['use_ldap'],
            $userData['auth_method']
        ]);

        if (!$success) {
            throw new \RuntimeException('Failed to insert user. PDO Error: ' . implode(' - ', $stmt->errorInfo()));
        }

        return (int)$this->db->lastInsertId('users_id_seq');
    }

    public function updateProvisionedUser(int $userId, array $fields): void
    {
        $allowed = ['fullname', 'email', 'auth_method', 'perm_templ', 'perm_templ_source'];
        $setFields = [];
        $values = [];
        foreach ($fields as $column => $value) {
            if (!in_array($column, $allowed, true)) {
                throw new \InvalidArgumentException("Column $column is not owned by provisioning");
            }
            $setFields[] = "$column = ?";
            $values[] = $value;
        }
        if ($setFields === []) {
            return;
        }

        $values[] = $userId;
        $stmt = $this->db->prepare("UPDATE users SET " . implode(', ', $setFields) . " WHERE id = ?");
        $stmt->execute($values);
    }

    public function getUserByUsername(string $username): ?array
    {
        $query = "SELECT users.*, perm_templ.name AS perm_templ_name
                  FROM users
                  LEFT JOIN perm_templ ON users.perm_templ = perm_templ.id
                       AND perm_templ.template_type = 'user'
                  WHERE users.username = :username LIMIT 1";
        $stmt = $this->db->prepare($query);
        $stmt->execute([':username' => $username]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ?: null;
    }

    public function getUserByEmail(string $email): ?array
    {
        $match = $this->emailEquals('users.email', ':email');
        $query = "SELECT users.*, perm_templ.name AS perm_templ_name
                  FROM users
                  LEFT JOIN perm_templ ON users.perm_templ = perm_templ.id
                       AND perm_templ.template_type = 'user'
                  WHERE $match LIMIT 1";
        $stmt = $this->db->prepare($query);
        $stmt->execute([':email' => $email]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ?: null;
    }

    public function findActiveUserIdByEmail(string $email): ?int
    {
        $match = DbCompat::accentSensitiveEquals($this->db->getAttribute(PDO::ATTR_DRIVER_NAME), 'email');
        $stmt = $this->db->prepare("SELECT id FROM users WHERE $match AND active = 1");
        $stmt->execute([$email]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ? (int)$result['id'] : null;
    }

    public function findInactiveUserIdByEmail(string $email): ?int
    {
        $match = DbCompat::accentSensitiveEquals($this->db->getAttribute(PDO::ATTR_DRIVER_NAME), 'email');
        $stmt = $this->db->prepare("SELECT id FROM users WHERE $match AND (active IS NULL OR active <> 1)");
        $stmt->execute([$email]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ? (int)$result['id'] : null;
    }

    public function getProvisioningProfile(int $userId): array
    {
        $stmt = $this->db->prepare("SELECT fullname, email, auth_method, perm_templ, perm_templ_source FROM users WHERE id = ?");
        $stmt->execute([$userId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    public function countUsersByEmail(string $email): int
    {
        $match = $this->emailEquals('email', ':email');
        $query = "SELECT COUNT(*) FROM users WHERE $match";
        $stmt = $this->db->prepare($query);
        $stmt->execute([':email' => $email]);

        return (int)$stmt->fetchColumn();
    }

    /**
     * Email addresses match regardless of case but accent-exact, so User@x and
     * user@x are one account while a look-alike address cannot resolve to another.
     */
    private function emailEquals(string $column, string $placeholder): string
    {
        return DbCompat::caseInsensitiveEquals($this->db->getAttribute(PDO::ATTR_DRIVER_NAME), $column, $placeholder);
    }

    public function updateUser(int $userId, UpdateUserCommand $changes): bool
    {
        // Build dynamic update query from the fields the request carried
        $setFields = [];
        $params = [':id' => $userId];

        // An empty password means "leave it unchanged"; callers only hash non-empty
        // values, so storing it verbatim would replace the hash with an empty string.
        $columns = [
            'username' => $changes->username,
            'password' => $changes->passwordGiven() ? $changes->password : null,
            'fullname' => $changes->fullname,
            'email' => $changes->email,
            'description' => $changes->description,
            'active' => $changes->active,
            'perm_templ' => $changes->permissionTemplateId,
        ];

        foreach ($columns as $field => $value) {
            if ($value !== null) {
                $setFields[] = "{$field} = :{$field}";
                $params[":{$field}"] = is_bool($value) ? (int)$value : $value;
            }
        }

        // A template set by hand is no longer the SSO mapping's to revoke on the next login.
        if ($changes->permissionTemplateId !== null) {
            $setFields[] = "perm_templ_source = 'admin'";
        }

        // auth_method and the legacy use_ldap flag change together; switching LDAP off
        // keeps an OIDC, SAML or web server account as it is.
        if ($changes->useLdap !== null || $changes->useRemoteUser !== null) {
            // Only switching a method off depends on the method the account has now
            $current = AuthMethod::SQL;
            if ($changes->useLdap !== true && $changes->useRemoteUser !== true) {
                $currentStmt = $this->db->prepare('SELECT auth_method FROM users WHERE id = :id');
                $currentStmt->execute([':id' => $userId]);
                $current = AuthMethod::fromDb((string)($currentStmt->fetchColumn() ?: 'sql'));
            }

            $target = $changes->authMethodAfter($current);
            if ($target !== null) {
                $setFields[] = 'auth_method = :auth_method';
                $setFields[] = 'use_ldap = :use_ldap';
                $params[':auth_method'] = $target->value;
                $params[':use_ldap'] = $target === AuthMethod::LDAP ? 1 : 0;
            }
        }

        if (empty($setFields)) {
            return true; // No fields to update
        }

        $query = "UPDATE users SET " . implode(', ', $setFields) . " WHERE id = :id";
        $stmt = $this->db->prepare($query);

        return $stmt->execute($params);
    }

    /**
     * Assign permission template to a user
     *
     * @param int $userId User ID
     * @param int $permTemplId Permission template ID
     * @return bool True if assignment was successful
     */
    public function assignPermissionTemplate(int $userId, int $permTemplId): bool
    {
        $stmt = $this->db->prepare("UPDATE users SET perm_templ = :permTemplId WHERE id = :userId");
        return $stmt->execute([':permTemplId' => $permTemplId, ':userId' => $userId]);
    }

    /**
     * Check if a permission template exists
     *
     * @param int $permTemplId Permission template ID
     * @param string|null $templateType Optional template_type filter ('user' or 'group')
     * @return bool True if the permission template exists (and matches type when set)
     */
    public function permissionTemplateExists(int $permTemplId, ?string $templateType = null): bool
    {
        if ($templateType !== null && PermissionTemplateType::isValid($templateType)) {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM perm_templ WHERE id = :permTemplId AND template_type = :templateType"
            );
            $stmt->execute([':permTemplId' => $permTemplId, ':templateType' => $templateType]);
        } else {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM perm_templ WHERE id = :permTemplId");
            $stmt->execute([':permTemplId' => $permTemplId]);
        }
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * Transactions go through the port: on SQLite one can be open without PDO
     * knowing, and opening a second one on the same handle then fails.
     */
    private function transaction(): TransactionInterface
    {
        return $this->transactionPort ??= new PdoTransaction($this->db);
    }
}
