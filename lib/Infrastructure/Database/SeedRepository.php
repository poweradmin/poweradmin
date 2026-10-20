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

namespace Poweradmin\Infrastructure\Database;

use PDO;
use Poweradmin\Domain\Model\PermissionCatalogue;

/**
 * Writes the PermissionCatalogue seed data into freshly created Poweradmin tables.
 */
class SeedRepository
{
    public function __construct(private readonly PDO $db, private readonly string $dbType)
    {
    }

    public function seedPermissions(): void
    {
        $stmt = $this->db->prepare('INSERT INTO perm_items VALUES (?, ?, ?)');
        foreach (PermissionCatalogue::permissions() as $row) {
            $stmt->execute($row);
        }

        // Sync PostgreSQL sequence after inserting with explicit IDs (fixes #942)
        if ($this->dbType === 'pgsql') {
            $this->db->exec("SELECT setval('perm_items_id_seq', (SELECT MAX(id) FROM perm_items))");
        }
    }

    /**
     * Creates the default user and group permission templates with their
     * permissions, and the default user groups backed by the group templates.
     *
     * @return array<string, int|false> template name => perm_templ id
     */
    public function seedDefaultTemplates(): array
    {
        $permissionIds = $this->permissionIdsByName();

        $templateIds = [];
        $stmt = $this->db->prepare("INSERT INTO perm_templ (name, descr) VALUES (:name, :descr)");
        foreach (PermissionCatalogue::templatesOfType('user') as $template) {
            $stmt->execute([':name' => $template['name'], ':descr' => $template['descr']]);
            $templateIds[$template['name']] = $this->lastTemplateId();
        }
        $this->assignTemplatePermissions('user', $templateIds, $permissionIds);

        $stmt = $this->db->prepare("INSERT INTO perm_templ (name, descr, template_type) VALUES (:name, :descr, :template_type)");
        foreach (PermissionCatalogue::templatesOfType('group') as $template) {
            $stmt->execute([':name' => $template['name'], ':descr' => $template['descr'], ':template_type' => $template['template_type']]);
            $templateIds[$template['name']] = $this->lastTemplateId();
        }
        $this->assignTemplatePermissions('group', $templateIds, $permissionIds);

        $stmt = $this->db->prepare("INSERT INTO user_groups (name, description, perm_templ, created_by) VALUES (:name, :description, :perm_templ, NULL)");
        foreach (PermissionCatalogue::groups() as $group) {
            if ($templateIds[$group['template']] !== false) {
                $stmt->execute([
                    ':name' => $group['name'],
                    ':description' => $group['description'],
                    ':perm_templ' => $templateIds[$group['template']]
                ]);
            }
        }

        return $templateIds;
    }

    public function createAdminUser(#[\SensitiveParameter] string $passwordHash, int $permTemplId): void
    {
        $user_query = $this->db->prepare(
            "INSERT INTO users (username, password, fullname, email, description, perm_templ, active, use_ldap, auth_method) " .
            "VALUES ('admin', ?, 'Administrator', 'admin@example.net', 'Administrator with full rights.', ?, 1, 0, 'sql')"
        );
        $user_query->execute(array($passwordHash, $permTemplId));
    }

    /**
     * @return array<string, int>
     */
    private function permissionIdsByName(): array
    {
        $permissionNames = [];
        foreach (PermissionCatalogue::templates() as $template) {
            $permissionNames = array_merge($permissionNames, $template['permissions']);
        }
        $permissionNames = array_values(array_unique($permissionNames));

        $permissionIds = [];
        $stmt = $this->db->prepare("SELECT id, name FROM perm_items WHERE name IN (" . implode(',', array_fill(0, count($permissionNames), '?')) . ")");
        $stmt->execute($permissionNames);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $permissionIds[$row['name']] = (int)$row['id'];
        }

        return $permissionIds;
    }

    /**
     * @param array<string, int|false> $templateIds
     * @param array<string, int> $permissionIds
     */
    private function assignTemplatePermissions(string $templateType, array $templateIds, array $permissionIds): void
    {
        $stmt = $this->db->prepare("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (:templ_id, :perm_id)");
        foreach (PermissionCatalogue::templatesOfType($templateType) as $template) {
            foreach ($template['permissions'] as $permName) {
                // lastInsertId() returns false on a failed template insert, and
                // isset() would happily pass that through as a template id
                if (isset($permissionIds[$permName]) && $templateIds[$template['name']] !== false) {
                    $stmt->execute([
                        ':templ_id' => $templateIds[$template['name']],
                        ':perm_id' => $permissionIds[$permName]
                    ]);
                }
            }
        }
    }

    private function lastTemplateId(): int|false
    {
        $id = $this->db->lastInsertId('perm_templ_id_seq');

        return $id === false ? false : (int)$id;
    }
}
