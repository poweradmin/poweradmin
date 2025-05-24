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
use Poweradmin\Domain\Model\ZoneTemplate;
use Poweradmin\Domain\Service\DnsRecord;

class UnlinkZonesTemplController extends BaseController
{

    public function run(): void
    {
        $perm_godlike = UserManager::verifyPermission($this->db, 'user_is_ueberuser');
        $perm_zone_edit = UserManager::verifyPermission($this->db, 'zone_content_edit_own') || UserManager::verifyPermission($this->db, 'zone_content_edit_others');
        $perm_zone_meta_edit = UserManager::verifyPermission($this->db, 'zone_meta_edit_own') || UserManager::verifyPermission($this->db, 'zone_meta_edit_others');

        $this->checkCondition(!($perm_godlike || $perm_zone_edit || $perm_zone_meta_edit), _('You do not have permission to unlink zones from templates.'));

        if ($this->isPost()) {
            $this->validateCsrfToken();

            $zone_ids = $_POST['zone_ids'] ?? [];
            $template_id = filter_input(INPUT_POST, 'template_id', FILTER_VALIDATE_INT);

            if (empty($zone_ids)) {
                $this->showError(_('No zones selected.'));
                return;
            }

            if (!$template_id) {
                $this->showError(_('Invalid template ID.'));
                return;
            }

            if (isset($_POST['confirm'])) {
                $this->unlinkZones($zone_ids, $template_id);
            } else {
                $this->showConfirmation($zone_ids, $template_id);
            }
        } else {
            $this->showError(_('Invalid request method.'));
        }
    }

    private function unlinkZones(array $zone_ids, int $template_id): void
    {
        $successful = 0;
        $failed = 0;

        foreach ($zone_ids as $zone_id) {
            $zone_id = filter_var($zone_id, FILTER_VALIDATE_INT);
            if (!$zone_id) {
                $failed++;
                continue;
            }

            // Check if user has permission to edit this zone
            $perm_godlike = UserManager::verifyPermission($this->db, 'user_is_ueberuser');
            if (!$perm_godlike && !UserManager::verifyUserIsOwnerZoneId($this->db, $zone_id)) {
                $failed++;
                continue;
            }

            $zoneTemplate = new ZoneTemplate($this->db, $this->getConfig());
            if ($zoneTemplate->unlinkZoneFromTemplate($zone_id)) {
                $successful++;
            } else {
                $failed++;
            }
        }

        if ($successful > 0) {
            $message = sprintf(
                ngettext(
                    '%d zone has been unlinked from template successfully.',
                    '%d zones have been unlinked from template successfully.',
                    $successful
                ),
                $successful
            );

            if ($failed > 0) {
                $message .= ' ' . sprintf(
                    ngettext(
                        '%d zone could not be unlinked.',
                        '%d zones could not be unlinked.',
                        $failed
                    ),
                    $failed
                );
            }

            $this->setMessage('list_template_zones', 'success', $message);
        } else {
            $this->setMessage('list_template_zones', 'error', _('Failed to unlink zones from template.'));
        }

        $this->redirect('index.php', ['page' => 'list_template_zones', 'id' => $template_id]);
    }

    private function showConfirmation(array $zone_ids, int $template_id): void
    {
        // Validate and filter zone IDs
        $valid_zone_ids = array_filter(array_map('intval', $zone_ids));

        if (empty($valid_zone_ids)) {
            $this->showError(_('No valid zones selected.'));
            return;
        }

        // Get template information
        $template_details = ZoneTemplate::getZoneTemplDetails($this->db, $template_id);

        if (!$template_details) {
            $this->showError(_('Template not found.'));
            return;
        }

        // Get zone details using ZoneTemplate service
        $zoneTemplate = new ZoneTemplate($this->db, $this->getConfig());
        $zones = $zoneTemplate->getZonesByIds($valid_zone_ids);

        $this->render('confirm_unlink_zones_templ.html', [
            'zones' => $zones,
            'zone_ids' => $valid_zone_ids,
            'template_id' => $template_id,
            'template_name' => $template_details['name'],
            'zone_count' => count($zones)
        ]);
    }
}
