<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2026 Poweradmin Development Team
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

namespace Poweradmin\Application\Controller;

use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Application\Service\DnsBackendProviderFactory;
use Poweradmin\Application\Service\RecordAddMessages;
use Poweradmin\Application\Service\RecordAddResult;
use Poweradmin\Application\Service\RejectedZoneEditPresenter;
use Poweradmin\Application\Service\ZoneSaveMessages;
use Poweradmin\Application\Service\ZoneSigningMessages;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Service\RecordTypeService;
use Poweradmin\Domain\Model\ZoneTemplate;
use Poweradmin\Domain\Model\ZoneType;
use Poweradmin\Domain\Service\CatalogZoneService;
use Poweradmin\Domain\Service\DnsIdnService;
use Poweradmin\Domain\Service\ZoneAccessPolicy;
use Poweradmin\Domain\Service\ZoneEditSubmission;
use Poweradmin\Domain\Service\ZoneManagementService;
use Poweradmin\Domain\Service\ZoneSortingService;
use Poweradmin\Domain\Service\Dns\DomainManager;
use Poweradmin\Domain\Service\Dns\DomainManagerInterface;
use Poweradmin\Domain\Service\Dns\SOARecordManager;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Infrastructure\Session\FormStateService;
use Poweradmin\Domain\Service\RecordDisplayService;
use Poweradmin\Domain\Service\ReverseTtlResolver;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneReadRepositoryInterface;
use Poweradmin\Domain\Service\SessionKeys;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Renders the zone edit page with its record table and handles record, comment, template and signing changes.
 */
class EditController extends BaseController
{
    private RecordTypeService $recordTypeService;
    private FormStateService $formStateService;
    private SOARecordManagerInterface $soaRecordManager;
    private ReverseTtlResolver $reverseTtlResolver;
    private UserContextService $userContextService;
    private ZoneReadRepositoryInterface $zoneRepository;
    private PermissionService $permissionService;
    private RecordRepositoryInterface $recordRepository;
    private DomainRepositoryInterface $domainRepository;
    /** Rows and comment from a submission rejected as stale, so the re-render can restore them. */
    private array $rejectedRecords = [];
    private ?string $rejectedZoneComment = null;

    public function __construct(array $request)
    {
        parent::__construct($request);
        $this->recordRepository = $this->createRecordRepository();
        $this->domainRepository = $this->createDomainRepository();
        $this->recordTypeService = new RecordTypeService($this->getConfig());
        $this->formStateService = new FormStateService();
        $this->soaRecordManager = $this->createSOARecordManager();
        $this->reverseTtlResolver = $this->createReverseTtlResolver();
        $this->userContextService = new UserContextService();
        $this->zoneRepository = $this->createZoneRepository();

        $this->permissionService = $this->createPermissionService();
    }

