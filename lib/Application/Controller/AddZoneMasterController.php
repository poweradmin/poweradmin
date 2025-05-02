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
use Poweradmin\Domain\Service\Dns;
use Poweradmin\Domain\Service\DnsIdnService;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Symfony\Component\Validator\Constraints as Assert;

class AddZoneMasterController extends BaseController
{

    private LegacyLogger $logger;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $this->logger = new LegacyLogger($this->db);
    }

    public function run(): void
    {
        $this->checkPermission('zone_master_add', _("You do not have the permission to add a master zone."));

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
            'owner' => [
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

        $pdnssec_use = $this->config->get('pdnssec', 'use', false);
        $dns_third_level_check = $this->config->get('dns', 'third_level_check', false);

        $zone_name = DnsIdnService::toPunycode(trim($_POST['domain']));
        $dom_type = $_POST["dom_type"];
        $owner = $_POST['owner'];
        $zone_template = $_POST['zone_template'] ?? "none";

        $dnsRecord = new DnsRecord($this->db, $this->getConfig());
        $hostnameValidator = new HostnameValidator($this->config);
        if (!$hostnameValidator->isValidHostnameFqdn($zone_name, 0)) {
            // Don't add a generic error as the validation method already sets a specific one
            $this->showForm();
        } elseif ($dns_third_level_check && DnsRecord::get_domain_level($zone_name) > 2 && $dnsRecord->domain_exists(DnsRecord::get_second_level_domain($zone_name))) {
            $this->setMessage('add_zone_master', 'error', _('There is already a zone with this name.'));
            $this->showForm();
        } elseif ($dnsRecord->domain_exists($zone_name) || $dnsRecord->record_name_exists($zone_name)) {
            $this->setMessage('add_zone_master', 'error', _('There is already a zone with this name.'));
            $this->showForm();
        } elseif ($dnsRecord->add_domain($this->db, $zone_name, $owner, $dom_type, '', $zone_template)) {
            $zone_id = $dnsRecord->get_zone_id_from_name($zone_name);
            $this->logger->log_info(sprintf(
                'client_ip:%s user:%s operation:add_zone zone_name:%s zone_type:%s zone_template:%s',
                $_SERVER['REMOTE_ADDR'],
                $_SESSION["userlogin"],
                $zone_name,
                $dom_type,
                $zone_template
            ), $zone_id);

            if ($pdnssec_use) {
                $dnssecProvider = DnssecProviderFactory::create($this->db, $this->getConfig());

                if (isset($_POST['dnssec']) && $dnssecProvider->isDnssecEnabled()) {
                    $dnssecProvider->secureZone($zone_name);
                }

                $dnssecProvider->rectifyZone($zone_name);
            }

            // Check if the zone is a reverse zone and redirect accordingly
            if (DnsHelper::isReverseZone($zone_name)) {
                $this->setMessage('list_reverse_zones', 'success', _('Zone has been added successfully.'));
                $this->redirect('index.php', ['page' => 'list_reverse_zones']);
            } else {
                $this->setMessage('list_forward_zones', 'success', _('Zone has been added successfully.'));
                $this->redirect('index.php', ['page' => 'list_forward_zones']);
            }
        }
    }

    private function showForm(): void
    {
        $perm_view_others = UserManager::verify_permission($this->db, 'user_view_others');
        $zone_templates = new ZoneTemplate($this->db, $this->getConfig());

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
                $templates = $zone_templates->get_list_zone_templ($_SESSION['userid']);
                $valid_template_ids = array_column($templates, 'id');
                $zone_template_value = ($template_id !== false && in_array($template_id, $valid_template_ids)) ?
                    $template_id : 'none';
            }
        } else {
            $zone_template_value = 'none';
        }

        // Safely handle the owner value - ensure it's an integer
        if (isset($_POST['owner'])) {
            $owner_id = filter_var($_POST['owner'], FILTER_VALIDATE_INT);
            // Verify that the owner ID exists among valid users
            $valid_users = UserManager::show_users($this->db);
            $valid_owner_ids = array_column($valid_users, 'id');
            $owner_value = ($owner_id !== false && in_array($owner_id, $valid_owner_ids)) ? $owner_id : $_SESSION['userid'];
        } else {
            $owner_value = $_SESSION['userid'];
        }

        // Safely handle the domain type value
        $valid_domain_types = array("MASTER", "NATIVE");
        $dom_type_value = isset($_POST['dom_type']) && in_array($_POST['dom_type'], $valid_domain_types) ?
            $_POST['dom_type'] : $this->config->get('interface', 'zone_type_default', 'NATIVE');

        $is_post_request = !empty($_POST);

        // Create a sanitized version of the DNSSEC checkbox status
        $dnssec_checked = isset($_POST['dnssec']) && $_POST['dnssec'] == '1';

        $this->render('add_zone_master.html', [
            'perm_view_others' => $perm_view_others,
            'session_user_id' => $_SESSION['userid'],
            'available_zone_types' => $valid_domain_types,
            'users' => UserManager::show_users($this->db),
            'zone_templates' => $zone_templates->get_list_zone_templ($_SESSION['userid']),
            'iface_zone_type_default' => $this->config->get('interface', 'zone_type_default', 'NATIVE'),
            'iface_add_domain_record' => $this->config->get('interface', 'add_domain_record', false),
            'pdnssec_use' => $this->config->get('dnssec', 'enabled', false),
            'domain_value' => $domain_value,
            'zone_template_value' => $zone_template_value,
            'owner_value' => $owner_value,
            'dom_type_value' => $dom_type_value,
            'is_post' => $is_post_request,
            'dnssec_checked' => $dnssec_checked,
            // Don't pass raw POST data to the template for security
        ]);
    }
}
