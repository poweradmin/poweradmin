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

namespace Poweradmin\Application\Controller\Zone;

use Poweradmin\Application\Controller\BaseController;
use Poweradmin\Application\Service\ZoneCreateRequest;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\ZoneType;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipModeService;
use Poweradmin\Domain\Utility\DomainHelper;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Handles the bulk registration form: creates a primary zone from a template for each submitted domain name.
 */
class BulkRegistrationController extends BaseController
{
    /** Bulk creation only makes sense for locally served zones. */
    private const AVAILABLE_ZONE_TYPES = [ZoneType::MASTER, ZoneType::NATIVE];

    public function run(): void
    {
        $this->checkPermission(Permission::PERM_ZONE_MASTER_ADD, _("You do not have the permission to add a master zone."));

        // Set the current page for navigation highlighting
        $this->setCurrentPage('bulk_registration');
        $this->setPageTitle(_('Bulk Registration'));

        $blocker = $this->services()->zoneOwnershipFormResolver()->blocker((int)$this->getCurrentUserId());
        if ($blocker !== null) {
            $this->showError($blocker);
            return;
        }

        if ($this->isPost()) {
            $this->doBulkRegistration();
        } else {
            $this->showBulkRegistrationForm();
        }
    }


    private function doBulkRegistration(): void
    {
        $constraints = [
            'dom_type' => [
                new Assert\NotBlank()
            ],
            'zone_template' => [
                new Assert\NotBlank()
            ],
            'domains' => [
                new Assert\NotBlank()
            ]
        ];

        $this->setValidationConstraints($constraints);

        $postParams = $this->httpRequest->getPostParams();
        if (!$this->doValidateRequest($postParams)) {
            $this->showFirstValidationError($postParams);
        }

        $domains = DomainHelper::getDomains($this->httpRequest->getPostParam('domains'));
        $dom_type = $this->httpRequest->getPostParam('dom_type');
        $zone_template = $this->httpRequest->getPostParam('zone_template');

        // The dropdown only populates the form; the submit path must whitelist too.
        if (!in_array($dom_type, self::AVAILABLE_ZONE_TYPES, true)) {
            $this->setMessage('bulk_registration', 'error', _('Invalid or unexpected input given.'));
            $this->showBulkRegistrationForm();
            return;
        }

        $templateAccess = $this->services()->zoneTemplateAccessPolicy();
        if (!$templateAccess->canCurrentUserUseTemplate($zone_template)) {
            $this->setMessage('bulk_registration', 'error', _('Invalid or unexpected input given.'));
            $this->showBulkRegistrationForm();
            return;
        }

        $batch = $this->createZoneCreateService()->createMany(
            new ZoneCreateRequest(
                name: '',
                type: $dom_type,
                ownerInput: $this->httpRequest->getPostParam('owner'),
                groupsInput: $this->httpRequest->getPostParam('groups'),
                callerUserId: (int)$this->getCurrentUserId(),
                template: (string)$zone_template
            ),
            $domains
        );
        if ($batch->message !== null) {
            $this->setMessage('bulk_registration', 'error', $batch->message);
            $this->showBulkRegistrationForm();
            return;
        }

        $failed_domains = $batch->failed();
        if (!$failed_domains) {
            $this->setMessage('list_forward_zones', 'success', _('Zones have been added successfully.'));
            $this->redirect('/zones/forward');
        } else {
            $this->setMessage('bulk_registration', 'warning', _('Some zone(s) could not be added.'));
            $this->showBulkRegistrationForm($failed_domains, $batch->added());
        }
    }

    private function showBulkRegistrationForm(array $failed_domains = [], array $added_domains = []): void
    {
        $zone_templates = $this->services()->zoneTemplateService();
        $ownershipMode = new ZoneOwnershipModeService($this->config);

        $userGroupRepo = $this->services()->userGroupRepository();
        $isAdmin = $this->hasPermission(Permission::PERM_USER_IS_UEBERUSER);
        $allGroups = $isAdmin ? $userGroupRepo->findAll() : $userGroupRepo->findByUserId((int)$this->getCurrentUserId());

        $users = $this->services()->userRepository()->getUsersWithZoneCounts();
        $assignableOwners = $this->assignableOwners($users);
        $groupsInput = $this->httpRequest->getPostParam('groups');
        $this->render('bulk_registration.html', [
            'userid' => $this->getCurrentUserId(),
            'owner_value' => $this->preservedOwnerChoice($assignableOwners, $this->httpRequest->getPostParam('owner')),
            'perm_edit_others' => $this->hasPermission(Permission::PERM_USER_EDIT_OTHERS),
            'iface_zone_type_default' => $this->config->get('dns', 'zone_type_default', 'MASTER'),
            'available_zone_types' => self::AVAILABLE_ZONE_TYPES,
            'users' => $users,
            'selectable_owners' => $assignableOwners,
            'zone_templates' => $zone_templates->getListZoneTempl((int)$this->getCurrentUserId()),
            'failed_domains' => $failed_domains,
            'added_domains' => $added_domains,
            'user_owner_allowed' => $ownershipMode->isUserOwnerAllowed(),
            'group_owner_allowed' => $ownershipMode->isGroupOwnerAllowed(),
            'all_groups' => $allGroups,
            'selected_groups' => is_array($groupsInput) ? array_map('intval', $groupsInput) : [],
        ]);
    }
}