    public function run(): void
    {
        // Set the current page for navigation highlighting
        $this->setCurrentPage('edit');
        $this->setPageTitle(_('Edit zone'));

        $userId = $this->getCurrentUserId();
        $iface_rowamount = $this->resolveRowsPerPage();

        // Get user preferences for form positioning
        $userPreferenceService = $this->createUserPreferenceService();
        $iface_edit_add_record_top = $userPreferenceService->getRecordFormPosition($userId) === 'top';
        $iface_edit_save_changes_top = $userPreferenceService->getSaveButtonPosition($userId) === 'top';
        // API-backend records have no numeric ID, only an opaque composite identifier;
        // showing it as a column is unreadable, so suppress it regardless of preference.
        $isApiBackend = DnsBackendProviderFactory::isApiBackend($this->getConfig());
        $iface_show_id = $userPreferenceService->getShowRecordId($userId) && !$isApiBackend;
        $iface_show_add_record_form = $userPreferenceService->getShowAddRecordForm($userId);
        $iface_show_record_edit_button = $userPreferenceService->getShowRecordEditButton($userId);
        $iface_show_record_delete_button = $userPreferenceService->getShowRecordDeleteButton($userId);
        $display_hostname_only = $userPreferenceService->getDisplayHostnameOnly($userId);

        $iface_record_comments = $this->config->get('interface', 'show_record_comments', false);
        $iface_zone_comments = $this->config->get('interface', 'show_zone_comments', true);

        // Initialize filter parameters
        $searchTerm = htmlspecialchars($this->httpRequest->getQueryParam('search', ''));
        $recordTypeFilter = htmlspecialchars($this->httpRequest->getQueryParam('record_type', ''));
        $contentFilter = htmlspecialchars($this->httpRequest->getQueryParam('content', ''));

        // Generate a form token for the add record form
        $formToken = $this->formStateService->generateFormId('add_record');

        // Check if we have any form data from a failed submission
        $formData = null;
        $formId = $this->httpRequest->getQueryParam('form_id');
        if ($formId) {
            $formData = $this->formStateService->getFormData($formId);
        }

        $row_start = 0;
        $start = $this->httpRequest->getQueryParam('start');
        if ($start !== null) {
            $row_start = max(0, ((int)$start - 1) * $iface_rowamount);
        }

        [$record_sort_by, $sort_direction] = (new ZoneSortingService($this->userContextService))->getZoneSortOrder(
            ['id', 'name', 'type', 'content', 'prio', 'ttl', 'disabled'],
            SessionKeys::EDIT_RECORD_SORT_BY,
            submittedSortBy: $this->httpRequest->getPostParam('record_sort_by') ?? $this->httpRequest->getQueryParam('record_sort_by'),
            submittedDirection: $this->httpRequest->getPostParam('sort_direction') ?? $this->httpRequest->getQueryParam('sort_direction')
        );

        $zone_id = $this->requireNumericParam('id');

        // Clear session-based form data if zone has changed to prevent persistence across zones
        $this->clearFormDataOnZoneChange($zone_id);

        // Early permission check - validate access before data retrieval
        $userId = $this->userContextService->getLoggedInUserId();
        $perm_view = $this->permissionService->getViewPermissionLevel($userId);
        $user_is_zone_owner = $this->isZoneOwner($zone_id);

        if ($perm_view !== "all" && !$user_is_zone_owner) {
            $this->showError(_('You do not have permission to access this zone.'));
            return;
        }

        // Only retrieve zone data after permission validation
        $zone_name = $this->domainRepository->getDomainNameById($zone_id);
        if ($zone_name === null) {
            $this->showError(_('Zone not found.'));
            return;
        }
        $isReverseZone = DnsHelper::isReverseZoneName($zone_name);
        // Form pre-fill stays on dns.ttl; JS updateTtlForType() swaps in dns.ttl_reverse
        // for PTR selections so display tracks what's persisted.
        $defaultTtl = $this->reverseTtlResolver->getForwardTtl();

        // Process form submissions
        if ($this->isPost() && $this->httpRequest->getPostParam('commit') !== null) {
            $this->validateCsrfToken();

            // Check if this is a record addition (has name, content, type fields)
            $name = $this->httpRequest->getPostParam('name');
            $content = $this->httpRequest->getPostParam('content');
            $type = $this->httpRequest->getPostParam('type');
            if ($name !== null && $content !== null && $type !== null) {
                // Store the original form data before processing (in case validation fails)
                $prio = $this->httpRequest->getPostParam('prio');
                $ttl = $this->httpRequest->getPostParam('ttl');
                $_SESSION[SessionKeys::ADD_RECORD_LAST_DATA] = [
                    'name' => $name,
                    'content' => $content,
                    'type' => $type,
                    'prio' => $prio !== null && $prio !== '' ? (int)$prio : 0,
                    'ttl' => $ttl !== null && $ttl !== '' ? (int)$ttl : $this->reverseTtlResolver->resolveTtlForType($type, $isReverseZone),
                    'comment' => $this->httpRequest->getPostParam('comment', '')
                ];

                // Handle record addition directly in edit controller (no redirect)
                if ($this->httpRequest->getPostParam('record') === null) { // Check if it's an add record operation (not a zone update)
                    $result = $this->addRecord($zone_id, $zone_name);

                    // If the record was added successfully, clear the stored data
                    if ($result) {
                        unset($_SESSION[SessionKeys::ADD_RECORD_LAST_DATA]);
                        unset($_SESSION[SessionKeys::ADD_RECORD_ERROR]);
                    } elseif (!$formData && isset($_SESSION[SessionKeys::ADD_RECORD_ERROR])) {
                        // Create form data from the session error data
                        $formData = array_merge($_SESSION[SessionKeys::ADD_RECORD_LAST_DATA], $_SESSION[SessionKeys::ADD_RECORD_ERROR]);
                    }
                } else {
                    // This is a zone update operation, handle as before
                    $this->saveRecords($zone_id, $zone_name);
                }
            } elseif ($this->httpRequest->getPostParam('record') !== null || $this->httpRequest->getPostParam('zone_comment') !== null || $this->httpRequest->getPostParam('form_complete') !== null) {
                // Save operation: records, a zone comment, or an unchanged form. The
                // form_complete marker is always present, so a save where the client
                // omitted every unchanged record still bumps the SOA serial as before.
                $this->saveRecords($zone_id, $zone_name);
            }
        } elseif ($this->isPost() && $this->httpRequest->getPostParam('record') !== null && $this->httpRequest->getPostParam('commit') === null) {
            // max_input_vars truncated the POST and dropped the bottom save button; run
            // the save anyway so incomplete rows are skipped and the operator is warned.
            $this->validateCsrfToken();
            $this->saveRecords($zone_id, $zone_name);
        }

        // If we have stored validation error data from a previous request, use it
        if (!$formData && isset($_SESSION[SessionKeys::ADD_RECORD_LAST_DATA]) && isset($_SESSION[SessionKeys::ADD_RECORD_ERROR])) {
            $formData = array_merge($_SESSION[SessionKeys::ADD_RECORD_LAST_DATA], $_SESSION[SessionKeys::ADD_RECORD_ERROR]);
        }

        // Permission levels - use zone-aware checking for group permission support
        $perm_edit = $this->permissionService->getEditPermissionLevelForZone($userId, $zone_id);
        $perm_meta_edit = $this->permissionService->getZoneMetaEditPermissionLevel($userId);
        $meta_edit = ZoneAccessPolicy::levelAppliesToZone($perm_meta_edit, $user_is_zone_owner);
        $can_manage_dnssec = $this->permissionService->canManageDnssecForZone($userId, $zone_id);

        $perm_metadata_view = $this->permissionService->getZoneMetadataViewPermissionLevel($userId);
        $perm_ownership_view = $this->permissionService->getZoneOwnershipViewPermissionLevel($userId);
        $metadata_view = ZoneAccessPolicy::levelAppliesToZone($perm_metadata_view, $user_is_zone_owner);
        $ownership_view = ZoneAccessPolicy::levelAppliesToZone($perm_ownership_view, $user_is_zone_owner);

        $this->requireZoneView($zone_id);

        if ($this->isPost() && $meta_edit) {
            $this->handleZoneMetadataPost($zone_id);
        }

        if ($this->httpRequest->getPostParam('sign_zone') !== null) {
            $this->validateCsrfToken();

            if (!$can_manage_dnssec) {
                $this->setMessage('edit', 'error', _('You do not have permission to manage DNSSEC for this zone.'));
                $this->redirect('/zones/' . $zone_id . '/edit');
                return;
            }

            [$type, $message] = ZoneSigningMessages::forSign($this->createZoneSigningService()->sign($zone_id, $zone_name));
            $this->setMessage('edit', $type, $message);
        }

        if ($this->httpRequest->getPostParam('unsign_zone') !== null) {
            $this->validateCsrfToken();

            if (!$can_manage_dnssec) {
                $this->setMessage('edit', 'error', _('You do not have permission to manage DNSSEC for this zone.'));
                $this->redirect('/zones/' . $zone_id . '/edit');
                return;
            }

            [$type, $message] = ZoneSigningMessages::forUnsign($this->createZoneSigningService()->unsign($zone_id, $zone_name));
            $this->setMessage('edit', $type, $message);
        }

        $domain_type = $this->domainRepository->getDomainType($zone_id);
        $record_count = $this->recordRepository->countZoneRecords($zone_id);
        $slave_master = $this->domainRepository->getDomainMaster($zone_id);
        $types = ZoneType::getTypes();

        // Only zones PowerDNS would actually publish from a catalog get the selector,
        // so nothing below runs for the kinds that would discard the result.
        $catalog_selector_view = $this->getPdnsCapabilities()->supportsCatalogZones()
            && in_array($domain_type, CatalogZoneService::PUBLISHABLE_KINDS, true);

        // Read after the record listing above: in API mode the zone body is already
        // held, so the catalog read costs nothing extra here.
        $catalog_service = $this->createCatalogZoneService();
        $catalog_name = $catalog_selector_view ? $catalog_service->getCatalog($zone_id) : '';
        $catalog_producer = $catalog_name !== '' ? $catalog_service->getCatalogProducer($zone_id) : null;
        $catalog_producers = $catalog_selector_view && $meta_edit ? $catalog_service->getManageableProducers($userId) : [];

        // Get zone templates
        $zone_templates = $this->createZoneTemplateModel();
        $zone_templates = $zone_templates->getListZoneTempl($userId);
        $zone_template_id = DomainManager::getZoneTemplate($this->db, $zone_id);
        $zone_template_details = ZoneTemplate::getZoneTemplDetails($this->db, $zone_template_id);

        // Twig escapes this for the textarea. Escaping it here as well would put the
        // entities in front of the operator and save them back on the next submit.
        $zone_comment = (string)$this->zoneRepository->getZoneComment($zone_id);

        $idn_zone_name = DnsIdnService::toIdnAlias($zone_name);
        // Get records via DnsDataService (supports both SQL and API backends)
        $dnsDataService = $this->createDnsDataService();
        $recordResult = $dnsDataService->getZoneRecords(
            $zone_id,
            $zone_name,
            $row_start,
            $iface_rowamount,
            $record_sort_by,
            $sort_direction,
            $iface_record_comments,
            $searchTerm,
            $recordTypeFilter,
            $contentFilter
        );
        $records = $recordResult['records'];
        $total_filtered_count = $recordResult['total'];

        $soa_record = $this->soaRecordManager->getSOARecord($zone_id);

        $isDnsSecEnabled = $this->config->get('dnssec', 'enabled', false);
        $dnssecProvider = $this->createDnssecProvider();
        $is_secured = $dnssecProvider->isZoneSecured($zone_name, $this->getConfig());
        // Presigned zones always report secured, so unsigned zones skip the metadata lookup
        $is_presigned = $is_secured && $dnssecProvider->isZonePresigned($zone_name);
        // Serial as served by PowerDNS (SOA-EDIT applied); only relevant for signed zones
        $signed_serial = ($isDnsSecEnabled && $is_secured) ? $dnssecProvider->getEditedSerial($zone_name) : null;

        // Transform records for display using the RecordDisplayService
        $recordDisplayService = new RecordDisplayService($display_hostname_only);

        $displayRecords = $recordDisplayService->transformRecords($records, $zone_name);

        $perm_edit_ns_subzone = $this->hasPermission(Permission::PERM_EDIT_NS_SUBZONE);
        $perm_is_godlike = $this->permissionService->isAdmin($userId);
        $zone_is_read_only = ZoneType::isReadOnly($domain_type);
        $user_can_edit_zone = ZoneAccessPolicy::canEditZone($perm_edit, $user_is_zone_owner);
        $zone_is_editable = $user_can_edit_zone && !$zone_is_read_only;
        $can_edit_records = $perm_edit !== 'none';
        $log_permission = $this->permissionService->getZoneLogPermissionLevel($userId);
        $can_view_zone_logs = ZoneAccessPolicy::levelAppliesToZone($log_permission, $user_is_zone_owner);

        foreach ($displayRecords as &$record) {
            $record['display_name'] ??= $record['name'];
            $record['editable_name'] ??= $record['name'];
            $record['unsaved_edit'] = false;
            $record['stored_summary'] = '';
            $nsRecordLocked = ZoneAccessPolicy::isNsRecordLocked(
                $record['type'],
                $perm_edit,
                $perm_edit_ns_subzone,
                $record['name'],
                $zone_name
            );
            $record['record_locked'] = ZoneAccessPolicy::isRecordLocked(
                $zone_is_read_only,
                $record['type'],
                $perm_edit,
                $nsRecordLocked
            );
        }
        unset($record);

        $stale_form_dropped = RejectedZoneEditPresenter::restore($displayRecords, $this->rejectedRecords);
        $stored_zone_comment = $zone_comment;
        $zone_comment_conflict = false;
        if ($this->rejectedZoneComment !== null) {
            // The retry writes the submitted comment over the stored one, so say what
            // the zone holds when another writer has changed it in the meantime.
            $zone_comment_conflict = $stored_zone_comment !== $this->rejectedZoneComment;
            $zone_comment = $this->rejectedZoneComment;
        }

        $recordTypes = $isReverseZone
            ? $this->recordTypeService->getReverseZoneTypes($isDnsSecEnabled, $this->getRecordTypeCapabilities())
            : $this->recordTypeService->getDomainZoneTypes($isDnsSecEnabled, $this->getRecordTypeCapabilities());

        $this->render('edit.html', [
            'zone_id' => $zone_id,
            'zone_name' => $zone_name,
            'zone_name_to_display' => $zone_name,
            'idn_zone_name' => $idn_zone_name,
            'zone_display_name' => DnsIdnService::toDisplay($zone_name),
            'zone_comment' => $zone_comment,
            'zone_comment_conflict' => $zone_comment_conflict,
            'stored_zone_comment' => $stored_zone_comment,
            'domain_type' => $domain_type,
            'slave_master' => $slave_master,
            'zone_types' => $types,
            'zone_replicates_from_primary' => ZoneType::replicatesFromPrimary($domain_type),
            // Only the API backend can ask PowerDNS for a transfer, so the button is hidden otherwise
            'can_retrieve_zone' => $isApiBackend && $domain_type === ZoneType::SLAVE && ($slave_master ?? '') !== '',
            // Catalog kinds are absent from $types, so the browser would preselect the
            // first option and one click would silently retype the zone.
            'zone_type_change_allowed' => in_array($domain_type, $types, true),
            'catalog_members_view' => $domain_type === ZoneType::PRODUCER && $metadata_view
                && $this->getPdnsCapabilities()->supportsCatalogZones(),
            'catalog_selector_view' => $catalog_selector_view,
            'catalog_producers' => $catalog_producers,
            'catalog_producer_id' => $catalog_producer['id'] ?? null,
            // Non-empty with a null producer id means the zone is in a catalog whose
            // producer this install does not manage. Shown so it is not silently lost.
            'catalog_name' => $catalog_name,
            'zone_templates' => $zone_templates,
            'zone_template_id' => $zone_template_id,
            'zone_template_details' => $zone_template_details,
            'record_count' => $record_count,
            'filtered_record_count' => $total_filtered_count,
            'records' => $displayRecords,
            'stale_form_dropped' => $stale_form_dropped,
            'perm_view' => $perm_view,
            'perm_edit' => $perm_edit,
            'perm_edit_ns_subzone' => $perm_edit_ns_subzone,
            'perm_meta_edit' => $perm_meta_edit,
            'meta_edit' => $meta_edit,
            'metadata_view' => $metadata_view,
            'ownership_view' => $ownership_view,
            'zone_is_read_only' => $zone_is_read_only,
            'user_can_edit_zone' => $user_can_edit_zone,
            'zone_is_editable' => $zone_is_editable,
            'can_edit_records' => $can_edit_records,
            'can_view_zone_logs' => $can_view_zone_logs,
            'can_manage_dnssec' => $can_manage_dnssec,
            'perm_zone_templ_add' => $this->permissionService->canAddZoneTemplates($userId),
            'perm_is_godlike' => $perm_is_godlike,
            'dblog_use' => $this->config->get('logging', 'database_enabled', false),
            'perm_view_zone_own' => $this->hasPermission(Permission::PERM_ZONE_CONTENT_VIEW_OWN),
            'perm_view_zone_other' => $this->hasPermission(Permission::PERM_ZONE_CONTENT_VIEW_OTHERS),
            'user_is_zone_owner' => $user_is_zone_owner,
            'row_start' => $row_start,
            'row_amount' => $iface_rowamount,
            'record_sort_by' => $record_sort_by,
            'sort_direction' => $sort_direction,
            'pagination' => $this->presentPagination($total_filtered_count, $iface_rowamount, '/zones/' . $zone_id . '/edit?start={PageNumber}', [
                'search' => $this->httpRequest->getQueryParam('search'),
                'record_type' => $this->httpRequest->getQueryParam('record_type'),
                'content' => $this->httpRequest->getQueryParam('content'),
            ]),
            'pdnssec_use' => $isDnsSecEnabled,
            'is_secured' => $is_secured,
            'is_presigned' => $is_presigned,
            'signed_serial' => $signed_serial,
            'session_userid' => $this->userContextService->getLoggedInUserId(),
            'dns_ttl' => $defaultTtl,
            'default_ttl' => $this->reverseTtlResolver->getForwardTtl(),
            'ptr_default_ttl' => $this->reverseTtlResolver->getConfiguredReverseTtl(),
            'type_default_ttls' => $this->reverseTtlResolver->getTypeDefaults(),
            'ttl_defaults_by_type' => $this->reverseTtlResolver->resolveTtlsForTypes($recordTypes, $isReverseZone),
            'is_reverse_zone' => $isReverseZone,
            'record_types' => $recordTypes,
            'iface_add_reverse_record' => $this->config->get('interface', 'add_reverse_record', true),
            'iface_add_domain_record' => $this->config->get('interface', 'add_domain_record', true),
            'iface_edit_show_id' => $iface_show_id,
            'iface_show_add_record_form' => $iface_show_add_record_form,
            'iface_show_record_edit_button' => $iface_show_record_edit_button,
            'iface_show_record_delete_button' => $iface_show_record_delete_button,
            'iface_edit_add_record_top' => $iface_edit_add_record_top,
            'iface_edit_save_changes_top' => $iface_edit_save_changes_top,
            'iface_record_comments' => $iface_record_comments,
            'iface_zone_comments' => $iface_zone_comments,
            'serial' => SOARecordManager::getSOASerial($soa_record),
            'whois_actions' => $this->moduleCapabilityData('whois_lookup', ['zone_id' => $zone_id]),
            'rdap_actions' => $this->moduleCapabilityData('rdap_lookup', ['zone_id' => $zone_id]),
            'form_token' => $formToken,
            'form_data' => $formData,
            'search_term' => $searchTerm,
            'record_type_filter' => $recordTypeFilter,
            'content_filter' => $contentFilter,
            'display_hostname_only' => $display_hostname_only,
            'dns_wizard_actions' => $this->moduleCapabilityData('dns_wizard', ['zone_id' => $zone_id]),
            'export_formats' => $this->moduleCapabilityData('zone_export', ['zone_id' => $zone_id]),
            'import_enabled' => $this->moduleProvides('zone_import'),
        ]);
    }

