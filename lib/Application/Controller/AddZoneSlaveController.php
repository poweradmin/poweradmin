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

use Poweradmin\BaseController;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Service\Dns;
use Poweradmin\Domain\Service\DnsIdnService;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Domain\Service\DnsValidation\IPAddressValidator;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Symfony\Component\Validator\Constraints as Assert;

class AddZoneSlaveController extends BaseController
{
    private LegacyLogger $logger;
    private IPAddressValidator $ipAddressValidator;

    public function __construct(array $request)
    {
        parent::__construct($request);
        $this->logger = new LegacyLogger($this->db);
        $this->ipAddressValidator = new IPAddressValidator();
    }

    public function run(): void
    {
        $this->checkPermission('zone_slave_add', _("You do not have the permission to add a slave zone."));

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
            'owner' => [
                new Assert\NotBlank(),
                new Assert\Type('numeric')
            ],
            'domain' => [
                new Assert\NotBlank()
            ],
            'slave_master' => [
                new Assert\NotBlank()
            ]
        ];

        $this->setValidationConstraints($constraints);

        if (!$this->doValidateRequest($_POST)) {
            $this->showFirstValidationError($_POST);
        }

        $dns_third_level_check = $this->config->get('dns', 'third_level_check', false);

        $type = "SLAVE";
        $owner = $_POST['owner'];
        $master = $_POST['slave_master'];
        $zone = DnsIdnService::toPunycode(trim($_POST['domain']));

        $dns = new Dns($this->db, $this->getConfig());
        $dnsRecord = new DnsRecord($this->db, $this->getConfig());
        $hostnameValidator = new HostnameValidator($this->config);
        if (!$hostnameValidator->isValidHostnameFqdn($zone, 0)) {
            $this->setMessage('add_zone_slave', 'error', _('Invalid hostname.'));
            $this->showForm();
        } elseif ($dns_third_level_check && DnsRecord::get_domain_level($zone) > 2 && $dnsRecord->domain_exists(DnsRecord::get_second_level_domain($zone))) {
            $this->setMessage('add_zone_slave', 'error', _('There is already a zone with this name.'));
            $this->showForm();
        } elseif ($dnsRecord->domain_exists($zone) || $dnsRecord->record_name_exists($zone)) {
            $this->setMessage('add_zone_slave', 'error', _('There is already a zone with this name.'));
            $this->showForm();
        } elseif (!$this->ipAddressValidator->areMultipleValidIPs($master)) {
            $this->setMessage('add_zone_slave', 'error', _('This is not a valid IPv4 or IPv6 address.'));
            $this->showForm();
        } else {
            $dnsRecord = new DnsRecord($this->db, $this->getConfig());
            if ($dnsRecord->add_domain($this->db, $zone, $owner, $type, $master, 'none')) {
                $zone_id = $dnsRecord->get_zone_id_from_name($zone);
                $this->logger->log_info(sprintf(
                    'client_ip:%s user:%s operation:add_zone zone:%s zone_type:SLAVE zone_master:%s',
                    $_SERVER['REMOTE_ADDR'],
                    $_SESSION["userlogin"],
                    $zone,
                    $master
                ), $zone_id);

                // Check if the zone is a reverse zone and redirect accordingly
                if (DnsHelper::isReverseZone($zone)) {
                    $this->setMessage('list_reverse_zones', 'success', _('Zone has been added successfully.'));
                    $this->redirect('index.php', ['page' => 'list_reverse_zones']);
                } else {
                    $this->setMessage('list_forward_zones', 'success', _('Zone has been added successfully.'));
                    $this->redirect('index.php', ['page' => 'list_forward_zones']);
                }
            }
        }
    }

    private function showForm(): void
    {
        // Keep the submitted values if there was an error
        $domain_value = isset($_POST['domain']) ? htmlspecialchars($_POST['domain']) : '';
        $slave_master_value = isset($_POST['slave_master']) ? htmlspecialchars($_POST['slave_master']) : '';

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

        $is_post_request = !empty($_POST);

        $this->render('add_zone_slave.html', [
            'users' => UserManager::show_users($this->db),
            'session_user_id' => $_SESSION['userid'],
            'perm_view_others' => UserManager::verify_permission($this->db, 'user_view_others'),
            'domain_value' => $domain_value,
            'slave_master_value' => $slave_master_value,
            'owner_value' => $owner_value,
            'is_post' => $is_post_request,
            // Don't pass raw POST data to the template for security
        ]);
    }
}
