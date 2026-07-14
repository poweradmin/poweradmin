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
 * Script that handles requests to add new slave zone
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Poweradmin\Application\Http\Request;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Service\DnsIdnService;
use Poweradmin\Domain\Utility\DomainUtility;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Domain\Service\DnsValidation\IPAddressValidator;
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
    private IPAddressValidator $ipAddressValidator;
    private IpAddressRetriever $ipAddressRetriever;
    private UserContextService $userContextService;
    private Request $request;

    public function __construct(array $request)
    {
        parent::__construct($request);
        $this->auditLogger = new LegacyLogger($this->db);
        $this->ipAddressValidator = new IPAddressValidator();
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

        $blocker = $this->getOwnerOptionsBlocker();
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

    private function getOwnerOptionsBlocker(): ?string
    {
        $ownershipMode = new ZoneOwnershipModeService($this->config);
        if ($ownershipMode->isUserOwnerAllowed()) {
            return null;
        }
        $userGroupRepo = $this->createUserGroupRepository();
        if ($this->hasPermission('user_is_ueberuser')) {
            if (empty($userGroupRepo->findAll())) {
                return _('Zone ownership mode is groups_only but no groups exist. Create a group before adding zones.');
            }
            return null;
        }
        if (empty($userGroupRepo->findByUserId($this->userContextService->getLoggedInUserId()))) {
            return _('Zone ownership mode is groups_only but you are not a member of any group. Ask an administrator to add you to a group before creating zones.');
        }
        return null;
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

        $dns_third_level_check = $this->config->get('dns', 'third_level_check', false);

        $ownershipMode = new ZoneOwnershipModeService($this->config);

        $type = "SLAVE";
        $ownerInput = $this->request->getPostParam('owner');
        $owner = $ownershipMode->isUserOwnerAllowed() && !empty($ownerInput) ? (int)$ownerInput : null;
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
        $groupsInput = $this->request->getPostParam('groups');
        $selected_groups = $ownershipMode->isGroupOwnerAllowed() && is_array($groupsInput) ?
            array_map('intval', $groupsInput) : [];

        // Validate: at least one owner (user or group) must be selected
        if ($owner === null && empty($selected_groups)) {
            $this->setMessage('add_zone_slave', 'error', _('At least one user or group must be selected as owner.'));
            $this->showForm();
            return;
        }

        // Block assigning a zone to a different user without elevated permission
        $callerId = $this->userContextService->getLoggedInUserId();
        if ($owner !== null && $owner !== $callerId) {
            $isAdmin = $this->hasPermission('user_is_ueberuser');
            if (!$isAdmin && !$this->hasPermission('zone_content_edit_others')) {
                $this->setMessage('add_zone_slave', 'error', _('You do not have permission to create zones for other users.'));
                $this->showForm();
                return;
            }
        }

        // Validate submitted group IDs against user's allowed groups
        if (!empty($selected_groups)) {
            $userGroupRepo = $this->createUserGroupRepository();
            $existing = $userGroupRepo->findExistingIds($selected_groups);
            $unknown = array_values(array_diff($selected_groups, $existing));
            if (!empty($unknown)) {
                $this->setMessage('add_zone_slave', 'error', sprintf(_('Unknown group ID(s): %s'), implode(',', $unknown)));
                $this->showForm();
                return;
            }
            $selected_groups = $existing;

            $isAdmin = $this->hasPermission('user_is_ueberuser');
            if (!$isAdmin) {
                $allowedGroups = $userGroupRepo->findByUserId($_SESSION[SessionKeys::USERID]);
                $allowedGroupIds = array_map(fn($g) => $g->getId(), $allowedGroups);
                $disallowed = array_values(array_diff($selected_groups, $allowedGroupIds));
                if (!empty($disallowed)) {
                    $this->setMessage('add_zone_slave', 'error', sprintf(_('You can only assign groups you are a member of (disallowed: %s)'), implode(',', $disallowed)));
                    $this->showForm();
                    return;
                }
            }
        }

        $domainRepository = $this->createDomainRepository();
        $recordRepository = $this->createRecordRepository();
        $hostnameValidator = new HostnameValidator($this->config);
        if (!$hostnameValidator->isValid($zone)) {
            $this->setMessage('add_zone_slave', 'error', _('Invalid hostname.'));
            $this->showForm();
        } elseif ($dns_third_level_check && DomainUtility::getDomainLevel($zone) > 2 && $domainRepository->domainExists(DomainUtility::getSecondLevelDomain($zone))) {
            $this->setMessage('add_zone_slave', 'error', _('There is already a zone with this name.'));
            $this->showForm();
        } elseif ($domainRepository->domainExists($zone) || $recordRepository->hasNonDelegationRecords($zone)) {
            $this->setMessage('add_zone_slave', 'error', _('There is already a zone with this name.'));
            $this->showForm();
        } elseif (($overlapError = $this->getZoneOverlapError($zone)) !== null) {
            $this->setMessage('add_zone_slave', 'error', $overlapError);
            $this->showForm();
        } elseif (!$this->ipAddressValidator->areMultipleValidIPs($master)) {
            $this->setMessage('add_zone_slave', 'error', _('This is not a valid IPv4 or IPv6 address.'));
            $this->showForm();
        } else {
            if ($this->createDomainManager()->addDomain($this->db, $zone, $owner, $type, $master, 'none', $selected_groups)) {
                $zone_id = $domainRepository->getZoneIdFromName($zone);

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
        }
    }

    private function showForm(): void
    {
        // Keep the submitted values if there was an error
        $domainInput = $this->request->getPostParam('domain');
        $domain_value = $domainInput !== null ? htmlspecialchars($domainInput) : '';
        $slaveMasterInput = $this->request->getPostParam('slave_master');
        $slave_master_value = $slaveMasterInput !== null ? htmlspecialchars($slaveMasterInput) : '';

        // Safely handle the owner value - ensure it's an integer or preserve empty selection
        $ownerInput = $this->request->getPostParam('owner');
        if ($ownerInput !== null) {
            if ($ownerInput === '') {
                // Empty value means "no user owner" was explicitly selected
                $owner_value = '';
            } else {
                $owner_id = filter_var($ownerInput, FILTER_VALIDATE_INT);
                // Verify that the owner ID exists among valid users
                $valid_users = UserManager::showUsers($this->db);
                $valid_owner_ids = array_column($valid_users, 'id');
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
            'users' => UserManager::showUsers($this->db),
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