    /**
     * Join or leave a catalog. The producer arrives as a zone id so the service can
     * resolve its name and check rights on it, rather than trusting a posted name.
     */
    private function handleCatalogChange(int $zone_id): void
    {
        $userId = $this->userContextService->getLoggedInUserId();
        $catalogService = $this->createCatalogZoneService();
        $producerId = $this->httpRequest->getPostParam('new_catalog', '');

        // The zone is in a catalog with no local producer; leave it as it is rather
        // than clearing something the operator cannot see the whole of.
        if ($producerId === 'keep') {
            return;
        }

        if ($producerId === '' || $producerId === null) {
            $done = $catalogService->clear($userId, $zone_id);
            $message = $done ? _('The zone has been removed from the catalog.') : _('You do not have permission to edit this zone.');
        } elseif (!is_numeric($producerId)) {
            $done = false;
            $message = _('Invalid or unexpected input given.');
        } else {
            $done = $catalogService->assign($userId, $zone_id, (int)$producerId);
            $message = $done ? _('The catalog has been changed successfully.') : _('You do not have permission to edit this zone.');
        }

        $this->setMessage('edit', $done ? 'success' : 'error', $message);
    }

    private function handleZoneMetadataPost(int $zone_id): void
    {
        $domainManager = $this->createDomainManager();
        $new_type = htmlspecialchars($this->httpRequest->getPostParam('newtype', ''));
        if ($this->httpRequest->getPostParam('type_change') !== null && in_array($new_type, ZoneType::getTypes())) {
            $this->validateCsrfToken();
            // Converting a zone is equivalent to creating one of the target type.
            if (!$this->permissionService->canCreateZone((int)$this->getCurrentUserId(), $new_type)) {
                $this->setMessage('edit', 'error', _('You do not have permission to change this zone to that type.'));
                return;
            }
            $this->reportZoneWrite('edit', $domainManager->changeZoneType($new_type, $zone_id), _('Zone type has been changed successfully.'));
        }

        if ($this->httpRequest->getPostParam('slave_master_change') !== null) {
            $this->validateCsrfToken();
            $this->reportZoneWrite('edit', $domainManager->changeZoneSlaveMaster($zone_id, $this->httpRequest->getPostParam('new_master', '')), _('Slave master has been changed successfully.'));
        }

        if ($this->httpRequest->getPostParam('retrieve_zone') !== null) {
            $this->validateCsrfToken();
            $this->handleRetrieveZone($zone_id, $domainManager);
        }

        if ($this->httpRequest->getPostParam('catalog_change') !== null) {
            $this->validateCsrfToken();
            $this->handleCatalogChange($zone_id);
        }

        if ($this->httpRequest->getPostParam('template_change') !== null) {
            $this->validateCsrfToken();
            $this->handleTemplateChange($zone_id);
        }
    }

