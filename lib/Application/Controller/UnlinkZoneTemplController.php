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

namespace Poweradmin\Application\Controller;

use Poweradmin\BaseController;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\UserManager;
use Symfony\Component\Validator\Constraints as Assert;

class UnlinkZoneTemplController extends BaseController
{
    public function run(): void
    {
        $perm_godlike = UserManager::verifyPermission($this->db, 'user_is_ueberuser');
        $perm_zone_edit = UserManager::verifyPermission($this->db, 'zone_content_edit_own') || UserManager::verifyPermission($this->db, 'zone_content_edit_others');
        $perm_zone_meta_edit = UserManager::verifyPermission($this->db, 'zone_meta_edit_own') || UserManager::verifyPermission($this->db, 'zone_meta_edit_others');

        $this->checkCondition(!($perm_godlike || $perm_zone_edit || $perm_zone_meta_edit), _('You do not have permission to unlink zones from templates.'));

        $zone_id = filter_input(INPUT_GET, 'zone_id', FILTER_VALIDATE_INT);
        $confirm = filter_input(INPUT_GET, 'confirm', FILTER_VALIDATE_INT);

        if (!$zone_id) {
            $this->showError(_('Invalid zone ID.'));
            return;
        }

        // Check if user has permission to edit this zone
        if (!$perm_godlike && !UserManager::verifyUserIsOwnerZoneId($this->db, $zone_id)) {
            $this->showError(_('You do not have permission to edit this zone.'));
            return;
        }

        if ($confirm === 1) {
            $this->validateCsrfToken();
            $this->unlinkZone($zone_id);
        } else {
            $this->showConfirmation($zone_id);
        }
    }

    private function unlinkZone(int $zone_id): void
    {
        try {
            // Update the zone to set zone_templ_id to 0
            $stmt = $this->db->prepare("UPDATE zones SET zone_templ_id = 0 WHERE domain_id = ?");
            $stmt->execute([$zone_id]);

            $this->setMessage('edit', 'success', _('Zone has been unlinked from template successfully.'));
            $this->redirect('index.php', ['page' => 'edit', 'id' => $zone_id]);
        } catch (\Exception $e) {
            $this->showError(_('Failed to unlink zone from template: ') . $e->getMessage());
        }
    }

    private function showConfirmation(int $zone_id): void
    {
        // Get zone information
        $pdns_db_name = $this->config->get('database', 'pdns_name');
        $domains_table = $pdns_db_name ? $pdns_db_name . '.domains' : 'domains';

        $stmt = $this->db->prepare("SELECT d.name, d.type, z.zone_templ_id, zt.name as template_name 
                                    FROM $domains_table d 
                                    LEFT JOIN zones z ON d.id = z.domain_id 
                                    LEFT JOIN zone_templ zt ON z.zone_templ_id = zt.id 
                                    WHERE d.id = ?");
        $stmt->execute([$zone_id]);
        $zone = $stmt->fetch();

        if (!$zone) {
            $this->showError(_('Zone not found.'));
            return;
        }

        $this->render('confirm_unlink_zone_templ.html', [
            'zone_id' => $zone_id,
            'zone_name' => $zone['name'],
            'template_name' => $zone['template_name'],
            'zone_type' => $zone['type'],
            'template_id' => $zone['zone_templ_id']
        ]);
    }
}
