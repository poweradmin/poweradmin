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
 * Script that handles record deletions from zones
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
use Poweradmin\Domain\Service\Validator;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Poweradmin\Infrastructure\Repository\DbRecordCommentRepository;

class DeleteRecordController extends BaseController
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
        if (!isset($_GET['id']) || !Validator::is_number($_GET['id'])) {
            $this->showError(_('Invalid or unexpected input given.'));
        }

        $record_id = htmlspecialchars($_GET['id']);
        $dnsRecord = new DnsRecord($this->db, $this->getConfig());

        $zid = $dnsRecord->get_zone_id_from_record_id($record_id);
        if ($zid == NULL) {
            $this->showError(_('There is no zone with this ID.'));
        }

        $domain_id = $dnsRecord->recid_to_domid($record_id);

        if (isset($_GET['confirm'])) {
            $record_info = $dnsRecord->get_record_from_id($record_id);
            if ($dnsRecord->delete_record($record_id)) {
                if (isset($record_info['prio'])) {
                    $this->logger->log_info(sprintf('client_ip:%s user:%s operation:delete_record record_type:%s record:%s content:%s ttl:%s priority:%s',
                        $_SERVER['REMOTE_ADDR'], $_SESSION["userlogin"],
                        $record_info['type'], $record_info['name'], $record_info['content'], $record_info['ttl'], $record_info['prio']), $zid);
                } else {
                    $this->logger->log_info(sprintf('client_ip:%s user:%s operation:delete_record record_type:%s record:%s content:%s ttl:%s',
                        $_SERVER['REMOTE_ADDR'], $_SESSION["userlogin"],
                        $record_info['type'], $record_info['name'], $record_info['content'], $record_info['ttl']), $zid);
                }

                DnsRecord::delete_record_zone_templ($this->db, $record_id);
                $dnsRecord = new DnsRecord($this->db, $this->getConfig());
                $dnsRecord->update_soa_serial($zid);

                if ($this->config('pdnssec_use')) {
                    $zone_name = $dnsRecord->get_domain_name_by_id($zid);
                    $dnssecProvider = DnssecProviderFactory::create($this->db, $this->getConfig());
                    $dnssecProvider->rectifyZone($zone_name);
                }

                if (!$dnsRecord->has_similar_records($domain_id, $record_info['name'], $record_info['type'], $record_id)) {
                    $this->recordCommentService->deleteComment($domain_id, $record_info['name'], $record_info['type']);
                    $this->setMessage('edit', 'success', _('The record has been deleted successfully.'));
                } else if ($this->config('comments_enabled')) {
                    $this->setMessage('edit', 'warn', _('The record was deleted but the comment was preserved because similar records exist.'));
                }

                $this->redirect('index.php', ['page'=> 'edit', 'id' => $zid]);
            }
        }

        $perm_edit = Permission::getEditPermission($this->db);

        $dnsRecord = new DnsRecord($this->db, $this->getConfig());
        $zone_info = $dnsRecord->get_zone_info_from_id($zid);
        $user_is_zone_owner = UserManager::verify_user_is_owner_zoneid($this->db, $domain_id);
        if ($zone_info['type'] == "SLAVE" || $perm_edit == "none" || ($perm_edit == "own" || $perm_edit == "own_as_client") && $user_is_zone_owner == "0") {
            $this->showError(_("You do not have the permission to edit this record."));
        }

        $this->showQuestion($record_id, $zid, $domain_id);
    }

    public function showQuestion(string $record_id, $zid, int $zone_id): void
    {
        $dnsRecord = new DnsRecord($this->db, $this->getConfig());
        $zone_name = $dnsRecord->get_domain_name_by_id($zone_id);

        if (str_starts_with($zone_name, "xn--")) {
            $idn_zone_name = idn_to_utf8($zone_name, IDNA_NONTRANSITIONAL_TO_ASCII);
        } else {
            $idn_zone_name = "";
        }

        $this->render('delete_record.html', [
            'record_id' => $record_id,
            'zone_id' => $zid,
            'zone_name' => $zone_name,
            'idn_zone_name' => $idn_zone_name,
            'record_info' => $dnsRecord->get_record_from_id($record_id),
        ]);
    }
}