    private function handleTemplateChange(int $zone_id): void
    {
        $zone_template = (string)($this->httpRequest->getPostParam('zone_template') ?? 'none');
        $new_zone_template = $zone_template === 'none' ? 0 : $zone_template;
        if ($this->httpRequest->getPostParam('current_zone_template', 0) == $new_zone_template) {
            return;
        }

        $applied = $this->createZoneManagementService()->applyTemplate($zone_id, $zone_template, (int)$this->getCurrentUserId());
        if ($applied['success']) {
            $this->setMessage('edit', 'success', _('Zone template has been changed successfully.'));
            return;
        }

        $this->setMessage('edit', 'error', match ($applied['code']) {
            ZoneManagementService::ERR_READ_ONLY => _('You cannot apply a template to a read-only zone.'),
            ZoneManagementService::ERR_TEMPLATE_NOT_FOUND,
            ZoneManagementService::ERR_TEMPLATE_AMBIGUOUS,
            ZoneManagementService::ERR_TEMPLATE_FORBIDDEN => _('Invalid or unexpected input given.'),
            default => $applied['message'],
        });
    }

    private function handleRetrieveZone(int $zone_id, DomainManagerInterface $domainManager): void
    {
        // The SQL backend cannot trigger a transfer, and only a secondary has a primary to pull from
        $isSecondary = $this->domainRepository->getDomainType($zone_id) === ZoneType::SLAVE;
        if (!DnsBackendProviderFactory::isApiBackend($this->getConfig()) || !$isSecondary) {
            $this->setMessage('edit', 'error', _('Retrieving a zone from its primary needs the PowerDNS API backend and a secondary zone.'));
            return;
        }

        if ($domainManager->retrieveZone($zone_id)) {
            $this->setMessage('edit', 'success', _('Zone transfer from the primary has been requested.'));
        } else {
            $this->setMessage('edit', 'error', _('Failed to request a zone transfer from the primary. Check the PowerDNS logs for details.'));
        }
    }

