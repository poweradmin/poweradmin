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

/**
 * Script that handles bulk zone registration
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2026 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Poweradmin\Application\Http\Request;
use Poweradmin\Application\Service\ZoneCreateFormMessages;
use Poweradmin\Application\Service\ZoneOwnershipFormResolver;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\ZoneTemplate;
use Poweradmin\Domain\Model\ZoneType;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Domain\Service\ZoneOwnershipModeService;
use Poweradmin\Domain\Utility\DomainHelper;
use Poweradmin\Domain\Service\SessionKeys;
use Symfony\Component\Validator\Constraints as Assert;

class BulkRegistrationController extends BaseController
{
    /** Bulk creation only makes sense for locally served zones. */
    private const AVAILABLE_ZONE_TYPES = [ZoneType::MASTER, ZoneType::NATIVE];

    private UserContextService $userContextService;
    private Request $request;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $this->request = new Request();
        $this->userContextService = new UserContextService();
    }

    public function run(): void
    {
        $this->checkPermission('zone_master_add', _("You do not have the permission to add a master zone."));

        // Set the current page for navigation highlighting
        $this->setCurrentPage('bulk_registration');
        $this->setPageTitle(_('Bulk Registration'));

        $blocker = $this->zoneOwnerOptionsBlocker();
        if ($blocker !== null) {
            $this->showError($blocker);
            return;
        }

        if ($this->isPost()) {
            $this->validateCsrfToken();
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

        $postParams = $this->request->getPostParams();
        if (!$this->doValidateRequest($postParams)) {
            $this->showFirstValidationError($postParams);
        }

        $domains = DomainHelper::getDomains($this->request->getPostParam('domains'));
        $dom_type = $this->request->getPostParam('dom_type');
        $zone_template = $this->request->getPostParam('zone_template');

        // The dropdown only populates the form; the submit path must whitelist too.
        if (!in_array($dom_type, self::AVAILABLE_ZONE_TYPES, true)) {
            $this->setMessage('bulk_registration', 'error', _('Invalid or unexpected input given.'));
            $this->showBulkRegistrationForm();
            return;
        }

        $zoneTemplateModel = new ZoneTemplate($this->db, $this->getConfig());
        if (!$zoneTemplateModel->canCurrentUserUseTemplate($zone_template)) {
            $this->setMessage('bulk_registration', 'error', _('Invalid or unexpected input given.'));
            $this->showBulkRegistrationForm();
            return;
        }

        $ownership = $this->resolveZoneOwnershipFromForm($this->request);
        if ($ownership->hasError()) {
            $this->setMessage('bulk_registration', 'error', ZoneOwnershipFormResolver::errorMessage($ownership));
            $this->showBulkRegistrationForm();
            return;
        }
        $owner = $ownership->owner;
        $selected_groups = $ownership->groupIds;

        $added_domains = [];
        $failed_domains = [];
        $zoneService = $this->createZoneManagementService();
        $audit = $this->createAuditService();
        $callerId = $this->getCurrentUserId();
        foreach ($domains as $domain) {
            $created = $zoneService->createZone($domain, $dom_type, $owner, '', $zone_template, false, $selected_groups, $callerId);
            if (!$created['success']) {
                $failed_domains[] = ['name' => $domain, 'reason' => ZoneCreateFormMessages::errorMessage($created)];
                continue;
            }
            $added_domains[] = $domain;
            $audit->logZoneAdd($created['zone_id'], $domain, $dom_type, $zone_template);
        }

        if (!$failed_domains) {
            $this->setMessage('list_forward_zones', 'success', _('Zones have been added successfully.'));
            $this->redirect('/zones/forward');
        } else {
            $this->setMessage('bulk_registration', 'warning', _('Some zone(s) could not be added.'));
            $this->showBulkRegistrationForm($failed_domains, $added_domains);
        }
    }

    private function showBulkRegistrationForm(array $failed_domains = [], array $added_domains = []): void
    {
        $zone_templates = new ZoneTemplate($this->db, $this->getConfig());
        $ownershipMode = new ZoneOwnershipModeService($this->config);

        $userGroupRepo = $this->createUserGroupRepository();
        $isAdmin = $this->hasPermission('user_is_ueberuser');
        $allGroups = $isAdmin ? $userGroupRepo->findAll() : $userGroupRepo->findByUserId($_SESSION[SessionKeys::USERID]);

        $callerId = $this->userContextService->getLoggedInUserId();
        $canViewOthers = $this->hasPermission('user_view_others');
        // Preserve the user's owner choice (including explicit "no user owner")
        // when re-rendering after a partial failure. Only honour foreign user
        // IDs when the caller is allowed to see other users; otherwise fall back
        // to the caller's own ID so the dropdown can't leak hidden accounts.
        $postParams = $this->request->getPostParams();
        if (array_key_exists('owner', $postParams)) {
            if ($postParams['owner'] === '') {
                $owner_value = '';
            } elseif (is_numeric($postParams['owner'])) {
                $postedId = (int)$postParams['owner'];
                $owner_value = ($postedId === $callerId || $canViewOthers) ? $postedId : $callerId;
            } else {
                $owner_value = $callerId;
            }
        } else {
            $owner_value = $callerId;
        }

        $this->render('bulk_registration.html', [
            'userid' => $_SESSION[SessionKeys::USERID],
            'owner_value' => $owner_value,
            'perm_view_others' => $this->hasPermission('user_view_others'),
            'perm_edit_others' => $this->hasPermission('user_edit_others'),
            'iface_zone_type_default' => $this->config->get('dns', 'zone_type_default', 'MASTER'),
            'available_zone_types' => self::AVAILABLE_ZONE_TYPES,
            'users' => $this->createUserRepository()->getUsersWithZoneCounts(),
            'zone_templates' => $zone_templates->getListZoneTempl($_SESSION[SessionKeys::USERID]),
            'failed_domains' => $failed_domains,
            'added_domains' => $added_domains,
            'user_owner_allowed' => $ownershipMode->isUserOwnerAllowed(),
            'group_owner_allowed' => $ownershipMode->isGroupOwnerAllowed(),
            'all_groups' => $allGroups,
            'selected_groups' => isset($postParams['groups']) && is_array($postParams['groups']) ? array_map('intval', $postParams['groups']) : [],
        ]);
    }
}
