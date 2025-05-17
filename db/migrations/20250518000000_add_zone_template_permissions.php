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

use Phinx\Migration\AbstractMigration;

final class AddZoneTemplatePermissions extends AbstractMigration
{
    public function change(): void
    {
        // Insert new permissions for zone templates
        $permissions = [
            [
                'id' => 63,
                'name' => 'zone_templ_add',
                'descr' => 'User is allowed to add new zone templates.'
            ],
            [
                'id' => 64,
                'name' => 'zone_templ_edit',
                'descr' => 'User is allowed to edit existing zone templates.'
            ]
        ];

        // Add the new permissions
        $this->table('perm_items')->insert($permissions)->save();

        // Add the new permissions to the Administrator template
        $adminTemplateId = 1; // Default Administrator template ID

        // Get the highest ID in perm_templ_items
        $rows = $this->fetchAll('SELECT MAX(id) as max_id FROM perm_templ_items');
        $nextItemId = $rows[0]['max_id'] + 1;

        $templateItems = [];
        foreach ($permissions as $index => $permission) {
            $templateItems[] = [
                'id' => $nextItemId + $index,
                'templ_id' => $adminTemplateId,
                'perm_id' => $permission['id']
            ];
        }

        // Insert the new permissions into the Administrator template
        $this->table('perm_templ_items')->insert($templateItems)->save();
    }
}
