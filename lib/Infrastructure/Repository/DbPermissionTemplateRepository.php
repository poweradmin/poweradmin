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

use Poweradmin\Application\Presenter\ErrorPresenter;
use Poweradmin\Domain\Error\ErrorMessage;

class DbPermissionTemplateRepository
{
    private object $db;

    public function __construct($db)
    {
        $this->db = $db;
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
        $query = "INSERT INTO perm_templ (name, descr)
			VALUES (" . $this->db->quote($details['templ_name'], 'text') . ", " . $this->db->quote($details['templ_descr'], 'text') . ")";

        $this->db->query($query);

        $perm_templ_id = $this->db->lastInsertId();

        if (isset($details['perm_id'])) {
            foreach ($details['perm_id'] as $perm_id) {
                $query = "INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (" . $this->db->quote($perm_templ_id, 'integer') . "," . $this->db->quote($perm_id, 'integer') . ")";
                $this->db->query($query);
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
        $limit = '';
        if ($templ_id > 0) {
            $limit = ", perm_templ_items
			WHERE perm_templ_items.templ_id = " . $this->db->quote($templ_id, 'integer') . "
			AND perm_templ_items.perm_id = perm_items.id";
        }

        $query = "SELECT perm_items.id AS id,
			perm_items.name AS name,
			perm_items.descr AS descr
			FROM perm_items" . $limit . "
			ORDER BY name";
        $response = $this->db->query($query);

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

        $query = "UPDATE perm_templ
			SET name = " . $this->db->quote($details['templ_name'], 'text') . ",
			descr = " . $this->db->quote($details['templ_descr'], 'text') . "
			WHERE id = " . $this->db->quote($details['templ_id'], 'integer');
        $this->db->query($query);

        // Now, update list of permissions assigned to this template. We could do
        // this The Correct Way [tm] by comparing the list of permissions that are
        // currently assigned with a list of permissions that should be assigned and
        // apply the difference between these two lists to the database. That sounds
        // like too much work. Just delete all the permissions currently assigned to
        // the template, then assign all the permissions the template should have.

        $query = "DELETE FROM perm_templ_items WHERE templ_id = " . $details['templ_id'];
        $this->db->query($query);

        if (isset($details['perm_id'])) {
            foreach ($details['perm_id'] as $perm_id) {
                $query = "INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (" . $this->db->quote($details['templ_id'], 'integer') . "," . $this->db->quote($perm_id, 'integer') . ")";
                $this->db->query($query);
            }
        }

        return true;
    }

    /**
     * Get name and description of template from Template ID
     *
     * @param int $templ_id Template ID
     *
     * @return array Template details
     */
    public function getPermissionTemplateDetails(int $templ_id): array
    {
        $query = "SELECT *
			FROM perm_templ
			WHERE perm_templ.id = " . $this->db->quote($templ_id, 'integer');

        $response = $this->db->query($query);
        return $response->fetch();
    }

    /**
     * Get a list of all available permission templates
     *
     * @return array array of templates [id, name, descr]
     */
    public  function listPermissionTemplates(): array
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
        $query = "SELECT id FROM users WHERE perm_templ = " . $id;
        $response = $this->db->queryOne($query);

        if ($response) {
            $error = new ErrorMessage(_('This template is assigned to at least one user.'));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            return false;
        } else {
            $query = "DELETE FROM perm_templ_items WHERE templ_id = " . $id;
            $this->db->query($query);

            $query = "DELETE FROM perm_templ WHERE id = " . $id;
            $this->db->query($query);
            return true;
        }
    }
}