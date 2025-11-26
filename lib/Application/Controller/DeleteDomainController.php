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
use Poweradmin\Domain\Service\DnsIdnService;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Poweradmin\Infrastructure\Repository\DbRecordCommentRepository;
use Symfony\Component\Validator\Constraints as Assert;

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
        $constraints = [
            'id' => [
                new Assert\NotBlank(),
                new Assert\Type('numeric')
            ]
        ];

        $this->setValidationConstraints($constraints);

        if (!$this->doValidateRequest($this->getRequest())) {
            $this->showFirstValidationError($this->getRequest());
        }

        $zone_id = (int)$this->getSafeRequestValue('id');

        $perm_delete = Permission::getDeletePermission($this->db);
        $user_is_zone_owner = UserManager::verifyUserIsOwnerZoneId($this->db, $zone_id);
        $this->checkCondition($perm_delete != "all" && ($perm_delete != "own" || !$user_is_zone_owner), _("You do not have the permission to delete a zone."));

        if ($this->isPost()) {
            $this->validateCsrfToken();
            $this->deleteDomain($zone_id);
        } else {
            $this->showDeleteDomain($zone_id);
        }
    }

    private function deleteDomain(int $zone_id): void
    {
        $dnsRecord = new DnsRecord($this->db, $this->getConfig());
        $zone_info = $dnsRecord->getZoneInfoFromId($zone_id);
        $pdnssec_use = $this->config->get('dnssec', 'enabled', false);

        if ($pdnssec_use && $zone_info['type'] == 'MASTER') {
            $zone_name = $dnsRecord->getDomainNameById($zone_id);

            $dnssecProvider = DnssecProviderFactory::create($this->db, $this->getConfig());
            if ($dnssecProvider->isZoneSecured($zone_name, $this->config)) {
                $dnssecProvider->unsecureZone($zone_name);
            }
        }

        if ($dnsRecord->deleteDomain($zone_id)) {
            $this->logger->logInfo(sprintf(
                'client_ip:%s user:%s operation:delete_zone zone:%s zone_type:%s',
                $_SERVER['REMOTE_ADDR'],
                $_SESSION["userlogin"],
                $zone_info['name'],
                $zone_info['type']
            ), $zone_id);

            $this->recordCommentService->deleteCommentsByDomainId($zone_id);

            // Check if the zone is a reverse zone and redirect accordingly
            if (DnsHelper::isReverseZone($zone_info['name'])) {
                $this->setMessage('list_reverse_zones', 'success', _('Zone has been deleted successfully.'));
                $this->redirect('/zones/reverse');
            } else {
                $this->setMessage('list_forward_zones', 'success', _('Zone has been deleted successfully.'));
                $this->redirect('/zones/forward');
            }
        }
    }

    private function showDeleteDomain(int $zone_id): void
    {
        $dnsRecord = new DnsRecord($this->db, $this->getConfig());
        $zone_info = $dnsRecord->getZoneInfoFromId($zone_id);
        $zone_owners = UserManager::getFullnamesOwnersFromFomainId($this->db, $zone_id);

        $slave_master_exists = false;
        if ($zone_info['type'] == 'SLAVE') {
            $dnsRecord = new DnsRecord($this->db, $this->getConfig());
            $slave_master = $dnsRecord->getDomainSlaveMaster($zone_id);
            if ($dnsRecord->supermasterExists($slave_master)) {
                $slave_master_exists = true;
            }
        }

        if (str_starts_with($zone_info['name'], "xn--")) {
            $idn_zone_name = DnsIdnService::toUtf8($zone_info['name']);
        } else {
            $idn_zone_name = "";
        }

        $this->render('delete_domain.html', [
            'zone_id' => $zone_id,
            'zone_info' => $zone_info,
            'idn_zone_name' => $idn_zone_name,
            'zone_owners' => $zone_owners,
            'slave_master_exists' => $slave_master_exists,
            'is_reverse_zone' => DnsHelper::isReverseZone($zone_info['name']),
        ]);
    }
}
