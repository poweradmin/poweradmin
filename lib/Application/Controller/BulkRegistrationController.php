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
 * Script that handles bulk zone registration
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Poweradmin\BaseController;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Model\ZoneTemplate;
use Poweradmin\Domain\Service\Dns;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Domain\Utility\DomainHelper;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Valitron;

class BulkRegistrationController extends BaseController
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
            $this->doBulkRegistration();
        } else {
            $this->showBulkRegistrationForm();
        }
    }

    private function doBulkRegistration(): void
    {
        $v = new Valitron\Validator($_POST);
        $v->rules([
            'required' => ['owner', 'dom_type', 'zone_template', 'domains'],
            'integer' => ['owner'],
        ]);

        if (!$v->validate()) {
            $this->showFirstError($v->errors());
        }

        $domains = DomainHelper::getDomains($_POST['domains']);
        $dom_type = $_POST['dom_type'];
        $zone_template = $_POST['zone_template'];
        $owner = (int)$_POST['owner'];

        $failed_domains = [];
        $dnsRecord = new DnsRecord($this->db, $this->getConfig());
        $dns = new Dns($this->db, $this->getConfig());
        foreach ($domains as $domain) {
            if (!$dns->is_valid_hostname_fqdn($domain, 0)) {
                $failed_domains[] = $domain . " - " . _('Invalid hostname.');
            } elseif ($dnsRecord->domain_exists($domain)) {
                $failed_domains[] = $domain . " - " . _('There is already a zone with this name.');
            } elseif ($dnsRecord->add_domain($this->db, $domain, $owner, $dom_type, '', $zone_template)) {
                $zone_id = $dnsRecord->get_zone_id_from_name($domain);
                $this->logger->log_info(sprintf('client_ip:%s user:%s operation:add_zone zone:%s zone_type:%s zone_template:%s',
                    $_SERVER['REMOTE_ADDR'], $_SESSION["userlogin"],
                    $domain, $dom_type, $zone_template), $zone_id);
            }
        }

        if (!$failed_domains) {
            $this->setMessage('list_zones', 'success', _('Zones has been added successfully.'));
            $this->redirect('index.php', ['page'=> 'list_zones']);
        } else {
            $this->setMessage('bulk_registration', 'warn', _('Some zone(s) could not be added.'));
            $this->showBulkRegistrationForm(array_unique($failed_domains));
        }
    }

    private function showBulkRegistrationForm(array $failed_domains = []): void
    {
        $zone_templates = new ZoneTemplate($this->db, $this->getConfig());

        $this->render('bulk_registration.html', [
            'userid' => $_SESSION['userid'],
            'perm_view_others' => UserManager::verify_permission($this->db, 'user_view_others'),
            'iface_zone_type_default' => $this->config('iface_zone_type_default'),
            'available_zone_types' => array("MASTER", "NATIVE"),
            'users' => UserManager::show_users($this->db),
            'zone_templates' => $zone_templates->get_list_zone_templ($_SESSION['userid']),
            'failed_domains' => $failed_domains,
        ]);
    }
}
