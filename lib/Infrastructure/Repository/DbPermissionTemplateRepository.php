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
 */

namespace Poweradmin\Infrastructure\Repository;

use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\DbCompat;
use Poweradmin\Infrastructure\Service\MessageService;

class DbPermissionTemplateRepository
{
    private object $db;
    private ConfigurationManager $config;

    public function __construct($db, ConfigurationManager $config)
    {
        $this->db = $db;
        $this->config = $config;
    }

    /**
     * Add a Permission Template
     *
     * @param array $details Permission template details [templ_name,templ_descr,perm_id]
     *
     * @return boolean true on success, false otherwise
     */
    public function addPermissionTemplate(array $details): bool
    {
        $stmt = $this->db->prepare("INSERT INTO perm_templ (name, descr) VALUES (:name, :descr)");
        $stmt->execute([
            ':name' => $details['templ_name'],
            ':descr' => $details['templ_descr']
        ]);

        $perm_templ_id = $this->db->lastInsertId();

        if (isset($details['perm_id'])) {
            $stmt = $this->db->prepare("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (:templ_id, :perm_id)");
            foreach ($details['perm_id'] as $perm_id) {
                $stmt->execute([
                    ':templ_id' => $perm_templ_id,
                    ':perm_id' => $perm_id
                ]);
            }
        }

        return true;
    }

    /**
     * Get List of Permissions
     *
     * Get a list of permissions that are available. If first argument is "0", it
     * should return all available permissions. If the first argument is > "0", it
     * should return the permissions assigned to that particular template only. If
     * second argument is true, only the permission names are returned.
     *
     * @param int $templ_id Template ID (optional) [default=0]
     * @param boolean $return_name_only Return name only or all details (optional) [default=false]
     *
     * @return array array of permissions [id,name,descr] or permission names [name]
     */
    public function getPermissionsByTemplateId(int $templ_id = 0, bool $return_name_only = false): array
    {
        if ($templ_id > 0) {
            $query = "SELECT perm_items.id AS id,
			perm_items.name AS name,
			perm_items.descr AS descr
			FROM perm_items, perm_templ_items
			WHERE perm_templ_items.templ_id = :templ_id
			AND perm_templ_items.perm_id = perm_items.id
			ORDER BY name";
            $stmt = $this->db->prepare($query);
            $stmt->execute([':templ_id' => $templ_id]);
            $response = $stmt;
        } else {
            $query = "SELECT perm_items.id AS id,
			perm_items.name AS name,
			perm_items.descr AS descr
			FROM perm_items
			ORDER BY name";
            $response = $this->db->query($query);
        }

        $permission_list = array();
        while ($permission = $response->fetch()) {
            if (!$return_name_only) {
                $permission_list [] = array(
                    "id" => $permission ['id'],
                    "name" => $permission ['name'],
                    "descr" => $permission ['descr']
                );
            } else {
                $permission_list [] = $permission ['name'];
            }
        }
        return $permission_list;
    }

    /**
     * Update permission template details
     *
     * @param array $details Permission Template Details
     *
     * @return boolean true on success, false otherwise
     */
    public function updatePermissionTemplateDetails(array $details): bool
    {
        // Fix permission template name and description first.

        $stmt = $this->db->prepare("UPDATE perm_templ SET name = :name, descr = :descr WHERE id = :id");
        $stmt->execute([
            ':name' => $details['templ_name'],
            ':descr' => $details['templ_descr'],
            ':id' => $details['templ_id']
        ]);

        // Now, update list of permissions assigned to this template. We could do
        // this The Correct Way [tm] by comparing the list of permissions that are
        // currently assigned with a list of permissions that should be assigned and
        // apply the difference between these two lists to the database. That sounds
        // like too much work. Just delete all the permissions currently assigned to
        // the template, then assign all the permissions the template should have.

        $stmt = $this->db->prepare("DELETE FROM perm_templ_items WHERE templ_id = :templ_id");
        $stmt->execute([':templ_id' => $details['templ_id']]);

        if (isset($details['perm_id'])) {
            $stmt = $this->db->prepare("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (:templ_id, :perm_id)");
            foreach ($details['perm_id'] as $perm_id) {
                $stmt->execute([
                    ':templ_id' => $details['templ_id'],
                    ':perm_id' => $perm_id
                ]);
            }
        }

        return true;
    }

    /**
     * Get name and description of template from Template ID
     *
     * @param int $templ_id Template ID
     *
     * @return array|false Template details or false if not found
     */
    public function getPermissionTemplateDetails(int $templ_id): array|false
    {
        $stmt = $this->db->prepare("SELECT * FROM perm_templ WHERE perm_templ.id = :id");
        $stmt->execute([':id' => $templ_id]);
        return $stmt->fetch();
    }

    /**
     * Get a list of all available permission templates
     *
     * @return array array of templates [id, name, descr]
     */
    public function listPermissionTemplates(): array
    {
        $query = "SELECT * FROM perm_templ ORDER BY name";
        $response = $this->db->query($query);

        $template_list = array();
        while ($template = $response->fetch()) {
            $template_list [] = array(
                "id" => $template ['id'],
                "name" => $template ['name'],
                "descr" => $template ['descr']
            );
        }
        return $template_list;
    }

    /**
     * Delete Permission Template ID
     *
     * @param int $id Permission template ID
     *
     * @return boolean true on success, false otherwise
     */
    public function deletePermissionTemplate(int $id): bool
    {
        $stmt = $this->db->prepare("SELECT id FROM users WHERE perm_templ = :id");
        $stmt->execute([':id' => $id]);
        $response = $stmt->fetchColumn();

        if ($response) {
            $messageService = new MessageService();
            $messageService->addSystemError(_('This template is assigned to at least one user.'));

            return false;
        } else {
            $stmt = $this->db->prepare("DELETE FROM perm_templ_items WHERE templ_id = :id");
            $stmt->execute([':id' => $id]);

            $stmt = $this->db->prepare("DELETE FROM perm_templ WHERE id = :id");
            $stmt->execute([':id' => $id]);
            return true;
        }
    }
}
