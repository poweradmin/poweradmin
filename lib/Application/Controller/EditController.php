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
 *
 */

/**
 * Script that handles editing of zone records
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Poweradmin\Application\Presenter\PaginationPresenter;
use Poweradmin\Application\Service\DnssecProviderFactory;
use Poweradmin\Application\Service\PaginationService;
use Poweradmin\Application\Service\RecordCommentService;
use Poweradmin\Application\Service\RecordCommentSyncService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\RecordLog;
use Poweradmin\Domain\Model\RecordType;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Model\ZoneTemplate;
use Poweradmin\Domain\Model\ZoneType;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Domain\Service\Validator;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Infrastructure\Repository\DbRecordCommentRepository;
use Poweradmin\Infrastructure\Service\HttpPaginationParameters;

class EditController extends BaseController
{
    private RecordCommentService $recordCommentService;
    private RecordCommentSyncService $commentSyncService;

    public function __construct(array $request)
    {
        parent::__construct($request);
        $recordCommentRepository = new DbRecordCommentRepository($this->db, $this->getConfig());
        $this->recordCommentService = new RecordCommentService($recordCommentRepository);
        $this->commentSyncService = new RecordCommentSyncService($this->recordCommentService);
    }

    public function run(): void
    {
        $iface_rowamount = $this->config('iface_rowamount');
        $iface_zone_comments = $this->config('iface_zone_comments');
        $iface_show_id = $this->config('iface_edit_show_id');
        $iface_edit_add_record_top = $this->config('iface_edit_add_record_top');
        $iface_edit_save_changes_top = $this->config('iface_edit_save_changes_top');
        $iface_record_comments = $this->config('iface_record_comments');

        $row_start = 0;
        if (isset($_GET["start"])) {
            $row_start = ($_GET["start"] - 1) * $iface_rowamount;
        }

        $record_sort_by = $this->getSortBy('record_sort_by', ['id', 'name', 'type', 'content', 'prio', 'ttl', 'disabled']);
        $sort_direction = $this->getSortDirection('sort_direction');

        if (!isset($_GET['id']) || !Validator::is_number($_GET['id'])) {
            $this->showError(_('Invalid or unexpected input given.'));
        }
        $zone_id = intval(htmlspecialchars($_GET['id']));
        $dnsRecord = new DnsRecord($this->db, $this->getConfig());
        $zone_name = $dnsRecord->get_domain_name_by_id($zone_id);

        if (isset($_POST['commit'])) {
            $this->validateCsrfToken();
            $this->saveRecords($zone_id, $iface_zone_comments, $zone_name);
        }

        if (isset($_POST['save_as'])) {
            $this->validateCsrfToken();
            $this->saveAsTemplate($zone_id);
        }

        $perm_view = Permission::getViewPermission($this->db);
        $perm_edit = Permission::getEditPermission($this->db);

        if (UserManager::verify_permission($this->db, 'zone_meta_edit_others')) {
            $perm_meta_edit = "all";
        } elseif (UserManager::verify_permission($this->db, 'zone_meta_edit_own')) {
            $perm_meta_edit = "own";
        } else {
            $perm_meta_edit = "none";
        }

        $user_is_zone_owner = UserManager::verify_user_is_owner_zoneid($this->db, $zone_id);

        $meta_edit = $perm_meta_edit == "all" || ($perm_meta_edit == "own" && $user_is_zone_owner == "1");

        if (isset($_POST['slave_master_change']) && is_numeric($_POST["domain"])) {
            $this->validateCsrfToken();
            $dnsRecord->change_zone_slave_master($_POST['domain'], $_POST['new_master']);
        }

        $types = ZoneType::getTypes();

        $new_type = htmlspecialchars($_POST['newtype'] ?? '');
        if (isset($_POST['type_change']) && in_array($new_type, $types)) {
            $this->validateCsrfToken();
            $dnsRecord->change_zone_type($new_type, $zone_id);
        }

        if (isset($_POST["newowner"]) && is_numeric($_POST["domain"]) && is_numeric($_POST["newowner"])) {
            $this->validateCsrfToken();
            DnsRecord::add_owner_to_zone($this->db, $_POST["domain"], $_POST["newowner"]);
        }

        if (isset($_POST["delete_owner"]) && is_numeric($_POST["delete_owner"])) {
            $this->validateCsrfToken();
            DnsRecord::delete_owner_from_zone($this->db, $zone_id, $_POST["delete_owner"]);
        }

        if (isset($_POST["template_change"])) {
            $this->validateCsrfToken();
            if (!isset($_POST['zone_template']) || "none" == $_POST['zone_template']) {
                $new_zone_template = 0;
            } else {
                $new_zone_template = $_POST['zone_template'];
            }
            if ($_POST['current_zone_template'] != $new_zone_template) {
                $dnsRecord->update_zone_records($this->config('db_type'), $this->config('dns_ttl'), $zone_id, $new_zone_template);
            }
        }

        if ($perm_view == "none" || $perm_view == "own" && $user_is_zone_owner == "0") {
            $this->showError(_("You do not have the permission to view this zone."));
        }

        if ($dnsRecord->zone_id_exists($zone_id) == "0") {
            $this->showError(_('There is no zone with this ID.'));
        }

        if (isset($_POST['sign_zone'])) {
            $this->validateCsrfToken();
            $dnsRecord->update_soa_serial($zone_id);

            $dnssecProvider = DnssecProviderFactory::create($this->db, $this->getConfig());

            if ($dnssecProvider->isDnssecEnabled()) {
                $result = $dnssecProvider->secureZone($zone_name);

                if ($result) {
                    $this->setMessage('edit', 'success', _('Zone has been signed successfully.'));
                }
            } else {
                $this->setMessage('edit', 'error', _('DNSSEC is not enabled on the server.'));
            }

            $dnssecProvider->rectifyZone($zone_name);
        }

        if (isset($_POST['unsign_zone'])) {
            $this->validateCsrfToken();

            $dnssecProvider = DnssecProviderFactory::create($this->db, $this->getConfig());
            $dnssecProvider->unsecureZone($zone_name);

            $dnsRecord->update_soa_serial($zone_id);
            $this->setMessage('edit', 'success', _('Zone has been unsigned successfully.'));
        }

        $zone_templates = new ZoneTemplate($this->db, $this->getConfig());

        $domain_type = $dnsRecord->get_domain_type($zone_id);
        $record_count = $dnsRecord->count_zone_records($zone_id);
        $zone_templates = $zone_templates->get_list_zone_templ($_SESSION['userid']);
        $zone_template_id = DnsRecord::get_zone_template($this->db, $zone_id);
        $zone_template_details = ZoneTemplate::get_zone_templ_details($this->db, $zone_template_id);

        $slave_master = $dnsRecord->get_domain_slave_master($zone_id);

        $users = UserManager::show_users($this->db);

        $zone_comment = '';
        $raw_zone_comment = DnsRecord::get_zone_comment($this->db, $zone_id);
        if ($raw_zone_comment) {
            $zone_comment = htmlspecialchars($raw_zone_comment);
        }

        $zone_name_to_display = $dnsRecord->get_domain_name_by_id($zone_id);
        if (str_starts_with($zone_name_to_display, "xn--")) {
            $idn_zone_name = idn_to_utf8($zone_name_to_display, IDNA_NONTRANSITIONAL_TO_ASCII);
        } else {
            $idn_zone_name = "";
        }
        $records = $dnsRecord->get_records_from_domain_id($this->config('db_type'), $zone_id, $row_start, $iface_rowamount, $record_sort_by, $sort_direction, $iface_record_comments);
        $owners = DnsRecord::get_users_from_domain_id($this->db, $zone_id);

        $soa_record = $dnsRecord->get_soa_record($zone_id);

        $isDnsSecEnabled = $this->config('pdnssec_use');
        $dnssecProvider = DnssecProviderFactory::create($this->db, $this->getConfig());

        $isReverseZone = DnsHelper::isReverseZone($zone_name);

        $this->render('edit.html', [
            'zone_id' => $zone_id,
            'zone_name' => $zone_name,
            'zone_name_to_display' => $zone_name_to_display,
            'idn_zone_name' => $idn_zone_name,
            'zone_comment' => $zone_comment,
            'domain_type' => $domain_type,
            'record_count' => $record_count,
            'zone_templates' => $zone_templates,
            'zone_template_id' => $zone_template_id,
            'zone_template_details' => $zone_template_details,
            'slave_master' => $slave_master,
            'users' => $users,
            'owners' => $owners,
            'records' => $records,
            'perm_view' => $perm_view,
            'perm_edit' => $perm_edit,
            'perm_meta_edit' => $perm_meta_edit,
            'meta_edit' => $meta_edit,
            'perm_zone_master_add' => UserManager::verify_permission($this->db, 'zone_master_add'),
            'perm_view_others' => UserManager::verify_permission($this->db, 'user_view_others'),
            'user_is_zone_owner' => $user_is_zone_owner,
            'zone_types' => $types,
            'row_start' => $row_start,
            'row_amount' => $iface_rowamount,
            'record_sort_by' => $record_sort_by,
            'sort_direction' => $sort_direction,
            'pagination' => $this->createAndPresentPagination($record_count, $iface_rowamount, $zone_id),
            'pdnssec_use' => $isDnsSecEnabled,
            'is_secured' => $dnssecProvider->isZoneSecured($zone_name, $this->getConfig()),
            'session_userid' => $_SESSION["userid"],
            'dns_ttl' => $this->config('dns_ttl'),
            'is_reverse_zone' => $isReverseZone,
            'record_types' => $isReverseZone ? RecordType::getReverseZoneTypes($isDnsSecEnabled) : RecordType::getDomainZoneTypes($isDnsSecEnabled),
            'iface_add_reverse_record' => $this->config('iface_add_reverse_record'),
            'iface_add_domain_record' => $this->config('iface_add_domain_record'),
            'iface_zone_comments' => $this->config('iface_zone_comments'),
            'iface_edit_show_id' => $iface_show_id,
            'iface_edit_add_record_top' => $iface_edit_add_record_top,
            'iface_edit_save_changes_top' => $iface_edit_save_changes_top,
            'iface_record_comments' => $iface_record_comments,
            'serial' => DnsRecord::get_soa_serial($soa_record),
            'file_version' => time()
        ]);
    }

    private function createAndPresentPagination(int $totalItems, string $itemsPerPage, int $id): string
    {
        $httpParameters = new HttpPaginationParameters();
        $currentPage = $httpParameters->getCurrentPage();

        $paginationService = new PaginationService();
        $pagination = $paginationService->createPagination($totalItems, $itemsPerPage, $currentPage);
        $presenter = new PaginationPresenter($pagination, 'index.php?page=edit&start={PageNumber}', $id);

        return $presenter->present();
    }

    public function getSortBy(string $name, array $allowedValues): string
    {
        $sortOrder = 'name';

        foreach ([$_GET, $_POST, $_SESSION] as $source) {
            if (isset($source[$name]) && in_array($source[$name], $allowedValues)) {
                $sortOrder = $source[$name];
                $_SESSION[$name] = $source[$name];
                break;
            }
        }

        return $sortOrder;
    }


    private function getSortDirection(string $name): string
    {
        $sortDirection = 'ASC';

        foreach ([$_GET, $_POST, $_SESSION] as $source) {
            if (isset($source[$name]) && in_array($source[$name], ['ASC', 'DESC'])) {
                $sortDirection = $source[$name];
                $_SESSION[$name] = $source[$name];
                break;
            }
        }

        return $sortDirection;
    }

    public function saveRecords(int $zone_id, bool $iface_zone_comments, string $zone_name): void
    {
        $error = false;
        $one_record_changed = false;
        $serial_mismatch = false;

        $dnsRecord = new DnsRecord($this->db, $this->getConfig());

        if (isset($_POST['record'])) {
            $soa_record = $dnsRecord->get_soa_record($zone_id);
            $current_serial = DnsRecord::get_soa_serial($soa_record);

            if (isset($_POST['serial']) && $_POST['serial'] != $current_serial) {
                $serial_mismatch = true;
            } else {
                foreach ($_POST['record'] as $record) {
                    $log = new RecordLog($this->db, $this->getConfig());

                    if (isset($record['disabled']) && $record['disabled'] == 'on') {
                        $record["disabled"] = 1;
                    } else {
                        $record["disabled"] = 0;
                    }

                    $log->log_prior($record['rid'], $record['zid']);

                    if (!$log->has_changed($record)) {
                        continue;
                    } else {
                        $one_record_changed = true;
                    }

                    $edit_record = $dnsRecord->edit_record($record);
                    if (false === $edit_record) {
                        $error = true;
                    } else {
                        $log->log_after($record['rid']);
                        $log->write();

                        $recordCopy = $log->getRecordCopy();
                        $this->recordCommentService->updateComment(
                            $zone_id,
                            $recordCopy['name'],
                            $recordCopy['type'],
                            $record['name'],
                            $record['type'],
                            $record['comment'] ?? '',
                            $_SESSION['userlogin']
                        );

                        $this->commentSyncService->updateRelatedRecordComments(
                            $dnsRecord,
                            $record,
                            $record['comment'] ?? '',
                            $_SESSION['userlogin']
                        );
                    }
                }
            }
        }

        if ($iface_zone_comments) {
            $raw_zone_comment = DnsRecord::get_zone_comment($this->db, $zone_id);
            $zone_comment = $_POST['comment'] ?? '';
            if ($raw_zone_comment != $zone_comment) {
                $dnsRecord->edit_zone_comment($zone_id, $zone_comment);
                $one_record_changed = true;
            }
        }

        if ($error === false) {
            $experimental_edit_conflict_resolution = $this->config('experimental_edit_conflict_resolution');
            if ($serial_mismatch && $experimental_edit_conflict_resolution == 'only_latest_version') {
                $this->setMessage('edit', 'warn', (_('Request has expired, please try again.')));
            } else {
                $dnsRecord->update_soa_serial($zone_id);

                if ($one_record_changed) {
                    $this->setMessage('edit', 'success', _('Zone has been updated successfully.'));
                } else {
                    $this->setMessage('edit', 'warn', (_('Zone did not have any record changes.')));
                }

                if ($this->config('pdnssec_use')) {
                    $dnssecProvider = DnssecProviderFactory::create($this->db, $this->getConfig());
                    $dnssecProvider->rectifyZone($zone_name);
                }
            }
        } else {
            $this->setMessage('edit', 'error', _('Zone has not been updated successfully.'));
        }
    }

    public function saveAsTemplate(string $zone_id): void
    {
        $template_name = htmlspecialchars($_POST['templ_name']) ?? '';
        if (ZoneTemplate::zone_templ_name_exists($this->db, $template_name)) {
            $this->showError(_('Zone template with this name already exists, please choose another one.'));
        } elseif ($template_name == '') {
            $this->showError(_("Template name can't be an empty string."));
        } else {
            $dnsRecord = new DnsRecord($this->db, $this->getConfig());
            $records = $dnsRecord->get_records_from_domain_id($this->config('db_type'), $zone_id);

            $description = htmlspecialchars($_POST['templ_descr']) ?? '';

            $options = [
                'NS1' => $this->config('dns_ns1') ?? '',
                'HOSTMASTER' => $this->config('dns_hostmaster') ?? '',
            ];

            ZoneTemplate::add_zone_templ_save_as($this->db, $template_name, $description, $_SESSION['userid'], $records, $options, $dnsRecord->get_domain_name_by_id($zone_id));
            $this->setMessage('edit', 'success', _('Zone template has been added successfully.'));
        }
    }
}
