<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2023 Poweradmin Development Team
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
}