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
 * Script that handles zone deletion
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Poweradmin\Application\Service\DnssecProviderFactory;
use Poweradmin\Application\Service\RecordCommentService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Poweradmin\Infrastructure\Repository\DbRecordCommentRepository;
use Valitron;

class DeleteDomainController extends BaseController
{

    private LegacyLogger $logger;
    private RecordCommentService $recordCommentService;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $this->logger = new LegacyLogger($this->db);
        $recordCommentRepository = new DbRecordCommentRepository($this->db, $this->getConfig());
        $this->recordCommentService = new RecordCommentService($recordCommentRepository);
    }

    public function run(): void
    {
        $v = new Valitron\Validator($_GET);
        $v->rules([
            'required' => ['id'],
            'integer' => ['id'],
        ]);
        if (!$v->validate()) {
            $this->showFirstError($v->errors());
        }

        $zone_id = htmlspecialchars($_GET['id']);

        $perm_edit = Permission::getEditPermission($this->db);
        $user_is_zone_owner = UserManager::verify_user_is_owner_zoneid($this->db, $zone_id);
        $this->checkCondition($perm_edit != "all" && ($perm_edit != "own" || !$user_is_zone_owner), _("You do not have the permission to delete a zone."));

        if (isset($_GET['confirm'])) {
            $this->deleteDomain($zone_id);
        } else {
            $this->showDeleteDomain($zone_id);
        }
    }

    private function deleteDomain(string $zone_id): void
    {
        $dnsRecord = new DnsRecord($this->db, $this->getConfig());
        $zone_info = $dnsRecord->get_zone_info_from_id($zone_id);
        $pdnssec_use = $this->config('pdnssec_use');

        if ($pdnssec_use && $zone_info['type'] == 'MASTER') {
            $zone_name = $dnsRecord->get_domain_name_by_id($zone_id);

            $dnssecProvider = DnssecProviderFactory::create($this->db, $this->getConfig());
            if ($dnssecProvider->isZoneSecured($zone_name, $this->getConfig())) {
                $dnssecProvider->unsecureZone($zone_name);
            }
        }

        if ($dnsRecord->delete_domain($zone_id)) {
            $this->logger->log_info(sprintf('client_ip:%s user:%s operation:delete_zone zone:%s zone_type:%s',
                $_SERVER['REMOTE_ADDR'], $_SESSION["userlogin"],
                $zone_info['name'], $zone_info['type']), $zone_id);

            $this->recordCommentService->deleteCommentsByDomainId($zone_id);

            $this->setMessage('list_zones', 'success', _('Zone has been deleted successfully.'));
            $this->redirect('index.php', ['page'=> 'list_zones']);
        }
    }

    private function showDeleteDomain(string $zone_id): void
    {
        $dnsRecord = new DnsRecord($this->db, $this->getConfig());
        $zone_info = $dnsRecord->get_zone_info_from_id($zone_id);
        $zone_owners = UserManager::get_fullnames_owners_from_domainid($this->db, $zone_id);

        $slave_master_exists = false;
        if ($zone_info['type'] == 'SLAVE') {
            $dnsRecord = new DnsRecord($this->db, $this->getConfig());
            $slave_master = $dnsRecord->get_domain_slave_master($zone_id);
            if ($dnsRecord->supermaster_exists($slave_master)) {
                $slave_master_exists = true;
            }
        }

        if (str_starts_with($zone_info['name'], "xn--")) {
            $idn_zone_name = idn_to_utf8($zone_info['name'], IDNA_NONTRANSITIONAL_TO_ASCII);
        } else {
            $idn_zone_name = "";
        }

        $this->render('delete_domain.html', [
            'zone_id' => $zone_id,
            'zone_info' => $zone_info,
            'idn_zone_name' => $idn_zone_name,
            'zone_owners' => $zone_owners,
            'slave_master_exists' => $slave_master_exists,
        ]);
    }
}
