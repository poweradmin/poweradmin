<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2023 Poweradmin Development Team
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
 * Script that handles request to add new records to existing zone
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2023 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

use Poweradmin\Application\Dnssec\DnssecProviderFactory;
use Poweradmin\Application\Presenter\ErrorPresenter;
use Poweradmin\BaseController;
use Poweradmin\DnsRecord;
use Poweradmin\Domain\Error\ErrorMessage;
use Poweradmin\LegacyLogger;
use Poweradmin\LegacyUsers;
use Poweradmin\Permission;
use Poweradmin\RecordType;

require_once __DIR__ . '/vendor/autoload.php';

class AddRecordController extends BaseController
{
    private LegacyLogger $logger;

    public function __construct() {
        parent::__construct();

        $this->logger = new LegacyLogger($this->db);
    }

    public function run(): void
    {
        $this->checkId();

        $perm_edit = Permission::getEditPermission($this->db);
        $zone_id = htmlspecialchars($_GET['id']);
        $zone_type = DnsRecord::get_domain_type($this->db, $zone_id);
        $user_is_zone_owner = LegacyUsers::verify_user_is_owner_zoneid($this->db, $zone_id);

        $this->checkCondition($zone_type == "SLAVE"
            || $perm_edit == "none"
            || ($perm_edit == "own" || $perm_edit == "own_as_client")
            && !$user_is_zone_owner, _("You do not have the permission to add a record to this zone.")
        );

        if ($this->isPost()) {
            $this->addRecord();
        }
        $this->showForm();
    }

    private function addRecord(): void
    {
        $v = new Valitron\Validator($_POST);
        $v->rules([
            'required' => ['content', 'type', 'ttl'],
            'integer' => ['priority', 'ttl'],
        ]);

        if (!$v->validate()) {
            $this->showFirstError($v->errors());
        }

        $name = $_POST['name'] ?? '';
        $content = $_POST['content'];
        $type = $_POST['type'];
        $prio = $_POST['prio'];
        $ttl = $_POST['ttl'];
        $zone_id = htmlspecialchars($_GET['id']);

        $this->createReverseRecord($name, $type, $content, $zone_id, $ttl, $prio);

        if ($this->createRecord($zone_id, $name, $type, $content, $ttl, $prio)) {
            unset($_POST);
        }
    }

    private function showForm(): void
    {
        $zone_id = htmlspecialchars($_GET['id']);
        $zone_name = DnsRecord::get_domain_name_by_id($this->db, $zone_id);
        $ttl = $this->config('dns_ttl');
        $iface_add_reverse_record = $this->config('iface_add_reverse_record');
        $is_reverse_zone = preg_match('/i(p6|n-addr).arpa/i', $zone_name);

        if (preg_match("/^xn--/", $zone_name)) {
            $idn_zone_name = idn_to_utf8($zone_name, IDNA_NONTRANSITIONAL_TO_ASCII);
        } else {
            $idn_zone_name = "";
        }

        $this->render('add_record.html', [
            'types' => RecordType::getTypes(),
            'name' => $_POST['name'] ?? '',
            'type' => $_POST['type'] ?? '',
            'content' => $_POST['content'] ?? '',
            'ttl' => $_POST['ttl'] ?? $ttl,
            'prio' => $_POST['prio'] ?? 0,
            'zone_id' => $zone_id,
            'zone_name' => $zone_name,
            'idn_zone_name' => $idn_zone_name,
            'is_reverse_zone' => $is_reverse_zone,
            'iface_add_reverse_record' => $iface_add_reverse_record,
        ]);
    }

    public function checkId(): void
    {
        $v = new Valitron\Validator($_GET);
        $v->rules([
            'required' => ['id'],
            'integer' => ['id']
        ]);
        if (!$v->validate()) {
            $this->showFirstError($v->errors());
        }
    }

    public function createReverseRecord($name, $type, $content, string $zone_id, $ttl, $prio): void
    {
        $iface_add_reverse_record = $this->config('iface_add_reverse_record');

        if ((isset($_POST["reverse"])) && $name && $iface_add_reverse_record) {
            if ($type === 'A') {
                $content_array = preg_split("/\./", $content);
                $content_rev = sprintf("%d.%d.%d.%d.in-addr.arpa", $content_array[3], $content_array[2], $content_array[1], $content_array[0]);
                $zone_rev_id = DnsRecord::get_best_matching_zone_id_from_name($this->db, $content_rev);
            } elseif ($type === 'AAAA') {
                $content_rev = DnsRecord::convert_ipv6addr_to_ptrrec($content);
                $zone_rev_id = DnsRecord::get_best_matching_zone_id_from_name($this->db, $content_rev);
            }

            if (isset($zone_rev_id) && $zone_rev_id != -1) {
                $zone_name = DnsRecord::get_domain_name_by_id($this->db, $zone_id);
                $fqdn_name = sprintf("%s.%s", $name, $zone_name);
                $dnsRecord = new DnsRecord($this->db);
                if ($dnsRecord->add_record($this->db, $zone_rev_id, $content_rev, 'PTR', $fqdn_name, $ttl, $prio)) {
                    $this->logger->log_info(sprintf('client_ip:%s user:%s operation:add_record record_type:PTR record:%s content:%s ttl:%s priority:%s',
                        $_SERVER['REMOTE_ADDR'], $_SESSION["userlogin"],
                        $content_rev, $fqdn_name, $ttl, $prio), $zone_id);

                    if ($this->config('pdnssec_use')) {
                        $dnssecProvider = DnssecProviderFactory::create($this->db, $this->getConfig());
                        $dnssecProvider->rectifyZone($zone_name);
                    }
                }
            } elseif (isset($content_rev)) {
                $error = new ErrorMessage(sprintf(_('There is no matching reverse-zone for: %s.'), $content_rev));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
        }
    }

    public function createRecord(string $zone_id, $name, $type, $content, $ttl, $prio): bool
    {
        $zone_name = DnsRecord::get_domain_name_by_id($this->db, $zone_id);

        $dnsRecord = new DnsRecord($this->db);
        if ($dnsRecord->add_record($this->db, $zone_id, $name, $type, $content, $ttl, $prio)) {
            $this->logger->log_info(sprintf('client_ip:%s user:%s operation:add_record record_type:%s record:%s.%s content:%s ttl:%s priority:%s',
                $_SERVER['REMOTE_ADDR'], $_SESSION["userlogin"],
                $type, $name, $zone_name, $content, $ttl, $prio), $zone_id
            );

            if ($this->config('pdnssec_use')) {
                $dnssecProvider = DnssecProviderFactory::create($this->db, $this->getConfig());
                $dnssecProvider->rectifyZone($zone_name);
            }

            $this->setMessage('add_record', 'success', _('The record was successfully added.'));
            return true;
        } else {
            $this->setMessage('add_record', 'error', _('This record was not valid and could not be added.'));
            return false;
        }
    }
}

$controller = new AddRecordController();
$controller->run();
