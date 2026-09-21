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

namespace Poweradmin\Application\Controller;

use Poweradmin\BaseController;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\ZoneType;
use Poweradmin\Domain\Utility\DnsIdnService;
use Poweradmin\Domain\Service\Dns\RecordManager;
use Poweradmin\Domain\Service\Auth\ZoneAccessPolicy;
use Poweradmin\Application\Service\ChangeRequestMessages;
use Poweradmin\Domain\Service\ChangeApprovalPolicy;

/**
 * Handles the zone comment form: shows the comment and saves it when the user may edit the zone.
 */
class EditCommentController extends BaseController
{

    public function run(): void
    {
        $domainRepository = $this->services()->domainRepository();
        $iface_zone_comments = $this->config->get('interface', 'show_zone_comments', true);

        if (!$iface_zone_comments) {
            $this->showError(_("Zone comments feature is disabled in configuration."));
        }

        $permissionService = $this->services()->permissionService();
        $userId = (int)$this->getCurrentUserId();
        $perm_view = $permissionService->getViewPermissionLevel($userId);
        $perm_edit = $permissionService->getEditPermissionLevel($userId);

        $zone_id = $this->requireNumericParam('id');

        $user_is_zone_owner = $this->isZoneOwner($zone_id);
        if ($perm_view == "none" || $perm_view == "own" && $user_is_zone_owner == "0") {
            $this->showError(_("You do not have the permission to view this comment."));
        }

        if (!$domainRepository->zoneIdExists($zone_id)) {
            $this->showError(_('There is no zone with this ID.'));
            return;
        }
        // The zone comment is filed with the zone editor's request, not here
        if ($this->changeApprovalModeForZone($zone_id) === ChangeApprovalPolicy::MODE_REQUEST) {
            $this->showError(ChangeRequestMessages::requiresApproval());
            return;
        }

        $zone_type = $domainRepository->getDomainType($zone_id);

        // Check permission to edit comment - directly reuse the logic from edit_zone_comment method
        $is_admin = $this->hasPermission(Permission::PERM_USER_IS_UEBERUSER);

        // Permission check logic matches what's in RecordManager->editZoneComment.
        // Read-only zones (Secondary, Consumer) block comment edits for everyone -
        // including admins - because RecordManager rejects the write. Otherwise a
        // user can edit if they are an admin, or have edit permission and own the zone.
        $can_edit = !ZoneType::isReadOnly($zone_type)
            && ($is_admin || ZoneAccessPolicy::canEditZone($perm_edit, (bool)$user_is_zone_owner));

        // For the form, we need to know if editing is disabled
        $perm_edit_comment = !$can_edit;

        if ($this->httpRequest->getPostParam('commit') !== null) {
            if ($perm_edit_comment) {
                $this->addSystemMessage('error', _("You do not have the permission to edit this comment."));
            } else {
                $written = $this->services()->recordManager()->editZoneComment($zone_id, $this->httpRequest->getPostParam('comment'));
                if (!$written->success) {
                    $this->addSystemMessage('error', (string)$written->message);
                } else {
                    $auditService = $this->services()->auditService();
                    $auditService->logZoneCommentEdit($zone_id, $domainRepository->getDomainNameById($zone_id));

                    $this->setMessage('edit', 'success', _('The comment has been updated successfully.'));
                    $this->redirect('/zones/' . $zone_id . '/edit');
                }
            }
        }

        $this->showCommentForm($zone_id, $perm_edit_comment);
    }

    public function showCommentForm(int $zone_id, bool $perm_edit_comment): void
    {
        $domainRepository = $this->services()->domainRepository();
        $zone_name = $domainRepository->getDomainNameById($zone_id);

        $idn_zone_name = DnsIdnService::toIdnAlias($zone_name);

        $this->render('edit_comment.html', [
            'zone_id' => $zone_id,
            'comment' => RecordManager::getZoneComment($this->db, $zone_id),
            'disabled' => $perm_edit_comment,
            'zone_name' => $zone_name,
            'idn_zone_name' => $idn_zone_name,
            'zone_display_name' => DnsIdnService::toDisplay($zone_name),
        ]);
    }
}
