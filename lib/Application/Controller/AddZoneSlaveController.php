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
 * Script that handles requests to add new slave zone
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Poweradmin\Application\Http\Request;
use Poweradmin\Application\Service\ZoneCreateFormMessages;
use Poweradmin\Application\Service\ZoneOwnershipFormResolver;
use Poweradmin\BaseController;
use Poweradmin\Domain\Service\DnsIdnService;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Domain\Service\ZoneOwnershipModeService;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Poweradmin\Infrastructure\Utility\IpAddressRetriever;
use Poweradmin\Domain\Service\SessionKeys;
use Symfony\Component\Validator\Constraints as Assert;

class AddZoneSlaveController extends BaseController
{
    private LegacyLogger $auditLogger;
    private IpAddressRetriever $ipAddressRetriever;
    private UserContextService $userContextService;
    private Request $request;

    public function __construct(array $request)
    {
        parent::__construct($request);
        $this->auditLogger = new LegacyLogger($this->db);
        $this->ipAddressRetriever = new IpAddressRetriever($_SERVER);
        $this->userContextService = new UserContextService();
        $this->request = new Request();
    }

    public function run(): void
    {
        $this->checkPermission('zone_slave_add', _("You do not have the permission to add a slave zone."));

        // Set the current page for navigation highlighting
        $this->setCurrentPage('add_zone_slave');
        $this->setPageTitle(_('Add Secondary Zone'));

        $blocker = $this->zoneOwnerOptionsBlocker();
        if ($blocker !== null) {
            $this->showError($blocker);
            return;
        }

        if ($this->isPost()) {
            $this->validateCsrfToken();
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

        $postData = $this->request->getPostParams();
        if (!$this->doValidateRequest($postData)) {
            $this->showFirstValidationError($postData);
        }

        $type = "SLAVE";
        $master = (string)$this->request->getPostParam('slave_master', '');

        $raw_domain = trim((string)$this->request->getPostParam('domain', ''));

        // On the reverse-zone form, accept a network (e.g. 192.168.1.0/24,
        // 2001:db8::/48) and create the matching in-addr.arpa/ip6.arpa zone
        // instead of silently creating a forward zone with that literal name.
        $is_reverse_context = $this->request->getPostParam('type') === 'reverse';
        if ($is_reverse_context) {
            $reverse_zone = DnsHelper::resolveReverseZoneName($raw_domain);
            if ($reverse_zone === null) {
                $this->setMessage('add_zone_slave', 'error', _('Enter a network in CIDR notation (for example 192.168.1.0/24 or 2001:db8::/48) or a reverse zone name ending in in-addr.arpa or ip6.arpa.'));
                $this->showForm();
                return;
            }
            $raw_domain = $reverse_zone;
        }

        $zone = DnsIdnService::toPunycode($raw_domain);

        $ownership = $this->resolveZoneOwnershipFromForm($this->request);
        if ($ownership->hasError()) {
            $this->setMessage('add_zone_slave', 'error', ZoneOwnershipFormResolver::errorMessage($ownership));
            $this->showForm();
            return;
        }
        $owner = $ownership->owner;
        $selected_groups = $ownership->groupIds;

        $created = $this->createZoneManagementService()->createZone($zone, $type, $owner, $master, 'none', false, $selected_groups, $this->getCurrentUserId());
        if (!$created['success']) {
            $this->setMessage('add_zone_slave', 'error', ZoneCreateFormMessages::errorMessage($created));
            $this->showForm();
            return;
        }
        $zone_id = $created['zone_id'];

        $this->auditLogger->logInfo(sprintf(
            'client_ip:%s user:%s operation:add_zone zone:%s zone_type:SLAVE zone_master:%s',
            $this->ipAddressRetriever->getClientIp(),
            $this->userContextService->getLoggedInUsername(),
            $zone,
            $master
        ), $zone_id);

        // Check if the zone is a reverse zone and redirect accordingly
        if (DnsHelper::isReverseZoneName($zone)) {
            $this->setMessage('list_reverse_zones', 'success', _('Zone has been added successfully.'));
            $this->redirect('/zones/reverse');
        } else {
            $this->setMessage('list_forward_zones', 'success', _('Zone has been added successfully.'));
            $this->redirect('/zones/forward');
        }
    }

    private function showForm(): void
    {
        // Keep the submitted values if there was an error
        $domainInput = $this->request->getPostParam('domain');
        $domain_value = $domainInput !== null ? htmlspecialchars($domainInput) : '';
        $slaveMasterInput = $this->request->getPostParam('slave_master');
        $slave_master_value = $slaveMasterInput !== null ? htmlspecialchars($slaveMasterInput) : '';
        $users = $this->createUserRepository()->getUsersWithZoneCounts();

        // Safely handle the owner value - ensure it's an integer or preserve empty selection
        $ownerInput = $this->request->getPostParam('owner');
        if ($ownerInput !== null) {
            if ($ownerInput === '') {
                // Empty value means "no user owner" was explicitly selected
                $owner_value = '';
            } else {
                $owner_id = filter_var($ownerInput, FILTER_VALIDATE_INT);
                // Verify that the owner ID exists among valid users
                $valid_owner_ids = array_column($users, 'id');
                $owner_value = ($owner_id !== false && in_array($owner_id, $valid_owner_ids)) ? $owner_id : $_SESSION[SessionKeys::USERID];
            }
        } else {
            // No POST data, default to current user
            $owner_value = $_SESSION[SessionKeys::USERID];
        }

        $is_post_request = !empty($this->request->getPostParams());

        // Fetch groups for the dropdown - admins see all, others see only their own
        $userGroupRepo = $this->createUserGroupRepository();
        $isAdmin = $this->hasPermission('user_is_ueberuser');
        $allGroups = $isAdmin ? $userGroupRepo->findAll() : $userGroupRepo->findByUserId($_SESSION[SessionKeys::USERID]);

        // Fetch member counts for all groups in a single query
        $groupIds = array_map(fn($g) => $g->getId(), $allGroups);
        $memberCounts = $userGroupRepo->getMemberCountsByGroupIds($groupIds);

        // Handle selected groups on error re-render
        $groupsInput = $this->request->getPostParam('groups');
        $selected_groups = is_array($groupsInput) ? array_map('intval', $groupsInput) : [];

        $ownershipMode = new ZoneOwnershipModeService($this->config);

        // Preserve reverse-zone context so the form returns to the reverse list
        $is_reverse_zone = $this->request->getQueryParam('type') === 'reverse'
            || $this->request->getPostParam('type') === 'reverse';

        $this->render('add_zone_slave.html', [
            'is_reverse_zone' => $is_reverse_zone,
            'users' => $users,
            'session_user_id' => $_SESSION[SessionKeys::USERID],
            'perm_view_others' => $this->hasPermission('user_view_others'),
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
