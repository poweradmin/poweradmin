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

use Poweradmin\Application\Service\ZoneCreateRequest;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Service\ZoneOwnershipModeService;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Handles the add-secondary-zone form: validates the name and primary address, then creates the SLAVE zone.
 */
class AddZoneSlaveController extends BaseController
{

    public function run(): void
    {
        $this->checkPermission(Permission::PERM_ZONE_SLAVE_ADD, _("You do not have the permission to add a slave zone."));

        // Set the current page for navigation highlighting
        $this->setCurrentPage('add_zone_slave');
        $this->setPageTitle(_('Add Secondary Zone'));

        $blocker = $this->zoneOwnerOptionsBlocker();
        if ($blocker !== null) {
            $this->showError($blocker);
            return;
        }

        if ($this->isPost()) {
            $this->addZone();
        } else {
            $this->showForm();
        }
    }


    private function addZone(): void
    {
        $constraints = [
            'domain' => [
                new Assert\NotBlank()
            ],
            'slave_master' => [
                new Assert\NotBlank()
            ]
        ];

        $this->setValidationConstraints($constraints);

        $postData = $this->httpRequest->getPostParams();
        if (!$this->doValidateRequest($postData)) {
            $this->showFirstValidationError($postData);
        }

        $created = $this->createZoneCreateService()->create(new ZoneCreateRequest(
            name: (string)$this->httpRequest->getPostParam('domain', ''),
            type: 'SLAVE',
            ownerInput: $this->httpRequest->getPostParam('owner'),
            groupsInput: $this->httpRequest->getPostParam('groups'),
            callerUserId: (int)$this->getCurrentUserId(),
            slaveMaster: (string)$this->httpRequest->getPostParam('slave_master', ''),
            reverseNetwork: $this->httpRequest->getPostParam('type') === 'reverse'
        ));
        if (!$created->success) {
            $this->setMessage('add_zone_slave', 'error', (string)$created->message);
            $this->showForm();
            return;
        }

        $messageKey = $created->isReverseZone() ? 'list_reverse_zones' : 'list_forward_zones';
        $this->setMessage($messageKey, 'success', _('Zone has been added successfully.'));
        $this->redirect($messageKey === 'list_reverse_zones' ? '/zones/reverse' : '/zones/forward');
    }

    private function showForm(): void
    {
        // Keep the submitted values if there was an error
        $domainInput = $this->httpRequest->getPostParam('domain');
        $domain_value = $domainInput ?? '';
        $slaveMasterInput = $this->httpRequest->getPostParam('slave_master');
        $slave_master_value = $slaveMasterInput ?? '';
        $users = $this->services()->userRepository()->getUsersWithZoneCounts();

        $assignableOwners = $this->assignableOwners($users);
        $owner_value = $this->preservedOwnerChoice($assignableOwners, $this->httpRequest->getPostParam('owner'));

        $is_post_request = !empty($this->httpRequest->getPostParams());

        // Fetch groups for the dropdown - admins see all, others see only their own
        $userGroupRepo = $this->services()->userGroupRepository();
        $isAdmin = $this->hasPermission(Permission::PERM_USER_IS_UEBERUSER);
        $allGroups = $isAdmin ? $userGroupRepo->findAll() : $userGroupRepo->findByUserId((int)$this->getCurrentUserId());

        // Fetch member counts for all groups in a single query
        $groupIds = array_map(fn($g) => $g->getId(), $allGroups);
        $memberCounts = $userGroupRepo->getMemberCountsByGroupIds($groupIds);

        // Handle selected groups on error re-render
        $groupsInput = $this->httpRequest->getPostParam('groups');
        $selected_groups = is_array($groupsInput) ? array_map('intval', $groupsInput) : [];

        $ownershipMode = new ZoneOwnershipModeService($this->config);

        // Preserve reverse-zone context so the form returns to the reverse list
        $is_reverse_zone = $this->httpRequest->getQueryParam('type') === 'reverse'
            || $this->httpRequest->getPostParam('type') === 'reverse';

        $this->render('add_zone_slave.html', [
            'is_reverse_zone' => $is_reverse_zone,
            'users' => $users,
            'selectable_owners' => $assignableOwners,
            'session_user_id' => $this->getCurrentUserId(),
            'domain_value' => $domain_value,
            'slave_master_value' => $slave_master_value,
            'owner_value' => $owner_value,
            'is_post' => $is_post_request,
            'all_groups' => $allGroups,
            'group_member_counts' => $memberCounts,
            'selected_groups' => $selected_groups,
            'user_owner_allowed' => $ownershipMode->isUserOwnerAllowed(),
            'group_owner_allowed' => $ownershipMode->isGroupOwnerAllowed(),
            // Don't pass raw POST data to the template for security
        ]);
    }
}