    public function saveRecords(int $zone_id, string $zone_name): void
    {
        $records = $this->httpRequest->getPostParam('record');
        $serial = $this->httpRequest->getPostParam('serial');
        $zoneComment = $this->httpRequest->getPostParam('zone_comment');

        $result = $this->createZoneEditService()->save(new ZoneEditSubmission(
            $zone_id,
            $zone_name,
            (int)$this->getCurrentUserId(),
            (string)$this->userContextService->getLoggedInUsername(),
            is_array($records) ? $records : null,
            $this->httpRequest->getPostParam('form_complete') !== null,
            $serial === null ? null : (string)$serial,
            $this->httpRequest->getPostParam('changed_rows_only') === '1',
            $zoneComment === null ? null : (string)$zoneComment
        ));

        foreach ($result->errors as $error) {
            $this->addSystemMessage('error', $error);
        }
        if ($result->truncated) {
            $this->setMessage('edit', 'warning', ZoneSaveMessages::truncated());
        }
        $this->rejectedRecords = $result->rejectedRecords;
        $this->rejectedZoneComment = $result->rejectedZoneComment;

        $message = ZoneSaveMessages::forResult($result);
        if ($message !== null) {
            $this->setMessage('edit', $message[0], $message[1]);
        }
    }

    /**
     * Handle adding a new record directly from the edit page
     *
     * @param int $zone_id The ID of the zone
     * @param string $zone_name The zone the record goes into
     * @return bool True if record was added successfully, false otherwise
     */
    private function addRecord(int $zone_id, string $zone_name): bool
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

