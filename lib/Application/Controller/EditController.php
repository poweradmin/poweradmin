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
use Poweradmin\Application\Service\RecordManagerService;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\RecordLog;
use Poweradmin\Domain\Service\RecordTypeService;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Model\ZoneTemplate;
use Poweradmin\Domain\Model\ZoneType;
use Poweradmin\Domain\Service\DnsIdnService;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Domain\Service\DomainRecordCreator;
use Poweradmin\Domain\Service\FormStateService;
use Poweradmin\Domain\Service\ReverseRecordCreator;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Domain\Service\Validator;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Domain\Repository\RecordRepository;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Poweradmin\Infrastructure\Repository\DbRecordCommentRepository;
use Poweradmin\Infrastructure\Repository\DbUserRepository;
use Poweradmin\Infrastructure\Repository\DbZoneRepository;
use Poweradmin\Infrastructure\Service\HttpPaginationParameters;
use Symfony\Component\Validator\Constraints as Assert;

class EditController extends BaseController
{
    private RecordCommentService $recordCommentService;
    private RecordCommentSyncService $commentSyncService;
    private RecordTypeService $recordTypeService;
    private FormStateService $formStateService;
    private LegacyLogger $logger;
    private DnsRecord $dnsRecord;
    private DomainRecordCreator $domainRecordCreator;
    private ReverseRecordCreator $reverseRecordCreator;
    private RecordManagerService $recordManager;
    private UserContextService $userContextService;
    private ZoneRepositoryInterface $zoneRepository;
    private PermissionService $permissionService;
    private RecordRepository $recordRepository;

    public function __construct(array $request)
    {
        parent::__construct($request);
        $recordCommentRepository = new DbRecordCommentRepository($this->db, $this->getConfig());
        $this->recordCommentService = new RecordCommentService($recordCommentRepository);
        $this->commentSyncService = new RecordCommentSyncService($this->recordCommentService);
        $this->recordTypeService = new RecordTypeService($this->getConfig());
        $this->formStateService = new FormStateService();

        // Initialize services for record addition
        $this->logger = new LegacyLogger($this->db);
        $this->dnsRecord = new DnsRecord($this->db, $this->getConfig());

        $this->recordManager = new RecordManagerService(
            $this->db,
            $this->dnsRecord,
            $this->recordCommentService,
            $this->commentSyncService,
            $this->logger,
            $this->getConfig()
        );

        $this->domainRecordCreator = new DomainRecordCreator(
            $this->getConfig(),
            $this->logger,
            $this->dnsRecord,
        );

        $this->reverseRecordCreator = new ReverseRecordCreator(
            $this->db,
            $this->getConfig(),
            $this->logger,
            $this->dnsRecord
        );

        $this->userContextService = new UserContextService();
        $this->zoneRepository = new DbZoneRepository($this->db, $this->getConfig());

        $userRepository = new DbUserRepository($this->db);
        $this->permissionService = new PermissionService($userRepository);
        $this->recordRepository = new RecordRepository($this->db, $this->getConfig());
    }

