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

use Poweradmin\Application\Service\DnssecProviderFactory;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Model\ZoneTemplate;
use Poweradmin\Domain\Service\DnsIdnService;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Domain\Service\ZoneValidationService;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Poweradmin\Infrastructure\Repository\DbUserGroupRepository;
use Poweradmin\Infrastructure\Repository\DbZoneGroupRepository;
use Symfony\Component\Validator\Constraints as Assert;

class AddZoneMasterController extends BaseController
{

    private LegacyLogger $logger;
    private UserContextService $userContext;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $this->logger = new LegacyLogger($this->db);
        $this->userContext = new UserContextService();
    }

    public function run(): void
    {
        $this->checkPermission('zone_master_add', _("You do not have the permission to add a master zone."));

        // Set the current page for navigation highlighting
        $this->requestData['page'] = 'add_zone_master';

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
            'dom_type' => [
                new Assert\NotBlank()
            ],
            'zone_template' => [
                new Assert\NotBlank()
            ]
        ];

        $this->setValidationConstraints($constraints);

        if (!$this->doValidateRequest($_POST)) {
            $this->showFirstValidationError($_POST);
        }

        $pdnssec_use = $this->config->get('dnssec', 'enabled', false);
        $dns_third_level_check = $this->config->get('dns', 'third_level_check', false);

        $zone_name = DnsIdnService::toPunycode(trim($_POST['domain']));
        $dom_type = $_POST["dom_type"];
        $owner = !empty($_POST['owner']) ? (int)$_POST['owner'] : null;
        $zone_template = $_POST['zone_template'] ?? "none";
        $selected_groups = isset($_POST['groups']) && is_array($_POST['groups']) ?
            array_map('intval', $_POST['groups']) : [];

        // Validate: at least one owner (user or group) must be selected
        if ($owner === null && empty($selected_groups)) {
            $this->setMessage('add_zone_master', 'error', _('At least one user or group must be selected as owner.'));
            $this->showForm();
            return;
        }

        $dnsRecord = new DnsRecord($this->db, $this->getConfig());
        $hostnameValidator = new HostnameValidator($this->config);
        if (!$hostnameValidator->isValid($zone_name)) {
            // Don't add a generic error as the validation method already sets a specific one
            $this->showForm();
        } elseif ($dns_third_level_check && DnsRecord::getDomainLevel($zone_name) > 2 && $dnsRecord->domainExists(DnsRecord::getSecondLevelDomain($zone_name))) {
            $this->setMessage('add_zone_master', 'error', _('There is already a zone with this name.'));
            $this->showForm();
        } elseif ($dnsRecord->domainExists($zone_name) || $dnsRecord->recordNameExists($zone_name)) {
            $this->setMessage('add_zone_master', 'error', _('There is already a zone with this name.'));
            $this->showForm();
        } elseif ($dnsRecord->addDomain($this->db, $zone_name, $owner, $dom_type, '', $zone_template)) {
            $zone_id = $dnsRecord->getZoneIdFromName($zone_name);

            // Add group ownership if groups were selected
            if (!empty($selected_groups)) {
                $zoneGroupRepo = new DbZoneGroupRepository($this->db, $this->getConfig());
                foreach ($selected_groups as $groupId) {
                    $zoneGroupRepo->add($zone_id, $groupId);
                }
            }
            $this->logger->logInfo(sprintf(
                'client_ip:%s user:%s operation:add_zone zone_name:%s zone_type:%s zone_template:%s',
                $_SERVER['REMOTE_ADDR'],
                $this->userContext->getLoggedInUsername(),
                $zone_name,
                $dom_type,
                $zone_template
            ), $zone_id);

            $dnssecMessageSet = false;

            if ($pdnssec_use) {
                $dnssecProvider = DnssecProviderFactory::create($this->db, $this->getConfig());

                if (isset($_POST['dnssec']) && $dnssecProvider->isDnssecEnabled()) {
                    // Pre-flight zone validation before DNSSEC signing
                    $zoneValidator = new ZoneValidationService($this->db);
                    $validation = $zoneValidator->validateZoneForDnssec($zone_id, $zone_name);

                    if (!$validation['valid']) {
                        // Show validation errors to user
                        $errorMsg = $zoneValidator->getFormattedErrorMessage($validation);
                        $messageKey = DnsHelper::isReverseZone($zone_name) ? 'list_reverse_zones' : 'list_forward_zones';
                        $this->setMessage($messageKey, 'warning', _('Zone was created successfully, but DNSSEC signing was skipped due to validation errors:') . "\n\n" . $errorMsg);
                        error_log("DNSSEC pre-flight validation failed for newly created zone: $zone_name");
                        $dnssecMessageSet = true;
                    } else {
                        // Validation passed - proceed with signing
                        // Update SOA serial before signing
                        $dnsRecord->updateSOASerial($zone_id);

                        $secureResult = $dnssecProvider->secureZone($zone_name);
                        $messageKey = DnsHelper::isReverseZone($zone_name) ? 'list_reverse_zones' : 'list_forward_zones';

                        if (!$secureResult) {
                            $this->setMessage($messageKey, 'warning', _('Zone was created, but securing it with DNSSEC failed. Zone validation passed, but PowerDNS API returned an error. Check PowerDNS logs for details.'));
                            error_log("DNSSEC signing failed for newly created zone: $zone_name");
                            $dnssecMessageSet = true;
                        } else {
                            // Verify the zone is now secured
                            if ($dnssecProvider->isZoneSecured($zone_name, $this->getConfig())) {
                                $this->setMessage($messageKey, 'success', _('Zone has been created and signed with DNSSEC successfully.'));
                                $dnssecMessageSet = true;
                            } else {
                                $this->setMessage($messageKey, 'warning', _('Zone was created and signing was requested, but verification failed. Check DNSSEC keys.'));
                                error_log("DNSSEC signing verification failed for newly created zone: $zone_name");
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
        $domain_value = isset($_POST['domain']) ? htmlspecialchars($_POST['domain']) : '';

        // Safely handle the zone template value
        if (isset($_POST['zone_template'])) {
            // If it's 'none', keep it as is
            if ($_POST['zone_template'] === 'none') {
                $zone_template_value = 'none';
            } else {
                // Otherwise, ensure it's a valid integer
                $template_id = filter_var($_POST['zone_template'], FILTER_VALIDATE_INT);
                // Get the list of valid template IDs
                $templates = $zone_templates->getListZoneTempl($_SESSION['userid']);
                $valid_template_ids = array_column($templates, 'id');
                $zone_template_value = ($template_id !== false && in_array($template_id, $valid_template_ids)) ?
                    $template_id : 'none';
            }
        } else {
            $zone_template_value = 'none';
        }

        // Safely handle the owner value - ensure it's an integer or preserve empty selection
        if (isset($_POST['owner'])) {
            if ($_POST['owner'] === '') {
                // Empty value means "no user owner" was explicitly selected
                $owner_value = '';
            } else {
                $owner_id = filter_var($_POST['owner'], FILTER_VALIDATE_INT);
                // Verify that the owner ID exists among valid users
                $valid_users = UserManager::showUsers($this->db);
                $valid_owner_ids = array_column($valid_users, 'id');
                $owner_value = ($owner_id !== false && in_array($owner_id, $valid_owner_ids)) ? $owner_id : $_SESSION['userid'];
            }
        } else {
            // No POST data, default to current user
            $owner_value = $_SESSION['userid'];
        }

        // Safely handle the domain type value
        $valid_domain_types = array("MASTER", "NATIVE");
        $dom_type_value = isset($_POST['dom_type']) && in_array($_POST['dom_type'], $valid_domain_types) ?
            $_POST['dom_type'] : $this->config->get('dns', 'zone_type_default', 'NATIVE');

        $is_post_request = !empty($_POST);

        // Create a sanitized version of the DNSSEC checkbox status
        $dnssec_checked = isset($_POST['dnssec']) && $_POST['dnssec'] == '1';

        // Get available templates for this user
        $userId = $this->userContext->getLoggedInUserId();
        $templates = $zone_templates->getListZoneTempl($userId);

        // Fetch all groups for the dropdown
        $userGroupRepo = new DbUserGroupRepository($this->db);
        $allGroups = $userGroupRepo->findAll();

        // Handle selected groups on error re-render
        $selected_groups = isset($_POST['groups']) && is_array($_POST['groups']) ?
            array_map('intval', $_POST['groups']) : [];

        $this->render('add_zone_master.html', [
            'perm_view_others' => $perm_view_others,
            'session_user_id' => $userId,
            'available_zone_types' => $valid_domain_types,
            'users' => UserManager::showUsers($this->db),
            'zone_templates' => $templates,
            'can_use_templates' => !empty($templates),
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
            'selected_groups' => $selected_groups,
            // Don't pass raw POST data to the template for security
        ]);
    }
}
