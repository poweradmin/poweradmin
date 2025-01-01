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
 * Script that handles requests to edit zone records
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Poweradmin\Application\Presenter\ErrorPresenter;
use Poweradmin\Application\Service\DnssecProviderFactory;
use Poweradmin\Application\Service\RecordCommentService;
use Poweradmin\Application\Service\RecordCommentSyncService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Error\ErrorMessage;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\RecordType;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Poweradmin\Infrastructure\Repository\DbRecordCommentRepository;

class EditRecordController extends BaseController
{

    private LegacyLogger $logger;
    private RecordCommentService $recordCommentService;
    private RecordCommentSyncService $commentSyncService;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $this->logger = new LegacyLogger($this->db);
        $recordCommentRepository = new DbRecordCommentRepository($this->db, $this->getConfig());
        $this->recordCommentService = new RecordCommentService($recordCommentRepository);
        $this->commentSyncService = new RecordCommentSyncService($this->recordCommentService);
    }

    public function run(): void
    {
        $perm_view = Permission::getViewPermission($this->db);
        $perm_edit = Permission::getEditPermission($this->db);

        $record_id = $_GET['id'];
        $dnsRecord = new DnsRecord($this->db, $this->getConfig());
        $zid = $dnsRecord->get_zone_id_from_record_id($record_id);

        $user_is_zone_owner = UserManager::verify_user_is_owner_zoneid($this->db, $zid);

        $dnsRecord = new DnsRecord($this->db, $this->getConfig());
        $zone_type = $dnsRecord->get_domain_type($zid);

        if ($perm_view == "none" || $perm_view == "own" && $user_is_zone_owner == "0") {
            $this->showError(_("You do not have the permission to view this record."));
        }

        if ($zone_type == "SLAVE" || $perm_edit == "none" || ($perm_edit == "own" || $perm_edit == "own_as_client") && $user_is_zone_owner == "0") {
            $error = new ErrorMessage(_("You do not have the permission to edit this record."));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);
        }

        if ($this->isPost()) {
            $this->validateCsrfToken();
            $this->saveRecord($zid);
        }

        $this->showRecordEditForm($record_id, $zone_type, $zid, $perm_edit, $user_is_zone_owner);
    }

    public function showRecordEditForm($record_id, string $zone_type, $zid, string $perm_edit, $user_is_zone_owner): void
    {
        $dnsRecord = new DnsRecord($this->db, $this->getConfig());
        $zone_name = $dnsRecord->get_domain_name_by_id($zid);

        $recordTypes = RecordType::getAllTypes();
        $record = $dnsRecord->get_record_from_id($record_id);
        $record['record_name'] = trim(str_replace(htmlspecialchars($zone_name), '', htmlspecialchars($record["name"])), '.');

        if (str_starts_with($zone_name, "xn--")) {
            $idn_zone_name = idn_to_utf8($zone_name, IDNA_NONTRANSITIONAL_TO_ASCII);
        } else {
            $idn_zone_name = "";
        }

        $iface_record_comments = $this->config('iface_record_comments');
        $recordComment = $this->recordCommentService->findComment($zid, $record['name'], $record['type']);

        $this->render('edit_record.html', [
            'record_id' => $record_id,
            'record' => $record,
            'recordTypes' => $recordTypes,
            'zone_name' => $zone_name,
            'idn_zone_name' => $idn_zone_name,
            'zone_type' => $zone_type,
            'zid' => $zid,
            'perm_edit' => $perm_edit,
            'user_is_zone_owner' => $user_is_zone_owner,
            'iface_record_comments' => $iface_record_comments,
            'comment' => $recordComment ? $recordComment->getComment() : '',
        ]);
    }

    public function saveRecord($zid): void
    {
        $dnsRecord = new DnsRecord($this->db, $this->getConfig());
        $old_record_info = $dnsRecord->get_record_from_id($_POST["rid"]);

        $postData = $_POST;
        if (isset($postData['disabled']) && $postData['disabled'] == "on") {
            $postData['disabled'] = 1;
        } else {
            $postData['disabled'] = 0;
        }

        $ret_val = $dnsRecord->edit_record($postData);
        if (!$ret_val) {
            return;
        }

        $dnsRecord->update_soa_serial($zid);

        $new_record_info = $dnsRecord->get_record_from_id($_POST["rid"]);
        $this->logger->log_info(sprintf('client_ip:%s user:%s operation:edit_record'
            . ' old_record_type:%s old_record:%s old_content:%s old_ttl:%s old_priority:%s'
            . ' record_type:%s record:%s content:%s ttl:%s priority:%s',
            $_SERVER['REMOTE_ADDR'], $_SESSION["userlogin"],
            $old_record_info['type'], $old_record_info['name'], $old_record_info['content'], $old_record_info['ttl'], $old_record_info['prio'],
            $new_record_info['type'], $new_record_info['name'], $new_record_info['content'], $new_record_info['ttl'], $new_record_info['prio']),
            $zid);

        $this->recordCommentService->updateComment(
            $zid,
            $old_record_info['name'],
            $old_record_info['type'],
            $new_record_info['name'],
            $new_record_info['type'],
            $_POST['comment'],
            $_SESSION['userlogin']
        );

        $this->commentSyncService->updateRelatedRecordComments(
            $dnsRecord,
            $new_record_info,
            $_POST['comment'],
            $_SESSION['userlogin']
        );

        if ($this->config('pdnssec_use')) {
            $zone_name = $dnsRecord->get_domain_name_by_id($zid);
            $dnssecProvider = DnssecProviderFactory::create($this->db, $this->getConfig());
            $dnssecProvider->rectifyZone($zone_name);
        }

        $this->setMessage('edit', 'success', _('The record has been updated successfully.'));
        $this->redirect('index.php', ['page' => 'edit', 'id' => $zid]);
    }
}