        if (!$this->doValidateRequest($this->httpRequest->getPostParams())) {
            // Store validation error directly in session
            $_SESSION[SessionKeys::ADD_RECORD_ERROR] = [
                'error' => true,
                'errorMessage' => _('Please provide all required fields.'),
                'fieldError' => !empty($this->httpRequest->getPostParam('content')) ? 'type' : 'content'
            ];

            // Don't call showFirstValidationError as it would redirect
            // We've already stored the form data for displaying error later
            return false;
        }

        $name = (string)$this->httpRequest->getPostParam('name', '');
        $content = (string)$this->httpRequest->getPostParam('content');
        $type = (string)$this->httpRequest->getPostParam('type');
        $prio = $this->httpRequest->getPostParam('prio');
        $prio = $prio !== null && $prio !== '' ? (int)$prio : 0;
        $comment = (string)$this->httpRequest->getPostParam('comment', '');

        $ttl = $this->httpRequest->getPostParam('ttl');
        $added = $this->createRecordAddService()->add(
            $zone_id,
            $zone_name,
            $name,
            $type,
            $content,
            $ttl !== null && $ttl !== '' ? (int)$ttl : null,
            $prio,
            $comment,
            (string)$this->userContextService->getLoggedInUsername(),
            RecordAddResult::companionFrom($this->httpRequest->getPostParams())
        );
        if (!$added->isOk()) {
            // Store validation error directly in session
            $_SESSION[SessionKeys::ADD_RECORD_ERROR] = [
                'error' => true,
                'errorMessage' => $added->record->message,
                'fieldError' => $added->record->field
            ];
            return false;
        }

