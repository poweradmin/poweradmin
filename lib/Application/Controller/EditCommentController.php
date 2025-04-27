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

/**
 * Script that handles editing of zone comments
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Poweradmin\BaseController;
use Poweradmin\Infrastructure\Service\MessageService;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Service\DnsIdnService;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Domain\Service\Validator;

class EditCommentController extends BaseController
{

    public function run(): void
    {
        $iface_zone_comments = $this->config->get('interface', 'show_zone_comments', true);

        if (!$iface_zone_comments) {
            $this->showError(_("Zone comments feature is disabled in configuration."));
        }

        $perm_view = Permission::getViewPermission($this->db);
        $perm_edit = Permission::getEditPermission($this->db);

        if (!isset($_GET['id']) || !Validator::is_number($_GET['id'])) {
            $this->showError(_('Invalid or unexpected input given.'));
        }
        $zone_id = htmlspecialchars($_GET['id']);

        $user_is_zone_owner = UserManager::verify_user_is_owner_zoneid($this->db, $zone_id);
        if ($perm_view == "none" || $perm_view == "own" && $user_is_zone_owner == "0") {
            $this->showError(_("You do not have the permission to view this comment."));
        }

        $dnsRecord = new DnsRecord($this->db, $this->getConfig());
        $zone_type = $dnsRecord->get_domain_type($zone_id);

        // Check permission to edit comment - directly reuse the logic from edit_zone_comment method
        $is_admin = UserManager::verify_permission($this->db, 'user_is_ueberuser');

        // Permission check logic matches what's in DnsRecord->edit_zone_comment
        // Users can edit if:
        // 1. They are an admin (uberuser) OR
        // 2. It's not a slave zone AND they have edit permission AND (they have 'all' permission OR they own the zone)
        $can_edit = $is_admin ||
                   ($zone_type != "SLAVE" &&
                    $perm_edit != "none" &&
                    ($perm_edit == "all" ||
                     (($perm_edit == "own" || $perm_edit == "own_as_client") && $user_is_zone_owner)));

        // For the form, we need to know if editing is disabled
        $perm_edit_comment = !$can_edit;

        if (isset($_POST["commit"])) {
            $this->validateCsrfToken();

            if ($perm_edit_comment) {
                $messageService = new MessageService();
                $messageService->addSystemError(_("You do not have the permission to edit this comment."));
            } else {
                $dnsRecord = new DnsRecord($this->db, $this->getConfig());
                $dnsRecord->edit_zone_comment($zone_id, $_POST['comment']);
                $this->setMessage('edit', 'success', _('The comment has been updated successfully.'));
                $this->redirect('index.php', ['page' => 'edit', 'id' => $zone_id]);
            }
        }

        $this->showCommentForm($zone_id, $perm_edit_comment);
    }

    public function showCommentForm(string $zone_id, bool $perm_edit_comment): void
    {
        $dnsRecord = new DnsRecord($this->db, $this->getConfig());
        $zone_name = $dnsRecord->get_domain_name_by_id($zone_id);

        if (str_starts_with($zone_name, "xn--")) {
            $idn_zone_name = DnsIdnService::toUtf8($zone_name);
        } else {
            $idn_zone_name = "";
        }

        $this->render('edit_comment.html', [
            'zone_id' => $zone_id,
            'comment' => DnsRecord::get_zone_comment($this->db, $zone_id),
            'disabled' => $perm_edit_comment,
            'zone_name' => $zone_name,
            'idn_zone_name' => $idn_zone_name,
        ]);
    }
}