    public function run(): void
    {
        // Get default rows per page from config
        $default_rowamount = $this->config->get('interface', 'rows_per_page', 10);

        // Create pagination service and get user preference
        $paginationService = new PaginationService();
        $iface_rowamount = $paginationService->getUserRowsPerPage($default_rowamount);
        $configManager = ConfigurationManager::getInstance();
        $iface_show_id = $configManager->get('interface', 'show_record_id', false);
        $iface_edit_add_record_top = $configManager->get('interface', 'position_record_form_top', false);
        $iface_edit_save_changes_top = $configManager->get('interface', 'position_save_button_top', false);
        $iface_record_comments = $configManager->get('interface', 'show_record_comments', false);
        $iface_zone_comments = $configManager->get('interface', 'show_zone_comments', true);

        // Initialize filter parameters
        $searchTerm = isset($_GET['search']) ? htmlspecialchars($_GET['search']) : '';
        $recordTypeFilter = isset($_GET['record_type']) ? htmlspecialchars($_GET['record_type']) : '';
        $contentFilter = isset($_GET['content']) ? htmlspecialchars($_GET['content']) : '';

        // Generate a form token for the add record form
        $formToken = $this->formStateService->generateFormId('add_record');

        // Check if we have any form data from a failed submission
        $formData = null;
        if (isset($_REQUEST['form_id']) && !empty($_REQUEST['form_id'])) {
            $formData = $this->formStateService->getFormData($_REQUEST['form_id']);
        }

        $row_start = 0;
        if (isset($_GET["start"])) {
            $row_start = ($_GET["start"] - 1) * $iface_rowamount;
        }

        $record_sort_by = $this->getSortBy('record_sort_by', ['id', 'name', 'type', 'content', 'prio', 'ttl', 'disabled']);
        $sort_direction = $this->getSortDirection('sort_direction');

        if (!isset($_GET['id']) || !Validator::isNumber($_GET['id'])) {
            $this->showError(_('Invalid or unexpected input given.'));
        }
        $zone_id = intval(htmlspecialchars($_GET['id']));
        $zone_name = $this->zoneRepository->getDomainNameById($zone_id);

        if (isset($_GET['export_csv'])) {
            $this->exportCsv($zone_id);
            return;
        }

        // Process record addition form directly in the edit page
        if ($this->isPost() && isset($_POST['commit']) && isset($_POST['name']) && isset($_POST['content']) && isset($_POST['type'])) {
            $this->validateCsrfToken();

            // Store the original form data before processing (in case validation fails)
            $_SESSION['add_record_last_data'] = [
                'name' => $_POST['name'] ?? '',
                'content' => $_POST['content'] ?? '',
                'type' => $_POST['type'] ?? '',
                'prio' => isset($_POST['prio']) && $_POST['prio'] !== '' ? (int)$_POST['prio'] : 0,
                'ttl' => isset($_POST['ttl']) && $_POST['ttl'] !== '' ? (int)$_POST['ttl'] : $this->config->get('dns', 'ttl', 3600),
                'comment' => $_POST['comment'] ?? ''
            ];

            // Handle record addition directly in edit controller (no redirect)
            if (!isset($_POST['record'])) { // Check if it's an add record operation (not a zone update)
                $result = $this->addRecord($zone_id);

                // If the record was added successfully, clear the stored data
                if ($result) {
                    unset($_SESSION['add_record_last_data']);
                    unset($_SESSION['add_record_error']);
                } elseif (!$formData && isset($_SESSION['add_record_error'])) {
                    // Create form data from the session error data
                    $formData = array_merge($_SESSION['add_record_last_data'], $_SESSION['add_record_error']);
                }
            } else {
                // This is a zone update operation, handle as before
                $this->saveRecords($zone_id, $zone_name);
            }
        }

        // If we have stored validation error data from a previous request, use it
        if (!$formData && isset($_SESSION['add_record_last_data']) && isset($_SESSION['add_record_error'])) {
            $formData = array_merge($_SESSION['add_record_last_data'], $_SESSION['add_record_error']);
        }

        if (isset($_POST['save_as'])) {
            $this->validateCsrfToken();
            $this->saveAsTemplate($zone_id);
        }

        $userId = $this->userContextService->getLoggedInUserId();
        $perm_view = $this->permissionService->getViewPermissionLevel($userId);
        $perm_edit = $this->permissionService->getEditPermissionLevel($userId);
        $perm_meta_edit = $this->permissionService->getZoneMetaEditPermissionLevel($userId);

        $user_is_zone_owner = UserManager::verifyUserIsOwnerZoneId($this->db, $zone_id);

        $meta_edit = $perm_meta_edit == "all" || ($perm_meta_edit == "own" && $user_is_zone_owner == "1");

        if (isset($_POST['slave_master_change']) && is_numeric($_POST["domain"])) {
            $this->validateCsrfToken();
            $this->dnsRecord->changeZoneSlaveMaster($_POST['domain'], $_POST['new_master']);
        }

        $types = ZoneType::getTypes();

        $new_type = htmlspecialchars($_POST['newtype'] ?? '');
        if (isset($_POST['type_change']) && in_array($new_type, $types)) {
            $this->validateCsrfToken();
            $this->dnsRecord->changeZoneType($new_type, $zone_id);
        }

        if (isset($_POST["newowner"]) && is_numeric($_POST["domain"]) && is_numeric($_POST["newowner"])) {
            $this->validateCsrfToken();
            $this->zoneRepository->addOwnerToZone($_POST["domain"], $_POST["newowner"]);
        }

        if (isset($_POST["delete_owner"]) && is_numeric($_POST["delete_owner"])) {
            $this->validateCsrfToken();
            $this->zoneRepository->removeOwnerFromZone($zone_id, $_POST["delete_owner"]);
        }

        if (isset($_POST["template_change"])) {
            $this->validateCsrfToken();
            if (!isset($_POST['zone_template']) || "none" == $_POST['zone_template']) {
                $new_zone_template = 0;
            } else {
                $new_zone_template = $_POST['zone_template'];
            }
            if ($_POST['current_zone_template'] != $new_zone_template) {
                $this->dnsRecord->updateZoneRecords($this->config->get('database', 'type', 'mysql'), $this->config->get('dns', 'ttl', 86400), $zone_id, $new_zone_template);
            }
        }

        if ($perm_view == "none" || $perm_view == "own" && $user_is_zone_owner == "0") {
            $this->showError(_("You do not have the permission to view this zone."));
        }

        if (!$this->zoneRepository->zoneIdExists($zone_id)) {
            $this->showError(_('There is no zone with this ID.'));
        }

        if (isset($_POST['sign_zone'])) {
            $this->validateCsrfToken();

            $dnssecProvider = DnssecProviderFactory::create($this->db, $this->getConfig());

            // Check if DNSSEC is enabled on the server
            if (!$dnssecProvider->isDnssecEnabled()) {
                $this->setMessage('edit', 'error', _('DNSSEC is not enabled on the server.'));
            } elseif ($dnssecProvider->isZoneSecured($zone_name, $this->getConfig())) {
                // Check if zone is already secured
                $this->setMessage('edit', 'info', _('Zone is already signed with DNSSEC.'));
            } else {
                // Sign the zone
                // Update SOA serial before signing
                $this->dnsRecord->updateSOASerial($zone_id);

                // Try to secure the zone
                $result = $dnssecProvider->secureZone($zone_name);

                if ($result) {
                    // Verify the zone is now secured
                    if ($dnssecProvider->isZoneSecured($zone_name, $this->getConfig())) {
                        $this->setMessage('edit', 'success', _('Zone has been signed successfully.'));
                        // Rectify zone to ensure consistency
                        $dnssecProvider->rectifyZone($zone_name);
                    } else {
                        $this->setMessage('edit', 'warning', _('Zone signing requested successfully, but verification failed. Check DNSSEC keys.'));
                        error_log("DNSSEC signing verification failed for zone: $zone_name - API returned success but zone not secured");
                    }
                } else {
                    $this->setMessage('edit', 'error', _('Failed to sign zone. Check PowerDNS logs for details.'));
                    error_log("DNSSEC signing failed for zone: $zone_name");
                }
            }
        }

        if (isset($_POST['unsign_zone'])) {
            $this->validateCsrfToken();

            $dnssecProvider = DnssecProviderFactory::create($this->db, $this->getConfig());

            // Check if zone is secured before attempting to unsecure
            if ($zone_name === false || !$dnssecProvider->isZoneSecured((string)$zone_name, $this->getConfig())) {
                $this->setMessage('edit', 'info', _('Zone is not currently signed with DNSSEC.'));
            } else {
                // Try to unsecure the zone
                $result = $dnssecProvider->unsecureZone((string)$zone_name);

                if ($result) {
                    // Verify the zone is now unsecured
                    if (!$dnssecProvider->isZoneSecured((string)$zone_name, $this->getConfig())) {
                        // Update SOA serial after unsigning
                        $this->dnsRecord->updateSOASerial($zone_id);
                        $this->setMessage('edit', 'success', _('Zone has been unsigned successfully.'));
                    } else {
                        $this->setMessage('edit', 'warning', _('Zone unsigning requested successfully, but verification failed.'));
                        error_log("DNSSEC unsigning verification failed for zone: $zone_name - API returned success but zone still secured");
                    }
                } else {
                    $this->setMessage('edit', 'error', _('Failed to unsign zone. Check PowerDNS logs for details.'));
                    error_log("DNSSEC unsigning failed for zone: $zone_name");
                }
            }
        }

        $zone_templates = new ZoneTemplate($this->db, $this->getConfig());

        $domain_type = $this->zoneRepository->getDomainType($zone_id);
        $record_count = $this->dnsRecord->countZoneRecords($zone_id);
        $zone_templates = $zone_templates->getListZoneTempl($this->userContextService->getLoggedInUserId());
        $zone_template_id = DnsRecord::getZoneTemplate($this->db, $zone_id);
        $zone_template_details = ZoneTemplate::getZoneTemplDetails($this->db, $zone_template_id);

        $slave_master = $this->zoneRepository->getDomainSlaveMaster($zone_id);

        $users = UserManager::showUsers($this->db);

        $zone_comment = '';
        $raw_zone_comment = $this->zoneRepository->getZoneComment($zone_id);
        if ($raw_zone_comment) {
            $zone_comment = htmlspecialchars($raw_zone_comment);
        }

        $zone_name_to_display = $this->zoneRepository->getDomainNameById($zone_id);
        if (str_starts_with($zone_name_to_display, "xn--")) {
            $idn_zone_name = DnsIdnService::toUtf8($zone_name_to_display);
        } else {
            $idn_zone_name = "";
        }
        // Get filtered records based on search parameters
        if (!empty($searchTerm) || !empty($recordTypeFilter) || !empty($contentFilter)) {
            $records = $this->recordRepository->getFilteredRecords(
                $zone_id,
                $row_start,
                $iface_rowamount,
                $record_sort_by,
                $sort_direction,
                $iface_record_comments,
                $searchTerm,
                $recordTypeFilter,
                $contentFilter
            );
            $total_filtered_count = $this->recordRepository->getFilteredRecordCount(
                $zone_id,
                $iface_record_comments,
                $searchTerm,
                $recordTypeFilter,
                $contentFilter
            );
        } else {
            $records = $this->dnsRecord->getRecordsFromDomainId(
                $this->config->get('database', 'type', 'mysql'),
                $zone_id,
                (int)$row_start,
                $iface_rowamount,
                $record_sort_by,
                $sort_direction,
                $iface_record_comments
            );
            $total_filtered_count = $record_count;
        }
        $owners = $this->zoneRepository->getZoneOwners($zone_id);

        $soa_record = $this->dnsRecord->getSOARecord($zone_id);

        $isDnsSecEnabled = $this->config->get('dnssec', 'enabled', false);
        $dnssecProvider = DnssecProviderFactory::create($this->db, $this->getConfig());

        $isReverseZone = $zone_name !== false && DnsHelper::isReverseZone((string)$zone_name);

        $this->render('edit.html', [
            'zone_id' => $zone_id,
            'zone_name' => $zone_name,
            'zone_name_to_display' => $zone_name_to_display,
            'idn_zone_name' => $idn_zone_name,
            'zone_comment' => $zone_comment,
            'domain_type' => $domain_type,
            'record_count' => $record_count,
            'filtered_record_count' => $total_filtered_count,
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
            'perm_zone_master_add' => $this->permissionService->canAddZones($userId),
            'perm_zone_templ_add' => $this->permissionService->canAddZoneTemplates($userId),
            'perm_view_others' => $this->permissionService->canViewOthersContent($userId),
            'perm_is_godlike' => $this->permissionService->isAdmin($userId),
            'user_is_zone_owner' => $user_is_zone_owner,
            'zone_types' => $types,
            'row_start' => $row_start,
            'row_amount' => $iface_rowamount,
            'record_sort_by' => $record_sort_by,
            'sort_direction' => $sort_direction,
            'pagination' => $this->createAndPresentPagination($record_count, $iface_rowamount, $zone_id),
            'pdnssec_use' => $isDnsSecEnabled,
            'is_secured' => $zone_name !== false && $dnssecProvider->isZoneSecured((string)$zone_name, $this->getConfig()),
            'session_userid' => $this->userContextService->getLoggedInUserId(),
            'dns_ttl' => $this->config->get('dns', 'ttl', 86400),
            'is_reverse_zone' => $isReverseZone,
            'record_types' => $isReverseZone ? $this->recordTypeService->getReverseZoneTypes($isDnsSecEnabled) : $this->recordTypeService->getDomainZoneTypes($isDnsSecEnabled),
            'iface_add_reverse_record' => $this->config->get('interface', 'add_reverse_record', true),
            'iface_add_domain_record' => $this->config->get('interface', 'add_domain_record', true),
            'iface_edit_show_id' => $iface_show_id,
            'iface_edit_add_record_top' => $iface_edit_add_record_top,
            'iface_edit_save_changes_top' => $iface_edit_save_changes_top,
            'iface_record_comments' => $iface_record_comments,
            'iface_zone_comments' => $iface_zone_comments,
            'serial' => DnsRecord::getSOASerial($soa_record),
            'file_version' => time(),
            'whois_enabled' => $this->config->get('whois', 'enabled', false),
            'form_token' => $formToken,
            'form_data' => $formData,
            'search_term' => $searchTerm,
            'record_type_filter' => $recordTypeFilter,
            'content_filter' => $contentFilter
        ]);
    }

