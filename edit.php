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
 *
 */

/**
 * Script that handles editing of zone records
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2023 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

use Poweradmin\Application\Dnssec\DnssecProviderFactory;
use Poweradmin\Application\Presenter\PaginationPresenter;
use Poweradmin\Application\Service\PaginationService;
use Poweradmin\BaseController;
use Poweradmin\DnsRecord;
use Poweradmin\Domain\Enum\ZoneType;
use Poweradmin\Infrastructure\Web\HttpPaginationParameters;
use Poweradmin\LegacyUsers;
use Poweradmin\Permission;
use Poweradmin\RecordLog;
use Poweradmin\RecordType;
use Poweradmin\Validation;
use Poweradmin\ZoneTemplate;

require_once __DIR__ . '/vendor/autoload.php';

class EditController extends BaseController {

    public function run(): void
    {
        $iface_rowamount = $this->config('iface_rowamount');
        $iface_zone_comments = $this->config('iface_zone_comments');

        $row_start = 0;
        if (isset($_GET["start"])) {
            $row_start = ($_GET["start"] - 1) * $iface_rowamount;
        }

        $record_sort_by = $this->getSortBy();

        if (!isset($_GET['id']) || !Validation::is_number($_GET['id'])) {
            $this->showError(_('Invalid or unexpected input given.'));
        }
        $zone_id = htmlspecialchars($_GET['id']);
        $zone_name = DnsRecord::get_domain_name_by_id($this->db, $zone_id);

        if (isset($_POST['commit'])) {
            $error = false;
            $one_record_changed = false;
            $serial_mismatch = false;

            if (isset($_POST['record'])) {
                $soa_record = DnsRecord::get_soa_record($this->db, $zone_id);
                $current_serial = DnsRecord::get_soa_serial($soa_record);

                if (isset($_POST['serial']) && $_POST['serial'] != $current_serial) {
                    $serial_mismatch = true;
                } else {
                    foreach ($_POST['record'] as $record) {
                        $log = new RecordLog($this->db);
                        $log->log_prior($record['rid'], $record['zid']);

                        if (!$log->has_changed($record)) {
                            continue;
                        } else {
                            $one_record_changed = true;
                        }

                        $dnsRecord = new DnsRecord($this->db, $this->getConfig());
                        $edit_record = $dnsRecord->edit_record($record);
                        if (false === $edit_record) {
                            $error = true;
                        } else {
                            $log->log_after($record['rid']);
                            $log->write();
                        }
                    }
                }
            }

            if ($iface_zone_comments) {
                $raw_zone_comment = DnsRecord::get_zone_comment($this->db, $zone_id);
                $zone_comment = $_POST['comment'] ?? '';
                if ($raw_zone_comment != $zone_comment) {
                    DnsRecord::edit_zone_comment($this->db, $zone_id, $zone_comment);
                    $one_record_changed = true;
                }
            }

            if ($error === false) {
                $experimental_edit_conflict_resolution = $this->config('experimental_edit_conflict_resolution');
                if ($serial_mismatch && $experimental_edit_conflict_resolution == 'only_latest_version') {
                    $this->setMessage('edit', 'warn', (_('Request has expired, please try again.')));
                } else {
                    $dnsRecord = new DnsRecord($this->db, $this->getConfig());
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

        if (isset($_POST['save_as'])) {
            if (ZoneTemplate::zone_templ_name_exists($this->db, $_POST['templ_name'])) {
                $this->showError(_('Zone template with this name already exists, please choose another one.'));
            } elseif ($_POST['templ_name'] == '') {
                $this->showError(_('Template name can\'t be an empty string.'));
            } else {
                $records = DnsRecord::get_records_from_domain_id($this->db, $this->config('db_type'), $zone_id);
                ZoneTemplate::add_zone_templ_save_as($this->db, $this->config('db_type'), $_POST['templ_name'], $_POST['templ_descr'], $_SESSION['userid'], $records, DnsRecord::get_domain_name_by_id($this->db, $zone_id));
                $this->setMessage('edit', 'success', _('Zone template has been added successfully.'));
            }
        }

        $perm_view = Permission::getViewPermission($this->db);
        $perm_edit = Permission::getEditPermission($this->db);

        if (LegacyUsers::verify_permission($this->db, 'zone_meta_edit_others')) {
            $perm_meta_edit = "all";
        } elseif (LegacyUsers::verify_permission($this->db, 'zone_meta_edit_own')) {
            $perm_meta_edit = "own";
        } else {
            $perm_meta_edit = "none";
        }

        $user_is_zone_owner = LegacyUsers::verify_user_is_owner_zoneid($this->db, $zone_id);

        $meta_edit = $perm_meta_edit == "all" || ($perm_meta_edit == "own" && $user_is_zone_owner == "1");

        if (isset($_POST['slave_master_change']) && is_numeric($_POST["domain"])) {
            DnsRecord::change_zone_slave_master($this->db, $_POST['domain'], $_POST['new_master']);
        }

        $types = ZoneType::getTypes();
        if (isset($_POST['type_change']) && in_array($_POST['newtype'], $types)) {
            DnsRecord::change_zone_type($this->db, $_POST['newtype'], $zone_id);
        }
        if (isset($_POST["newowner"]) && is_numeric($_POST["domain"]) && is_numeric($_POST["newowner"])) {
            DnsRecord::add_owner_to_zone($this->db, $_POST["domain"], $_POST["newowner"]);
        }
        if (isset($_POST["delete_owner"]) && is_numeric($_POST["delete_owner"])) {
            DnsRecord::delete_owner_from_zone($this->db, $zone_id, $_POST["delete_owner"]);
        }
        if (isset($_POST["template_change"])) {
            if (!isset($_POST['zone_template']) || "none" == $_POST['zone_template']) {
                $new_zone_template = 0;
            } else {
                $new_zone_template = $_POST['zone_template'];
            }
            if ($_POST['current_zone_template'] != $new_zone_template) {
                $dnsRecord = new DnsRecord($this->db, $this->getConfig());
                $dnsRecord->update_zone_records($this->config('db_type'), $this->config('dns_ttl'), $zone_id, $new_zone_template);
            }
        }

        if ($perm_view == "none" || $perm_view == "own" && $user_is_zone_owner == "0") {
            $this->showError(_("You do not have the permission to view this zone."));
        }

        if (DnsRecord::zone_id_exists($this->db, $zone_id) == "0") {
            $this->showError(_('There is no zone with this ID.'));
        }

        if (isset($_POST['sign_zone'])) {
            $dnsRecord = new DnsRecord($this->db, $this->getConfig());
            $dnsRecord->update_soa_serial($zone_id);

            $dnssecProvider = DnssecProviderFactory::create($this->db, $this->getConfig());
            $result = $dnssecProvider->secureZone($zone_name);
            $dnssecProvider->rectifyZone($zone_name);

            if ($result) {
                $this->setMessage('edit', 'success', _('Zone has been signed successfully.'));
            }
        }

        if (isset($_POST['unsign_zone'])) {
            $dnssecProvider = DnssecProviderFactory::create($this->db, $this->getConfig());
            $dnssecProvider->unsecureZone($zone_name);

            $dnsRecord = new DnsRecord($this->db, $this->getConfig());
            $dnsRecord->update_soa_serial($zone_id);
            $this->setMessage('edit', 'success', _('Zone has been unsigned successfully.'));
        }

        $domain_type = DnsRecord::get_domain_type($this->db, $zone_id);
        $record_count = DnsRecord::count_zone_records($this->db, $zone_id);
        $zone_templates = ZoneTemplate::get_list_zone_templ($this->db, $_SESSION['userid']);
        $zone_template_id = DnsRecord::get_zone_template($this->db, $zone_id);
        $zone_template_details = ZoneTemplate::get_zone_templ_details($this->db, $zone_template_id);
        $slave_master = DnsRecord::get_domain_slave_master($this->db, $zone_id);
        $users = LegacyUsers::show_users($this->db);

        $zone_comment = '';
        $raw_zone_comment = DnsRecord::get_zone_comment($this->db, $zone_id);
        if ($raw_zone_comment) { $zone_comment = htmlspecialchars($raw_zone_comment); }

        $zone_name_to_display = DnsRecord::get_domain_name_by_id($this->db, $zone_id);
        if (str_starts_with($zone_name_to_display, "xn--")) {
            $idn_zone_name = idn_to_utf8($zone_name_to_display, IDNA_NONTRANSITIONAL_TO_ASCII);
        } else {
            $idn_zone_name = "";
        }
        $records = DnsRecord::get_records_from_domain_id($this->db, $this->config('db_type'), $zone_id, $row_start, $iface_rowamount, $record_sort_by);
        $owners = DnsRecord::get_users_from_domain_id($this->db, $zone_id);

        $soa_record = DnsRecord::get_soa_record($this->db, $zone_id);

        $dnssecProvider = DnssecProviderFactory::create($this->db, $this->getConfig());

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
            'perm_zone_master_add' => LegacyUsers::verify_permission($this->db, 'zone_master_add'),
            'perm_view_others' => LegacyUsers::verify_permission($this->db, 'user_view_others'),
            'user_is_zone_owner' => $user_is_zone_owner,
            'zone_types' => $types,
            'row_start' => $row_start,
            'row_amount' => $iface_rowamount,
            'record_sort_by' => $record_sort_by,
            'pagination' => $this->createAndPresentPagination($record_count, $iface_rowamount, $zone_id),
            'pdnssec_use' => $this->config('pdnssec_use'),
            'is_secured' => $dnssecProvider->isZoneSecured($zone_name),
            'session_userid' => $_SESSION["userid"],
            'dns_ttl' => $this->config('dns_ttl'),
            'is_rev_zone' => preg_match('/i(p6|n-addr).arpa/i', $zone_name),
            'record_types' => RecordType::getTypes(),
            'iface_add_reverse_record' => $this->config('iface_add_reverse_record'),
            'iface_zone_comments' => $this->config('iface_zone_comments'),
            'serial' => DnsRecord::get_soa_serial($soa_record)
        ]);
    }

    private function createAndPresentPagination(int $totalItems, string $itemsPerPage, int $id): string
    {
        $httpParameters = new HttpPaginationParameters();
        $currentPage = $httpParameters->getCurrentPage();

        $paginationService = new PaginationService();
        $pagination = $paginationService->createPagination($totalItems, $itemsPerPage, $currentPage);
        $presenter = new PaginationPresenter($pagination, '?start={PageNumber}', $id);

        return $presenter->present();
    }

    public function getSortBy()
    {
        $record_sort_by = 'name';
        if (isset($_GET["record_sort_by"]) && preg_match("/^[a-z_]+$/", $_GET["record_sort_by"])) {
            $record_sort_by = $_GET["record_sort_by"];
            $_SESSION["record_sort_by"] = $_GET["record_sort_by"];
        } elseif (isset($_POST["record_sort_by"]) && preg_match("/^[a-z_]+$/", $_POST["record_sort_by"])) {
            $record_sort_by = $_POST["record_sort_by"];
            $_SESSION["record_sort_by"] = $_POST["record_sort_by"];
        } elseif (isset($_SESSION["record_sort_by"])) {
            $record_sort_by = $_SESSION["record_sort_by"];
        }
        return $record_sort_by;
    }
}

$controller = new EditController();
$controller->run();
