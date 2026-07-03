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
 * Script that handles requests to add new master zones
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Poweradmin\Application\Http\Request;
use Poweradmin\Application\Service\AuditService;
use Poweradmin\Application\Service\DnssecProviderFactory;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Model\ZoneTemplate;
use Poweradmin\Domain\Service\DnsIdnService;
use Poweradmin\Infrastructure\Service\DnsServiceFactory;
use Poweradmin\Domain\Utility\DomainUtility;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Domain\Service\ZoneOwnershipModeService;
use Poweradmin\Domain\Service\ZoneValidationService;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Poweradmin\Infrastructure\Utility\IpAddressRetriever;
use Poweradmin\Domain\Service\SessionKeys;
use Symfony\Component\Validator\Constraints as Assert;

class AddZoneMasterController extends BaseController
{

    private LegacyLogger $auditLogger;
    private UserContextService $userContext;
    private IpAddressRetriever $ipAddressRetriever;
    private Request $request;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $this->auditLogger = new LegacyLogger($this->db);
        $this->userContext = new UserContextService();
        $this->ipAddressRetriever = new IpAddressRetriever($_SERVER);
        $this->request = new Request();
    }

    public function run(): void
    {
        $this->checkPermission('zone_master_add', _("You do not have the permission to add a master zone."));

        // Set the current page for navigation highlighting
        $this->setCurrentPage('add_zone_master');
        $this->setPageTitle(_('Add Primary Zone'));

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
        if (UserManager::verifyPermission($this->db, 'user_is_ueberuser')) {
            if (empty($userGroupRepo->findAll())) {
                return _('Zone ownership mode is groups_only but no groups exist. Create a group before adding zones.');
            }
            return null;
        }
        if (empty($userGroupRepo->findByUserId($this->userContext->getLoggedInUserId()))) {
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
            'dom_type' => [
                new Assert\NotBlank()
            ],
            'zone_template' => [
                new Assert\NotBlank()
            ]
        ];

        $this->setValidationConstraints($constraints);

        $postData = $this->request->getPostParams();
        if (!$this->doValidateRequest($postData)) {
            $this->showFirstValidationError($postData);
        }

        $pdnssec_use = $this->config->get('dnssec', 'enabled', false);
        $dns_third_level_check = $this->config->get('dns', 'third_level_check', false);

        $ownershipMode = new ZoneOwnershipModeService($this->config);

        $raw_domain = trim((string)$this->request->getPostParam('domain', ''));

        // On the reverse-zone form, accept a network (e.g. 192.168.1.0/24,
        // 2001:db8::/48) and create the matching in-addr.arpa/ip6.arpa zone
        // instead of silently creating a forward zone with that literal name.
        $is_reverse_context = $this->request->getPostParam('type') === 'reverse';
        if ($is_reverse_context) {
            $reverse_zone = DnsHelper::resolveReverseZoneName($raw_domain);
            if ($reverse_zone === null) {
                $this->setMessage('add_zone_master', 'error', _('Enter a network in CIDR notation (for example 192.168.1.0/24 or 2001:db8::/48) or a reverse zone name ending in in-addr.arpa or ip6.arpa.'));
                $this->showForm();
                return;
            }
            $raw_domain = $reverse_zone;
        }

        $zone_name = DnsIdnService::toPunycode($raw_domain);
        $dom_type = $this->request->getPostParam('dom_type', '');
        $ownerInput = $this->request->getPostParam('owner');
        $owner = $ownershipMode->isUserOwnerAllowed() && !empty($ownerInput) ? (int)$ownerInput : null;
        $zone_template = $this->request->getPostParam('zone_template', 'none');
        $groupsInput = $this->request->getPostParam('groups');
        $selected_groups = $ownershipMode->isGroupOwnerAllowed() && is_array($groupsInput) ?
            array_map('intval', $groupsInput) : [];

        // Validate: at least one owner (user or group) must be selected
        if ($owner === null && empty($selected_groups)) {
            $this->setMessage('add_zone_master', 'error', _('At least one user or group must be selected as owner.'));
            $this->showForm();
            return;
        }

        // Block assigning a zone to a different user without elevated permission
        $callerId = $this->userContext->getLoggedInUserId();
        if ($owner !== null && $owner !== $callerId) {
            $isAdmin = UserManager::verifyPermission($this->db, 'user_is_ueberuser');
            if (!$isAdmin && !UserManager::verifyPermission($this->db, 'zone_content_edit_others')) {
                $this->setMessage('add_zone_master', 'error', _('You do not have permission to create zones for other users.'));
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
                $this->setMessage('add_zone_master', 'error', sprintf(_('Unknown group ID(s): %s'), implode(',', $unknown)));
                $this->showForm();
                return;
            }
            $selected_groups = $existing;

            $isAdmin = UserManager::verifyPermission($this->db, 'user_is_ueberuser');
            if (!$isAdmin) {
                $userId = $this->userContext->getLoggedInUserId();
                $allowedGroups = $userGroupRepo->findByUserId($userId);
                $allowedGroupIds = array_map(fn($g) => $g->getId(), $allowedGroups);
                $disallowed = array_values(array_diff($selected_groups, $allowedGroupIds));
                if (!empty($disallowed)) {
                    $this->setMessage('add_zone_master', 'error', sprintf(_('You can only assign groups you are a member of (disallowed: %s)'), implode(',', $disallowed)));
                    $this->showForm();
                    return;
                }
            }
        }

        $domainRepository = $this->createDomainRepository();
        $recordRepository = $this->createRecordRepository();
        $hostnameValidator = new HostnameValidator($this->config);
        if (!$hostnameValidator->isValid($zone_name)) {
            // Don't add a generic error as the validation method already sets a specific one
            $this->showForm();
        } elseif ($dns_third_level_check && DomainUtility::getDomainLevel($zone_name) > 2 && $domainRepository->domainExists(DomainUtility::getSecondLevelDomain($zone_name))) {
            $this->setMessage('add_zone_master', 'error', _('There is already a zone with this name.'));
            $this->showForm();
        } elseif ($domainRepository->domainExists($zone_name) || $recordRepository->recordNameExists($zone_name)) {
            $this->setMessage('add_zone_master', 'error', _('There is already a zone with this name.'));
            $this->showForm();
        } elseif (($overlapError = $this->getZoneOverlapError($zone_name)) !== null) {
            $this->setMessage('add_zone_master', 'error', $overlapError);
            $this->showForm();
        } elseif ($this->createDomainManager()->addDomain($this->db, $zone_name, $owner, $dom_type, '', $zone_template, $selected_groups)) {
            $zone_id = $domainRepository->getZoneIdFromName($zone_name);

            $this->auditLogger->logInfo(sprintf(
                'client_ip:%s user:%s operation:add_zone zone_name:%s zone_type:%s zone_template:%s',
                $this->ipAddressRetriever->getClientIp(),
                $this->userContext->getLoggedInUsername(),
                $zone_name,
                $dom_type,
                $zone_template
            ), $zone_id);

            $dnssecMessageSet = false;

            if ($pdnssec_use) {
                $dnssecProvider = DnssecProviderFactory::create($this->db, $this->getConfig());

                if ($this->request->getPostParam('dnssec') !== null && $dnssecProvider->isDnssecEnabled()) {
                    // Pre-flight zone validation before DNSSEC signing
                    $zoneValidator = new ZoneValidationService($this->getRepositoryFactory()->createRecordRepository());
                    $validation = $zoneValidator->validateZoneForDnssec($zone_id, $zone_name);

                    if (!$validation['valid']) {
                        // Show validation errors to user
                        $errorMsg = $zoneValidator->getFormattedErrorMessage($validation);
                        $messageKey = DnsHelper::isReverseZone($zone_name) ? 'list_reverse_zones' : 'list_forward_zones';
                        $this->setMessage($messageKey, 'warning', _('Zone was created successfully, but DNSSEC signing was skipped due to validation errors:') . "\n\n" . $errorMsg);
                        $this->logger->warning('DNSSEC pre-flight validation failed for newly created zone: {zone}', ['zone' => $zone_name]);
                        $dnssecMessageSet = true;
                    } else {
                        // Validation passed - proceed with signing
                        // Update SOA serial before signing
                        DnsServiceFactory::createSOARecordManager($this->db, $this->getConfig())->updateSOASerial($zone_id);

                        $secureResult = $dnssecProvider->secureZone($zone_name);
                        $messageKey = DnsHelper::isReverseZone($zone_name) ? 'list_reverse_zones' : 'list_forward_zones';

                        if (!$secureResult) {
                            $this->setMessage($messageKey, 'warning', _('Zone was created, but securing it with DNSSEC failed. Zone validation passed, but PowerDNS API returned an error. Check PowerDNS logs for details.'));
                            $this->logger->error('DNSSEC signing failed for newly created zone: {zone}', ['zone' => $zone_name]);
                            $dnssecMessageSet = true;
                        } else {
                            // Verify the zone is now secured
                            if ($dnssecProvider->isZoneSecured($zone_name, $this->getConfig())) {
                                $this->setMessage($messageKey, 'success', _('Zone has been created and signed with DNSSEC successfully.'));
                                (new AuditService($this->db))->logDnssecSignZone($zone_id, $zone_name);
                                $dnssecMessageSet = true;
                            } else {
                                $this->setMessage($messageKey, 'warning', _('Zone was created and signing was requested, but verification failed. Check DNSSEC keys.'));
                                $this->logger->warning('DNSSEC signing verification failed for newly created zone: {zone}', ['zone' => $zone_name]);
                                $dnssecMessageSet = true;
                            }
                        }
                    }
                }

                $dnssecProvider->rectifyZone($zone_name);
            }

            // Check if the zone is a reverse zone and redirect accordingly
            if (DnsHelper::isReverseZone($zone_name)) {
                if (!$dnssecMessageSet) {
                    $this->setMessage('list_reverse_zones', 'success', _('Zone has been added successfully.'));
                }
                $this->redirect('/zones/reverse');
            } else {
                if (!$dnssecMessageSet) {
                    $this->setMessage('list_forward_zones', 'success', _('Zone has been added successfully.'));
                }
                $this->redirect('/zones/forward');
            }
        }
    }

    private function showForm(): void
    {
        $perm_view_others = UserManager::verifyPermission($this->db, 'user_view_others');
        $zone_templates = new ZoneTemplate($this->db, $this->getConfig());
        $pdnssec_use = $this->config->get('dnssec', 'enabled', false);

        // Keep the submitted zone name if there was an error
        $domainInput = $this->request->getPostParam('domain');
        $domain_value = $domainInput !== null ? htmlspecialchars($domainInput) : '';

        // Resolve the system-wide default template (DB flag → config setting → none)
        $default_template_id = $zone_templates->getDefaultTemplateId();

        // Safely handle the zone template value
        $zoneTemplateInput = $this->request->getPostParam('zone_template');
        if ($zoneTemplateInput !== null) {
            // If it's 'none', keep it as is
            if ($zoneTemplateInput === 'none') {
                $zone_template_value = 'none';
            } else {
                // Otherwise, ensure it's a valid integer
                $template_id = filter_var($zoneTemplateInput, FILTER_VALIDATE_INT);
                // Get the list of valid template IDs
                $templates = $zone_templates->getListZoneTempl($_SESSION[SessionKeys::USERID]);
                $valid_template_ids = array_column($templates, 'id');
                $zone_template_value = ($template_id !== false && in_array($template_id, $valid_template_ids)) ?
                    $template_id : 'none';
            }
        } else {
            $zone_template_value = $default_template_id !== null ? $default_template_id : 'none';
        }

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

        // Safely handle the domain type value. Catalog zone kinds (Producer/
        // Consumer) only appear on PowerDNS 4.7+ - older servers reject them.
        $valid_domain_types = array("MASTER", "NATIVE");
        if ($this->getPdnsCapabilities()->supportsCatalogZones()) {
            $valid_domain_types[] = "PRODUCER";
            $valid_domain_types[] = "CONSUMER";
        }
        $domTypeInput = $this->request->getPostParam('dom_type');
        $dom_type_value = $domTypeInput !== null && in_array($domTypeInput, $valid_domain_types) ?
            $domTypeInput : $this->config->get('dns', 'zone_type_default', 'NATIVE');

        $is_post_request = !empty($this->request->getPostParams());

        // Create a sanitized version of the DNSSEC checkbox status
        $dnssec_checked = $this->request->getPostParam('dnssec') == '1';

        // Get available templates for this user
        $userId = $this->userContext->getLoggedInUserId();
        $templates = $zone_templates->getListZoneTempl($userId);

        // Fetch groups for the dropdown - admins see all, others see only their own
        $userGroupRepo = $this->createUserGroupRepository();
        $isAdmin = UserManager::verifyPermission($this->db, 'user_is_ueberuser');
        $allGroups = $isAdmin ? $userGroupRepo->findAll() : $userGroupRepo->findByUserId($userId);

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

        $this->render('add_zone_master.html', [
            'is_reverse_zone' => $is_reverse_zone,
            'perm_view_others' => $perm_view_others,
            'session_user_id' => $userId,
            'available_zone_types' => $valid_domain_types,
            'users' => UserManager::showUsers($this->db),
            'zone_templates' => $templates,
            'can_use_templates' => !empty($templates),
            'default_template_id' => $default_template_id,
            'iface_zone_type_default' => $this->config->get('dns', 'zone_type_default', 'NATIVE'),
            'iface_add_domain_record' => $this->config->get('interface', 'add_domain_record', false),
            'pdnssec_use' => $pdnssec_use,
            'domain_value' => $domain_value,
            'zone_template_value' => $zone_template_value,
            'owner_value' => $owner_value,
            'dom_type_value' => $dom_type_value,
            'is_post' => $is_post_request,
            'dnssec_checked' => $dnssec_checked,
            'all_groups' => $allGroups,
            'group_member_counts' => $memberCounts,
            'selected_groups' => $selected_groups,
            'user_owner_allowed' => $ownershipMode->isUserOwnerAllowed(),
            'group_owner_allowed' => $ownershipMode->isGroupOwnerAllowed(),
            // Don't pass raw POST data to the template for security
        ]);
    }
}
