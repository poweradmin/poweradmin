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

namespace Poweradmin\Application\Controller;

use Poweradmin\BaseController;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Service\BatchReverseRecordCreator;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Domain\Service\ReverseRecordCreator;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Valitron;

class BatchPtrRecordController extends BaseController
{
    private LegacyLogger $logger;
    private DnsRecord $dnsRecord;
    private BatchReverseRecordCreator $batchReverseRecordCreator;
    private $csrfTokenService;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $this->logger = new LegacyLogger($this->db);
        $this->dnsRecord = new DnsRecord($this->db, $this->getConfig());
        $this->csrfTokenService = new \Poweradmin\Application\Service\CsrfTokenService();

        $reverseRecordCreator = new ReverseRecordCreator(
            $this->db,
            $this->getConfig(),
            $this->logger,
            $this->dnsRecord
        );

        $this->batchReverseRecordCreator = new BatchReverseRecordCreator(
            $this->db,
            $this->getConfig(),
            $this->logger,
            $this->dnsRecord,
            $reverseRecordCreator
        );
    }

    public function run(): void
    {
        $this->checkId();

        $perm_edit = Permission::getEditPermission($this->db);
        $zone_id = htmlspecialchars($_GET['id']);
        $zone_type = $this->dnsRecord->get_domain_type($zone_id);
        $user_is_zone_owner = UserManager::verify_user_is_owner_zoneid($this->db, $zone_id);

        $this->checkCondition($zone_type == "SLAVE"
            || $perm_edit == "none"
            || ($perm_edit == "own" || $perm_edit == "own_as_client")
            && !$user_is_zone_owner, _("You do not have the permission to add records to this zone."));

        if ($this->isPost()) {
            try {
                $this->validateCsrfToken();
                $this->addBatchPtrRecords();
            } catch (\Exception $e) {
                $this->setMessage('batch_ptr_record', 'error', $e->getMessage());
            }
        }
        
        $this->showForm();
    }

    private function addBatchPtrRecords(): void
    {
        $v = new Valitron\Validator($_POST);
        $v->rules([
            'required' => ['network_type', 'network_prefix', 'host_prefix', 'domain', 'ttl'],
            'integer' => ['priority', 'ttl'],
        ]);

        if (!$v->validate()) {
            $this->showFirstError($v->errors());
            return;
        }
        
        $networkType = $_POST['network_type'] ?? '';
        $networkPrefix = $_POST['network_prefix'] ?? '';
        $hostPrefix = $_POST['host_prefix'] ?? '';
        $domain = $_POST['domain'] ?? '';
        $ttl = isset($_POST['ttl']) ? (int)$_POST['ttl'] : 0;
        $prio = (int)($_POST['priority'] ?? 0);
        $comment = $_POST['comment'] ?? '';
        $zone_id = (int)$_GET['id'];
        $ipv6_count = isset($_POST['ipv6_count']) ? (int)$_POST['ipv6_count'] : 256;
        
        try {
            if ($networkType === 'ipv4') {
                $result = $this->batchReverseRecordCreator->createIPv4Network(
                    $networkPrefix,
                    $hostPrefix,
                    $domain,
                    (string)$zone_id,
                    $ttl,
                    $prio,
                    $comment,
                    $_SESSION['userlogin'] ?? ''
                );
            } else { // IPv6
                $result = $this->batchReverseRecordCreator->createIPv6Network(
                    $networkPrefix,
                    $hostPrefix,
                    $domain,
                    (string)$zone_id,
                    $ttl,
                    $prio,
                    $comment,
                    $_SESSION['userlogin'] ?? '',
                    $ipv6_count
                );
            }

            if ($result['success']) {
                $this->setMessage('batch_ptr_record', 'success', $result['message']);
            } else {
                $this->setMessage('batch_ptr_record', 'error', $result['message']);
            }
        } catch (\Exception $e) {
            $this->setMessage('batch_ptr_record', 'error', $e->getMessage());
        }

        unset($_POST);
    }

    private function showForm(): void
    {
        $zone_id = htmlspecialchars($_GET['id']);
        $zone_name = $this->dnsRecord->get_domain_name_by_id($zone_id);
        $isReverseZone = DnsHelper::isReverseZone($zone_name);

        $ttl = $this->config('dns', 'ttl', 86400);
        $file_version = time();

        if (str_starts_with($zone_name, "xn--")) {
            $idn_zone_name = idn_to_utf8($zone_name, IDNA_NONTRANSITIONAL_TO_ASCII);
        } else {
            $idn_zone_name = "";
        }

        $this->render('batch_ptr_record.html', [
            'network_type' => $_POST['network_type'] ?? 'ipv4',
            'network_prefix' => $_POST['network_prefix'] ?? '',
            'host_prefix' => $_POST['host_prefix'] ?? 'host',
            'domain' => $_POST['domain'] ?? '',
            'ttl' => $_POST['ttl'] ?? $ttl,
            'priority' => $_POST['priority'] ?? 0,
            'ipv6_count' => $_POST['ipv6_count'] ?? 256,
            'zone_id' => $zone_id,
            'zone_name' => $zone_name,
            'idn_zone_name' => $idn_zone_name,
            'is_reverse_zone' => $isReverseZone,
            'file_version' => $file_version,
            'iface_record_comments' => $this->config('interface', 'show_record_comments', false),
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
}
