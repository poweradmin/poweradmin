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
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Model\ZoneTemplate;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Domain\Service\ZoneOwnershipModeService;
use Poweradmin\Domain\Utility\DomainHelper;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Poweradmin\Infrastructure\Repository\DbUserGroupRepository;
use Poweradmin\Infrastructure\Utility\IpAddressRetriever;
use Poweradmin\Domain\Service\SessionKeys;
use Symfony\Component\Validator\Constraints as Assert;

class BulkRegistrationController extends BaseController
{

    private LegacyLogger $auditLogger;
    private IpAddressRetriever $ipAddressRetriever;
    private UserContextService $userContextService;
    private Request $request;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $this->request = new Request();
        $this->auditLogger = new LegacyLogger($this->db);
        $this->ipAddressRetriever = new IpAddressRetriever($_SERVER);
        $this->userContextService = new UserContextService();
    }

    public function run(): void
    {
        $this->checkPermission('zone_master_add', _("You do not have the permission to add a master zone."));

        // Set the current page for navigation highlighting
        $this->setCurrentPage('bulk_registration');
        $this->setPageTitle(_('Bulk Registration'));

        $blocker = $this->getOwnerOptionsBlocker();
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

    private function getOwnerOptionsBlocker(): ?string
    {
        $ownershipMode = new ZoneOwnershipModeService($this->config);
        if ($ownershipMode->isUserOwnerAllowed()) {
            return null;
        }
        $userGroupRepo = new DbUserGroupRepository($this->db);
        if (UserManager::verifyPermission($this->db, 'user_is_ueberuser')) {
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

        $ownershipMode = new ZoneOwnershipModeService($this->config);
        $domains = DomainHelper::getDomains($this->request->getPostParam('domains'));
        $dom_type = $this->request->getPostParam('dom_type');
        $zone_template = $this->request->getPostParam('zone_template');

        $rawOwner = $this->request->getPostParam('owner', '');
        if ($ownershipMode->isUserOwnerAllowed() && $rawOwner !== '' && $rawOwner !== null) {
            if (!is_numeric($rawOwner)) {
                $this->setMessage('bulk_registration', 'error', _('Owner must be a numeric user ID.'));
                $this->showBulkRegistrationForm();
                return;
            }
            // owner=0 is treated as orphan everywhere else; coerce to null so
            // the at-least-one-owner guard below catches it.
            $parsedOwner = (int)$rawOwner;
            $owner = $parsedOwner > 0 ? $parsedOwner : null;
        } else {
            $owner = null;
        }
        $groups = $this->request->getPostParam('groups');
        $selected_groups = $ownershipMode->isGroupOwnerAllowed() && is_array($groups) ?
            array_map('intval', $groups) : [];

        if (!empty($selected_groups)) {
            $userGroupRepo = new DbUserGroupRepository($this->db);
            $existing = $userGroupRepo->findExistingIds($selected_groups);
            $unknown = array_values(array_diff($selected_groups, $existing));
            if (!empty($unknown)) {
                $this->setMessage('bulk_registration', 'error', sprintf(_('Unknown group ID(s): %s'), implode(',', $unknown)));
                $this->showBulkRegistrationForm();
                return;
            }
            $selected_groups = $existing;

            // Reject (don't silently drop) groups the caller is not a member of
            if (!UserManager::verifyPermission($this->db, 'user_is_ueberuser')) {
                $callerId = $this->userContextService->getLoggedInUserId();
                $allowedIds = array_map(fn($g) => $g->getId(), $userGroupRepo->findByUserId($callerId));
                $disallowed = array_values(array_diff($selected_groups, $allowedIds));
                if (!empty($disallowed)) {
                    $this->setMessage('bulk_registration', 'error', sprintf(_('You can only assign groups you are a member of (disallowed: %s)'), implode(',', $disallowed)));
                    $this->showBulkRegistrationForm();
                    return;
                }
            }
        }

        if ($owner === null && empty($selected_groups)) {
            $this->setMessage('bulk_registration', 'error', _('At least one user or group must be selected as owner.'));
            $this->showBulkRegistrationForm();
            return;
        }

        // Block assigning zones to a different user without elevated permission
        $callerId = $this->userContextService->getLoggedInUserId();
        if ($owner !== null && $owner !== $callerId) {
            $isAdmin = UserManager::verifyPermission($this->db, 'user_is_ueberuser');
            if (!$isAdmin && !UserManager::verifyPermission($this->db, 'zone_content_edit_others')) {
                $this->setMessage('bulk_registration', 'error', _('You do not have permission to create zones for other users.'));
                $this->showBulkRegistrationForm();
                return;
            }
        }

        $added_domains = [];
        $failed_domains = [];
        $domainRepository = $this->createDomainRepository();
        $domainManager = $this->createDomainManager();
        foreach ($domains as $domain) {
            $hostnameValidator = new HostnameValidator($this->config);
            if (!$hostnameValidator->isValid($domain)) {
                $failed_domains[] = ['name' => $domain, 'reason' => _('Invalid hostname.')];
            } elseif ($domainRepository->domainExists($domain)) {
                $failed_domains[] = ['name' => $domain, 'reason' => _('There is already a zone with this name.')];
            } elseif (($overlapError = $this->getZoneOverlapError($domain)) !== null) {
                $failed_domains[] = ['name' => $domain, 'reason' => $overlapError];
            } elseif ($domainManager->addDomain($this->db, $domain, $owner, $dom_type, '', $zone_template, $selected_groups)) {
                $added_domains[] = $domain;
                $zone_id = $domainRepository->getZoneIdFromName($domain);
                $this->auditLogger->logInfo(sprintf(
                    'client_ip:%s user:%s operation:add_zone zone:%s zone_type:%s zone_template:%s',
                    $this->ipAddressRetriever->getClientIp(),
                    $this->userContextService->getLoggedInUsername(),
                    $domain,
                    $dom_type,
                    $zone_template
                ), $zone_id);
            } else {
                $failed_domains[] = ['name' => $domain, 'reason' => _('Failed to add zone.')];
            }
        }

        if (!$failed_domains) {
            $this->setMessage('list_forward_zones', 'success', _('Zones has been added successfully.'));
            $this->redirect('/zones/forward');
        } else {
            $this->setMessage('bulk_registration', 'warn', _('Some zone(s) could not be added.'));
            $this->showBulkRegistrationForm($failed_domains, $added_domains);
        }
    }

    private function showBulkRegistrationForm(array $failed_domains = [], array $added_domains = []): void
    {
        $zone_templates = new ZoneTemplate($this->db, $this->getConfig());
        $ownershipMode = new ZoneOwnershipModeService($this->config);

        $userGroupRepo = new DbUserGroupRepository($this->db);
        $isAdmin = UserManager::verifyPermission($this->db, 'user_is_ueberuser');
        $allGroups = $isAdmin ? $userGroupRepo->findAll() : $userGroupRepo->findByUserId($_SESSION[SessionKeys::USERID]);

        $callerId = $this->userContextService->getLoggedInUserId();
        $canViewOthers = UserManager::verifyPermission($this->db, 'user_view_others');
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
            'perm_view_others' => UserManager::verifyPermission($this->db, 'user_view_others'),
            'perm_edit_others' => UserManager::verifyPermission($this->db, 'user_edit_others'),
            'iface_zone_type_default' => $this->config->get('dns', 'zone_type_default', 'MASTER'),
            'available_zone_types' => array("MASTER", "NATIVE"),
            'users' => UserManager::showUsers($this->db),
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