    private function createAndPresentPagination(int $totalItems, string $itemsPerPage, int $id): string
    {
        $httpParameters = new HttpPaginationParameters();
        $currentPage = $httpParameters->getCurrentPage();

        $paginationService = new PaginationService();
        $pagination = $paginationService->createPagination($totalItems, $itemsPerPage, $currentPage);

        // Build base URL with any active filters
        $baseUrl = 'index.php?page=edit&id=' . $id . '&start={PageNumber}';

        // Add filters to pagination links if they exist
        if (isset($_GET['search']) && !empty($_GET['search'])) {
            $baseUrl .= '&search=' . urlencode($_GET['search']);
        }
        if (isset($_GET['record_type']) && !empty($_GET['record_type'])) {
            $baseUrl .= '&record_type=' . urlencode($_GET['record_type']);
        }
        if (isset($_GET['content']) && !empty($_GET['content'])) {
            $baseUrl .= '&content=' . urlencode($_GET['content']);
        }

        $presenter = new PaginationPresenter($pagination, $baseUrl);

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

    public function saveRecords(int $zone_id, string $zone_name): void
    {
        $error = false;
        $one_record_changed = false;
        $serial_mismatch = false;
        $updatedRecordComments = [];

        if (isset($_POST['record'])) {
            $soa_record = $this->dnsRecord->getSOARecord($zone_id);
            $current_serial = DnsRecord::getSOASerial($soa_record);

            if ($this->isSerialMismatch($current_serial)) {
                $serial_mismatch = true;
            } else {
                foreach ($_POST['record'] as $record) {
                    $log = new RecordLog($this->db, $this->getConfig());

                    if (isset($record['disabled']) && $record['disabled'] == 'on') {
                        $record["disabled"] = 1;
                    } else {
                        $record["disabled"] = 0;
                    }

                    $comment = '';
                    if ($this->config->get('interface', 'show_record_comments', false)) {
                        $recordComment = $this->recordCommentService->findComment($zone_id, $record['name'], $record['type']);
                        $comment = $recordComment && $recordComment->getComment() ?? '';
                    }

                    $log->logPrior($record['rid'], $record['zid'], $comment);

                    if (!$log->hasChanged($record)) {
                        continue;
                    } else {
                        $one_record_changed = true;
                    }

                    $edit_record = $this->dnsRecord->editRecord($record);
                    if (false === $edit_record) {
                        $error = true;
                    } else {
                        $log->logAfter($record['rid']);
                        $log->write();

                        if ($this->config->get('interface', 'show_record_comments', false)) {
                            $recordCopy = $log->getRecordCopy();
                            $recordKey = $recordCopy['name'] . '|' . $recordCopy['type'];

                            if (!isset($updatedRecordComments[$recordKey])) {
                                $this->recordCommentService->updateComment(
                                    $zone_id,
                                    $recordCopy['name'],
                                    $recordCopy['type'],
                                    $record['name'],
                                    $record['type'],
                                    $record['comment'] ?? '',
                                    $this->userContextService->getLoggedInUsername()
                                );

                                $this->commentSyncService->updateRelatedRecordComments(
                                    $this->dnsRecord,
                                    $record,
                                    $record['comment'] ?? '',
                                    $this->userContextService->getLoggedInUsername()
                                );

                                $updatedRecordComments[$recordKey] = true;
                            }
                        }
                    }
                }
            }
        }

        if ($this->config->get('interface', 'show_zone_comments', true)) {
            $one_record_changed = $this->processZoneComment($zone_id, $this->dnsRecord, $one_record_changed);
        }

        $this->finalizeSave($error, $serial_mismatch, $this->dnsRecord, $zone_id, $one_record_changed, $zone_name);
    }

    public function saveAsTemplate(string $zone_id): void
    {
        $template_name = htmlspecialchars($_POST['templ_name']) ?? '';
        $zoneTemplate = new ZoneTemplate($this->db, $this->getConfig());
        if ($zoneTemplate->zoneTemplNameExists($template_name)) {
            $this->showError(_('Zone template with this name already exists, please choose another one.'));
        } elseif ($template_name == '') {
            $this->showError(_("Template name can't be an empty string."));
        } else {
            $records = $this->dnsRecord->getRecordsFromDomainId($this->config->get('database', 'type', 'mysql'), $zone_id);

            $description = htmlspecialchars($_POST['templ_descr']) ?? '';

            $options = [
                'NS1' => $this->config->get('dns', 'ns1', '') ?? '',
                'HOSTMASTER' => $this->config->get('dns', 'hostmaster', '') ?? '',
            ];

            $zoneTemplate->addZoneTemplSaveAs($template_name, $description, $this->userContextService->getLoggedInUserId(), $records, $options, $this->zoneRepository->getDomainNameById($zone_id));
            $this->setMessage('edit', 'success', _('Zone template has been added successfully.'));
        }
    }

    public function exportCsv(int $zone_id): void
    {
        // Check if user is logged in
        if (!$this->userContextService->isAuthenticated()) {
            $this->showError(_('You need to be logged in to export zone data.'));
            return;
        }

        // Check permissions - same as viewing zones
        $userId = $this->userContextService->getLoggedInUserId();
        $perm_view = $this->permissionService->getViewPermissionLevel($userId);
        $user_is_zone_owner = UserManager::verifyUserIsOwnerZoneId($this->db, $zone_id);

        if ($perm_view == "none" || ($perm_view == "own" && $user_is_zone_owner == "0")) {
            $this->showError(_("You do not have permission to export this zone."));
            return;
        }

        // Validate CSRF token if using POST
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->validateCsrfToken();
        }

        $zone_name = $this->zoneRepository->getDomainNameById($zone_id);

        if (!$zone_name) {
            $this->showError(_('There is no zone with this ID.'));
            return;
        }

        if (!$this->zoneRepository->zoneIdExists($zone_id)) {
            $this->showError(_('There is no zone with this ID.'));
            return;
        }

        $records = $this->dnsRecord->getRecordsFromDomainId($this->config->get('database', 'type', 'mysql'), $zone_id);

        if (empty($records)) {
            $this->showError(_('This zone does not have any records to export.'));
            return;
        }

        // Sanitize filename to prevent header injection
        $sanitized_filename = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $zone_name);