        // Clear session data when record is successfully created
        unset($_SESSION[SessionKeys::ADD_RECORD_LAST_DATA]);
        unset($_SESSION[SessionKeys::ADD_RECORD_ERROR]);

        // Clear form data if it exists in the session
        $formToken = $this->httpRequest->getPostParam('form_token');
        if ($formToken !== null) {
            $this->formStateService->clearFormData($formToken);
        }

        [$messageType, $message] = RecordAddMessages::forAdded($added);
        $this->setMessage('edit', $messageType, $message);

        return true;
    }

    /**
     * Clear form data from session if zone has changed
     *
     * @param int $currentZoneId The current zone ID being viewed
     * @return void
     */
    private function clearFormDataOnZoneChange(int $currentZoneId): void
    {
        // Check if we have a previously stored zone ID in session for form data
        if (isset($_SESSION[SessionKeys::ADD_RECORD_ZONE_ID]) && $_SESSION[SessionKeys::ADD_RECORD_ZONE_ID] != $currentZoneId) {
            // Zone has changed, clear the form data and error information
            unset($_SESSION[SessionKeys::ADD_RECORD_LAST_DATA]);
            unset($_SESSION[SessionKeys::ADD_RECORD_ERROR]);
            unset($_SESSION[SessionKeys::ADD_RECORD_ZONE_ID]);
        }

        // Store the current zone ID for future comparisons
        $_SESSION[SessionKeys::ADD_RECORD_ZONE_ID] = $currentZoneId;
    }
}