        // Set headers for CSV download with security headers
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $sanitized_filename . '_records.csv');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Content-Transfer-Encoding: binary');

        // Create output stream
        $output = fopen('php://output', 'w');

        // Add BOM for UTF-8
        fputs($output, "\xEF\xBB\xBF");

        // CSV header
        $header = ['Name', 'Type', 'Content', 'Priority', 'TTL', 'Disabled'];

        // Add comments column if enabled
        if ($this->config->get('interface', 'show_record_comments', false)) {
            $header[] = 'Comment';
        }

        fputcsv($output, $header);

        // CSV data
        foreach ($records as $record) {
            $row = [
                $record['name'],
                $record['type'],
                $record['content'],
                $record['prio'],
                $record['ttl'],
                $record['disabled'] ? 'Yes' : 'No'
            ];

            // Add comment if enabled
            if ($this->config->get('interface', 'show_record_comments', false)) {
                $row[] = $record['comment'] ?? '';
            }

            fputcsv($output, $row);
        }

        fclose($output);
        exit();
    }

    /**
     * Check if the serial is mismatched
     *
     * @param string $current_serial
     * @return bool
     */
    public function isSerialMismatch(string $current_serial): bool
    {
        return isset($_POST['serial']) && $_POST['serial'] != $current_serial;
    }

    /**
     * Process zone comment
     *
     * @param int $zone_id
     * @param DnsRecord $dnsRecord
     * @param bool $one_record_changed
     * @return bool
     */
    public function processZoneComment(int $zone_id, DnsRecord $dnsRecord, bool $one_record_changed): bool
    {
        $raw_zone_comment = $this->zoneRepository->getZoneComment($zone_id);
        $zone_comment = $_POST['comment'] ?? '';
        if ($raw_zone_comment != $zone_comment) {
            $this->zoneRepository->updateZoneComment($zone_id, $zone_comment);
            $one_record_changed = true;
        }
        return $one_record_changed;
    }

    /**
     * Finalize save
     *
     * @param bool $error
     * @param bool $serial_mismatch
     * @param DnsRecord $dnsRecord
     * @param int $zone_id
     * @param bool $one_record_changed
     * @param string $zone_name
     * @return void
     */
    public function finalizeSave(bool $error, bool $serial_mismatch, DnsRecord $dnsRecord, int $zone_id, bool $one_record_changed, string $zone_name): void
    {
        if ($error === false) {
            $experimental_edit_conflict_resolution = $this->config->get('misc', 'edit_conflict_resolution', 'last_writer_wins');
            if ($serial_mismatch && $experimental_edit_conflict_resolution == 'only_latest_version') {
                $this->setMessage('edit', 'warn', (_('Request has expired, please try again.')));
            } else {
                $dnsRecord->updateSOASerial($zone_id);

                if ($one_record_changed) {
                    $this->setMessage('edit', 'success', _('Zone has been updated successfully.'));
                } else {
                    $this->setMessage('edit', 'warn', (_('Zone did not have any record changes.')));
                }

                if ($this->config->get('dnssec', 'enabled', false)) {
                    $dnssecProvider = DnssecProviderFactory::create($this->db, $this->getConfig());
                    $dnssecProvider->rectifyZone($zone_name);
                }
            }
        } else {
            $this->setMessage('edit', 'error', _('Zone has not been updated successfully.'));
        }
    }


    /**
     * Handle adding a new record directly from the edit page
     *
     * @param int $zone_id The ID of the zone
     * @return bool True if record was added successfully, false otherwise
     */
    private function addRecord(int $zone_id): bool
    {
        // These are required fields
        $constraints = [
            'content' => [
                new Assert\NotBlank()
            ],
            'type' => [
                new Assert\NotBlank()
            ]
        ];

        $this->setValidationConstraints($constraints);

        if (!$this->doValidateRequest($_POST)) {
            // Store validation error directly in session
            $_SESSION['add_record_error'] = [
                'error' => true,
                'errorMessage' => _('Please provide all required fields.'),
                'fieldError' => isset($_POST['content']) && !empty($_POST['content']) ? 'type' : 'content'
            ];

            // Don't call showFirstValidationError as it would redirect
            // We've already stored the form data for displaying error later
            return false;
        }

        $name = $_POST['name'] ?? '';
        $content = $_POST['content'];
        $type = $_POST['type'];
        $prio = isset($_POST['prio']) && $_POST['prio'] !== '' ? (int)$_POST['prio'] : 0;
        $ttl = isset($_POST['ttl']) && $_POST['ttl'] !== '' ? (int)$_POST['ttl'] : $this->config->get('dns', 'ttl', 3600);
        $comment = $_POST['comment'] ?? '';

        try {
            if (!$this->createRecord($zone_id, $name, $type, $content, $ttl, $prio, $comment)) {
                // Get system errors that were generated during validation
                $systemErrors = $this->getSystemErrors();
                $errorMessage = !empty($systemErrors) ? end($systemErrors) :
                    _('This record was not valid and could not be added. It may already exist or contain invalid data.');

                // Determine which field has an error
                $fieldWithError = $this->determineFieldWithError($errorMessage);

                // Store validation error directly in session
                $_SESSION['add_record_error'] = [
                    'error' => true,
                    'errorMessage' => $errorMessage,
                    'fieldError' => $fieldWithError
                ];
                return false;
            }
        } catch (\Exception $e) {
            // Handle exceptions from the validation process
            $errorMessage = $e->getMessage();
            $fieldWithError = $this->determineFieldWithError($errorMessage);

            // Store validation error directly in session
            $_SESSION['add_record_error'] = [
                'error' => true,
                'errorMessage' => $errorMessage,
                'fieldError' => $fieldWithError
            ];
            return false;
        }

        // Clear session data when record is successfully created
        unset($_SESSION['add_record_last_data']);
        unset($_SESSION['add_record_error']);

        // Clear form data if it exists in the session
        if (isset($_POST['form_token'])) {
            $this->formStateService->clearFormData($_POST['form_token']);
        }

        if (isset($_POST['reverse'])) {
            $reverseResult = $this->createReverseRecord($name, $type, $content, $zone_id, $ttl, $prio, $comment);

            if ($reverseResult && isset($reverseResult['success']) && $reverseResult['success']) {
                $message = _('Record successfully added. A matching PTR record was also created.');
                $this->setMessage('edit', 'success', $message);
            } elseif ($reverseResult && isset($reverseResult['success']) && !$reverseResult['success'] && isset($reverseResult['message'])) {
                // Reverse record creation failed with a specific message
                $message = _('Record successfully added, but PTR record creation failed: ') . $reverseResult['message'];
                $this->setMessage('edit', 'warning', $message);
            } else {
                // Reverse record creation failed without a specific message
                $this->setMessage('edit', 'success', _('The record was successfully added, but PTR record creation failed.'));
            }
        } elseif (isset($_POST['create_domain_record'])) {
            $domainRecord = $this->createDomainRecord($name, $type, $content, $zone_id, $comment);
            $message = $domainRecord ? _('Record successfully added. A matching A record was also created.') : _('The record was successfully added.');
            $this->setMessage('edit', 'success', $message);
        } else {
            $this->setMessage('edit', 'success', _('The record was successfully added.'));
        }

        // Update the zone's SOA serial
        $this->dnsRecord->updateSOASerial($zone_id);

        return true;
    }

    /**
     * Create a new record
     *
     * @param int $zone_id Zone ID
     * @param string $name Record name
     * @param string $type Record type
     * @param string $content Record content
     * @param int $ttl TTL value
     * @param int $prio Priority value
     * @param string $comment Record comment
     * @return bool True if record was created successfully
     */
    private function createRecord(int $zone_id, $name, $type, $content, $ttl, $prio, $comment): bool
    {
        return $this->recordManager->createRecord(
            $zone_id,
            $name,
            $type,
            $content,
            $ttl,
            $prio,
            $comment,
            $this->userContextService->getLoggedInUsername(),
            $_SERVER['REMOTE_ADDR']
        );
    }

    /**
     * Create a reverse record
     *
     * @param string $name Record name
     * @param string $type Record type
     * @param string $content Record content
     * @param string|int $zone_id Zone ID
     * @param int $ttl TTL value
     * @param int $prio Priority value
     * @param string $comment Record comment
     * @return array Result array with success status and message
     */
    private function createReverseRecord($name, $type, $content, $zone_id, $ttl, $prio, string $comment): array
    {
        $result = $this->reverseRecordCreator->createReverseRecord(
            $name,
            $type,
            $content,
            $zone_id,
            $ttl,
            $prio,
            $comment,
            $this->userContextService->getLoggedInUsername()
        );

        if (isset($result['success']) && !$result['success']) {
            $this->setMessage('edit', 'error', $result['message']);
        }

        return $result;
    }

    /**
     * Create a domain record
     *
     * @param string $name Record name
     * @param string $type Record type
     * @param string $content Record content
     * @param string|int $zone_id Zone ID
     * @param string $comment Record comment
     * @return bool True if domain record was created successfully
     */
    private function createDomainRecord(string $name, string $type, string $content, $zone_id, string $comment): bool
    {
        $result = $this->domainRecordCreator->addDomainRecord(
            $name,
            $type,
            $content,
            $zone_id,
            $comment,
            $this->userContextService->getLoggedInUsername()
        );

        if ($result['success']) {
            return true;
        } else {
            $this->setMessage('edit', 'error', $result['message']);
            return false;
        }
    }

    /**
     * Determine which field has an error based on the error message
     *
     * @param string $errorMessage The error message
     * @return string The name of the field with an error
     */
    private function determineFieldWithError(string $errorMessage): string
    {
        $lowerError = strtolower($errorMessage);

        // Check for specific field mentions in the error message
        if (strpos($lowerError, 'name') !== false && strpos($lowerError, 'invalid') !== false) {
            return 'name';
        } elseif (
            strpos($lowerError, 'content') !== false ||
                 strpos($lowerError, 'value') !== false ||
                 strpos($lowerError, 'address') !== false ||
                 strpos($lowerError, 'hostname') !== false
        ) {
            return 'content';
        } elseif (strpos($lowerError, 'ttl') !== false) {
            return 'ttl';
        } elseif (strpos($lowerError, 'prio') !== false || strpos($lowerError, 'priority') !== false) {
            return 'prio';
        } elseif (strpos($lowerError, 'already exists') !== false) {
            return 'name-content-duplicate';
        }

        // Default to content field as that's the most common error source
        return 'content';
    }
}
