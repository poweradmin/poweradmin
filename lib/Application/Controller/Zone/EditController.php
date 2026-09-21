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

namespace Poweradmin\Application\Controller\Zone;

use Poweradmin\Application\Controller\BaseController;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Application\Http\ZoneEditIntent;
use Poweradmin\Application\Presenter\EditZonePresenter;
use Poweradmin\Application\Presenter\RecordFormFieldPresenter;
use Poweradmin\Application\Presenter\ChangeRequestPresenter;
use Poweradmin\Application\Service\DnsBackendProviderFactory;
use Poweradmin\Application\Service\ChangeRequestMessages;
use Poweradmin\Application\Service\RecordAddMessages;
use Poweradmin\Application\Service\RecordAddResult;
use Poweradmin\Application\Service\ZoneSaveMessages;
use Poweradmin\Application\Service\ZoneSigningMessages;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Service\Dns\RecordTypeService;
use Poweradmin\Domain\Model\ZoneType;
use Poweradmin\Domain\Service\Zone\CatalogZoneService;
use Poweradmin\Domain\Service\Zone\ChangeApprovalPolicy;
use Poweradmin\Domain\Service\Auth\ZoneAccessPolicy;
use Poweradmin\Domain\Service\Zone\ZoneChangeRequestResult;
use Poweradmin\Domain\Service\Zone\ZoneEditSubmission;
use Poweradmin\Domain\Service\Zone\ZoneManagementService;
use Poweradmin\Domain\Service\Dns\DomainManagerInterface;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Infrastructure\Session\FormStateService;
use Poweradmin\Domain\Service\Dns\RecordDisplayService;
use Poweradmin\Domain\Service\Dns\ReverseTtlResolver;
use Poweradmin\Domain\Service\Auth\UserContextService;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\RecordListingInterface;
use Poweradmin\Domain\Repository\ZoneReadRepositoryInterface;
use Poweradmin\Domain\Service\Auth\SessionKeys;
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
    private RecordListingInterface $recordRepository;
    private DomainRepositoryInterface $domainRepository;
    /** Rows and comment from a submission rejected as stale, so the re-render can restore them. */
    private array $rejectedRecords = [];
    private ?string $rejectedZoneComment = null;

    public function __construct(array $request)
    {
        parent::__construct($request);
        $this->recordRepository = $this->services()->recordRepository();
        $this->domainRepository = $this->services()->domainRepository();
        $this->recordTypeService = new RecordTypeService($this->getConfig());
        $this->formStateService = new FormStateService();
        $this->soaRecordManager = $this->services()->soaRecordManager();
        $this->reverseTtlResolver = $this->services()->reverseTtlResolver();
        $this->userContextService = new UserContextService();
        $this->zoneRepository = $this->services()->zoneRepository();

        $this->permissionService = $this->services()->permissionService();
    }

    public function run(): void
    {
        // Set the current page for navigation highlighting
        $this->setCurrentPage('edit');
        $this->setPageTitle(_('Edit zone'));

        $userId = $this->getCurrentUserId();
        $iface_rowamount = $this->resolveRowsPerPage();

        // Get user preferences for form positioning
        $userPreferenceService = $this->services()->userPreferenceService();
        $iface_edit_add_record_top = $userPreferenceService->getRecordFormPosition($userId) === 'top';
        $iface_edit_save_changes_top = $userPreferenceService->getSaveButtonPosition($userId) === 'top';
        $isApiBackend = DnsBackendProviderFactory::isApiBackend($this->getConfig());
        $iface_show_id = $userPreferenceService->getShowRecordId($userId);
        $iface_show_add_record_form = $userPreferenceService->getShowAddRecordForm($userId);
        $iface_show_record_edit_button = $userPreferenceService->getShowRecordEditButton($userId);
        $iface_show_record_delete_button = $userPreferenceService->getShowRecordDeleteButton($userId);
        $display_hostname_only = $userPreferenceService->getDisplayHostnameOnly($userId);

        $iface_record_comments = $this->config->get('interface', 'show_record_comments', false);
        $iface_zone_comments = $this->config->get('interface', 'show_zone_comments', true);

        // Initialize filter parameters
        $searchTerm = $this->httpRequest->getQueryParam('search', '');
        $recordTypeFilter = $this->httpRequest->getQueryParam('record_type', '');
        $contentFilter = $this->httpRequest->getQueryParam('content', '');

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

        [$record_sort_by, $sort_direction] = $this->createZoneSortingService()->getZoneSortOrder(
            ['id', 'name', 'type', 'content', 'prio', 'ttl', 'disabled'],
            SessionKeys::EDIT_RECORD_SORT_BY,
            submittedSortBy: $this->httpRequest->getPostParam('record_sort_by') ?? $this->httpRequest->getQueryParam('record_sort_by'),
            submittedDirection: $this->httpRequest->getPostParam('sort_direction') ?? $this->httpRequest->getQueryParam('sort_direction')
        );

        $zone_id = $this->requireNumericParam('id');

        // Clear session-based form data if zone has changed to prevent persistence across zones
        $this->formStateService->trackAddRecordZone($zone_id);

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

        // Process form submissions; which form arrived is decided in one place
        $postParams = $this->httpRequest->getPostParams();
        $intent = ZoneEditIntent::from($this->isPost(), $postParams);

        // A committed POST carrying the inline add form stashes its values before
        // processing, so a failed validation can re-display them (a truncated POST
        // never did, and still must not)
        if (($intent === ZoneEditIntent::ADD_RECORD || $intent === ZoneEditIntent::SAVE_RECORDS) && ZoneEditIntent::hasAddRecordFields($postParams)) {
            $this->rememberAddRecordForm($isReverseZone);
        }

        $edit_mode = $this->changeApprovalModeForZone($zone_id);

        if ($intent === ZoneEditIntent::ADD_RECORD) {
            // Handle record addition directly in edit controller (no redirect)
            $result = $edit_mode === ChangeApprovalPolicy::MODE_REQUEST
                ? $this->requestRecordAdd($zone_id, $zone_name)
                : $this->addRecord($zone_id, $zone_name);

            // If the record was added successfully, clear the stored data
            if ($result) {
                $this->formStateService->forgetAddRecordForm();
            } elseif (!$formData) {
                // Re-display the refused submission with its error
                $formData = $this->formStateService->addRecordFormWithError() ?? $formData;
            }
        } elseif ($intent === ZoneEditIntent::SAVE_RECORDS || $intent === ZoneEditIntent::SAVE_TRUNCATED) {
            // SAVE_TRUNCATED: max_input_vars dropped the bottom save button; run the
            // save anyway so incomplete rows are skipped and the operator is warned.
            if ($edit_mode === ChangeApprovalPolicy::MODE_REQUEST) {
                $this->requestRecordEdits($zone_id, $zone_name);
            } else {
                $this->saveRecords($zone_id, $zone_name);
            }
        }

        // If we have stored validation error data from a previous request, use it
        if (!$formData) {
            $formData = $this->formStateService->addRecordFormWithError() ?? $formData;
        }

        // Permission levels - use zone-aware checking for group permission support
        $perm_edit = $this->permissionService->getEditPermissionLevelForZone($userId, $zone_id);
        $perm_meta_edit = $this->permissionService->getZoneMetaEditPermissionLevel($userId);
        $meta_edit = ZoneAccessPolicy::levelAppliesToZone($perm_meta_edit, $user_is_zone_owner);
        $can_manage_dnssec = $this->permissionService->canManageDnssecForZone($userId, $zone_id);

        $this->requireZoneView($zone_id);

        if ($this->isPost() && $meta_edit) {
            $this->handleZoneMetadataPost($zone_id);
        }

        if (!$this->handleSigningRequest($zone_id, $zone_name, $can_manage_dnssec)) {
            return;
        }

        $domain_type = $this->domainRepository->getDomainType($zone_id);

        // Only zones PowerDNS would actually publish from a catalog get the selector,
        // so nothing below runs for the kinds that would discard the result.
        $supports_catalog_zones = $this->getPdnsCapabilities()->supportsCatalogZones();
        $catalog_selector_view = $supports_catalog_zones && in_array($domain_type, CatalogZoneService::PUBLISHABLE_KINDS, true);

        // Read after the record listing above: in API mode the zone body is already
        // held, so the catalog read costs nothing extra here.
        $catalog_service = $this->services()->catalogZoneService();
        $catalog_name = $catalog_selector_view ? $catalog_service->getCatalog($zone_id) : '';
        $catalog_producer = $catalog_name !== '' ? $catalog_service->getCatalogProducer($zone_id) : null;
        $catalog_producers = $catalog_selector_view && $meta_edit ? $catalog_service->getManageableProducers($userId) : [];

        $zone_template_id = $this->services()->zoneTemplateRepository()->getTemplateIdForZone($zone_id);

        // Get records via DnsDataService (supports both SQL and API backends)
        $recordResult = $this->services()->dnsDataService()->getZoneRecords(
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
        $total_filtered_count = $recordResult['total'];

        $isDnsSecEnabled = $this->config->get('dnssec', 'enabled', false);
        $dnssecProvider = $this->services()->dnssecProvider();
        $is_secured = $dnssecProvider->isZoneSecured($zone_name, $this->getConfig());
        // Presigned zones always report secured, so unsigned zones skip the metadata lookup
        $is_presigned = $is_secured && $dnssecProvider->isZonePresigned($zone_name);
        // Serial as served by PowerDNS (SOA-EDIT applied); only relevant for signed zones
        $signed_serial = ($isDnsSecEnabled && $is_secured) ? $dnssecProvider->getEditedSerial($zone_name) : null;

        // Transform records for display using the RecordDisplayService
        $displayRecords = (new RecordDisplayService($display_hostname_only))->transformRecords($recordResult['records'], $zone_name);

        // A requester gets the same inputs as an editor; the save files a request instead
        $requests_only = $edit_mode === ChangeApprovalPolicy::MODE_REQUEST && !ZoneAccessPolicy::canEditZone($perm_edit, $user_is_zone_owner);
        if ($requests_only) {
            $perm_edit = $this->permissionService->getChangeRequestPermissionLevelForZone($userId, $zone_id);
        }
        $pending_change_requests = $this->changeApproval()->enabled() && $perm_edit !== 'none'
            ? ChangeRequestPresenter::summaries($this->services()->zoneChangeRequestRepository()->listPendingForZone($zone_id))
            : [];

        $presenter = new EditZonePresenter(
            zoneId: $zone_id,
            zoneName: $zone_name,
            // Twig escapes this for the textarea. Escaping it here as well would put the
            // entities in front of the operator and save them back on the next submit.
            storedZoneComment: (string)$this->zoneRepository->getZoneComment($zone_id),
            rejectedZoneComment: $this->rejectedZoneComment,
            domainType: $domain_type,
            slaveMaster: $this->domainRepository->getDomainMaster($zone_id),
            zoneTemplates: $this->services()->zoneTemplateService()->getListZoneTempl($userId),
            zoneTemplateId: $zone_template_id,
            zoneTemplateDetails: $this->services()->zoneTemplateRepository()->getZoneTemplateDetails($zone_template_id) ?: [],
            recordCount: $this->recordRepository->countZoneRecords($zone_id),
            filteredRecordCount: $total_filtered_count,
            records: $displayRecords,
            rejectedRecords: $this->rejectedRecords,
            soaRecord: $this->soaRecordManager->getSOARecord($zone_id),
            isReverseZone: $isReverseZone,
            supportsCatalogZones: $supports_catalog_zones,
            catalogSelectorView: $catalog_selector_view,
            catalogProducers: $catalog_producers,
            catalogProducerId: $catalog_producer['id'] ?? null,
            catalogName: $catalog_name,
            userId: $userId,
            userIsZoneOwner: $user_is_zone_owner,
            permView: $perm_view,
            permEdit: $perm_edit,
            requestsOnly: $requests_only,
            permEditNsSubzone: $this->hasPermission(Permission::PERM_EDIT_NS_SUBZONE),
            permMetaEdit: $perm_meta_edit,
            metaEdit: $meta_edit,
            permMetadataView: $this->permissionService->getZoneMetadataViewPermissionLevel($userId),
            permOwnershipView: $this->permissionService->getZoneOwnershipViewPermissionLevel($userId),
            logPermission: $this->permissionService->getZoneLogPermissionLevel($userId),
            canManageDnssec: $can_manage_dnssec,
            permZoneTemplAdd: $this->permissionService->canAddZoneTemplates($userId),
            permIsGodlike: $this->permissionService->isAdmin($userId),
            permViewZoneOwn: $this->hasPermission(Permission::PERM_ZONE_CONTENT_VIEW_OWN),
            permViewZoneOther: $this->hasPermission(Permission::PERM_ZONE_CONTENT_VIEW_OTHERS),
            editMode: $edit_mode,
            pendingChangeRequests: $pending_change_requests,
            canReviewChangeRequests: $pending_change_requests !== [] && $this->changeApproval()->canReviewZone($this->getCurrentUserId(), $zone_id),
            dnssecEnabled: (bool)$isDnsSecEnabled,
            isSecured: $is_secured,
            isPresigned: $is_presigned,
            signedSerial: $signed_serial,
            recordTypeService: $this->recordTypeService,
            recordTypeCapabilities: $this->getRecordTypeCapabilities(),
            forwardTtl: $this->reverseTtlResolver->getForwardTtl(),
            ptrDefaultTtl: $this->reverseTtlResolver->getConfiguredReverseTtl(),
            typeDefaultTtls: $this->reverseTtlResolver->getTypeDefaults(),
            isApiBackend: $isApiBackend,
            showRecordId: $iface_show_id,
            showAddRecordForm: $iface_show_add_record_form,
            showRecordEditButton: $iface_show_record_edit_button,
            showRecordDeleteButton: $iface_show_record_delete_button,
            addRecordFormTop: $iface_edit_add_record_top,
            saveChangesTop: $iface_edit_save_changes_top,
            displayHostnameOnly: $display_hostname_only,
            recordComments: (bool)$iface_record_comments,
            zoneComments: (bool)$iface_zone_comments,
            requireChangeComment: (bool)$this->config->get('logging', 'require_change_comment', false),
            dblogUse: (bool)$this->config->get('logging', 'database_enabled', false),
            addReverseRecord: (bool)$this->config->get('interface', 'add_reverse_record', true),
            addDomainRecord: (bool)$this->config->get('interface', 'add_domain_record', true),
            rowStart: $row_start,
            rowAmount: $iface_rowamount,
            recordSortBy: $record_sort_by,
            sortDirection: $sort_direction,
            pagination: $this->presentPagination($total_filtered_count, $iface_rowamount, '/zones/' . $zone_id . '/edit?start={PageNumber}', [
                'search' => $this->httpRequest->getQueryParam('search'),
                'record_type' => $this->httpRequest->getQueryParam('record_type'),
                'content' => $this->httpRequest->getQueryParam('content'),
            ]),
            searchTerm: $searchTerm,
            recordTypeFilter: $recordTypeFilter,
            contentFilter: $contentFilter,
            formToken: $formToken,
            formData: $formData,
            whoisActions: $this->moduleCapabilityData('whois_lookup', ['zone_id' => $zone_id]),
            rdapActions: $this->moduleCapabilityData('rdap_lookup', ['zone_id' => $zone_id]),
            dnsWizardActions: $this->moduleCapabilityData('dns_wizard', ['zone_id' => $zone_id]),
            exportFormats: $this->moduleCapabilityData('zone_export', ['zone_id' => $zone_id]),
            importEnabled: $this->moduleProvides('zone_import'),
        );

        $this->render('edit.html', $presenter->toTemplateVariables());
    }

    /**
     * Stash the inline add form's values so a failed validation can re-display them.
     */
    private function rememberAddRecordForm(bool $isReverseZone): void
    {
        $prio = $this->httpRequest->getPostParam('prio');
        $ttl = $this->httpRequest->getPostParam('ttl');
        $type = (string)$this->httpRequest->getPostParam('type');
        $this->formStateService->rememberAddRecordForm([
            'name' => $this->httpRequest->getPostParam('name'),
            'content' => $this->httpRequest->getPostParam('content'),
            'type' => $type,
            'prio' => $prio !== null && $prio !== '' ? (int)$prio : 0,
            'ttl' => $ttl !== null && $ttl !== '' ? (int)$ttl : $this->reverseTtlResolver->resolveTtlForType($type, $isReverseZone),
            'comment' => $this->httpRequest->getPostParam('comment', '')
        ]);
    }

    /**
     * Join or leave a catalog. The producer arrives as a zone id so the service can
     * resolve its name and check rights on it, rather than trusting a posted name.
     */
    /**
     * Sign or unsign the zone when the page posted either button.
     *
     * @return bool False when the caller must stop because a redirect was sent
     */
    private function handleSigningRequest(int $zone_id, string $zone_name, bool $canManageDnssec): bool
    {
        // Both buttons are read, in the order the page posted them, so a request
        // carrying each one behaves exactly as the two separate blocks did
        $requested = [];
        if ($this->httpRequest->getPostParam('sign_zone') !== null) {
            $requested[] = 'sign';
        }
        if ($this->httpRequest->getPostParam('unsign_zone') !== null) {
            $requested[] = 'unsign';
        }
        if ($requested === []) {
            return true;
        }

        if (!$canManageDnssec) {
            $this->setMessage('edit', 'error', _('You do not have permission to manage DNSSEC for this zone.'));
            $this->redirect('/zones/' . $zone_id . '/edit');
            return false;
        }

        $service = $this->services()->zoneSigningService();
        foreach ($requested as $action) {
            [$type, $message] = $action === 'sign'
                ? ZoneSigningMessages::forSign($service->sign($zone_id, $zone_name))
                : ZoneSigningMessages::forUnsign($service->unsign($zone_id, $zone_name));
            $this->setMessage('edit', $type, $message);
        }

        return true;
    }

    private function handleCatalogChange(int $zone_id): void
    {
        $userId = $this->userContextService->getLoggedInUserId();
        $catalogService = $this->services()->catalogZoneService();
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
        $domainManager = $this->services()->domainManager();
        $new_type = $this->httpRequest->getPostParam('newtype', '');
        if ($this->httpRequest->getPostParam('type_change') !== null && in_array($new_type, ZoneType::getTypes())) {
            // Converting a zone is equivalent to creating one of the target type.
            if (!$this->permissionService->canCreateZone((int)$this->getCurrentUserId(), $new_type)) {
                $this->setMessage('edit', 'error', _('You do not have permission to change this zone to that type.'));
                return;
            }
            $this->reportZoneWrite('edit', $domainManager->changeZoneType($new_type, $zone_id), _('Zone type has been changed successfully.'));
        }

        if ($this->httpRequest->getPostParam('slave_master_change') !== null) {
            $this->reportZoneWrite('edit', $domainManager->changeZoneSlaveMaster($zone_id, $this->httpRequest->getPostParam('new_master', '')), _('Slave master has been changed successfully.'));
        }

        if ($this->httpRequest->getPostParam('retrieve_zone') !== null) {
            $this->handleRetrieveZone($zone_id, $domainManager);
        }

        if ($this->httpRequest->getPostParam('catalog_change') !== null) {
            $this->handleCatalogChange($zone_id);
        }

        if ($this->httpRequest->getPostParam('template_change') !== null) {
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
        // Applying a template rewrites records directly, which a reviewed zone does not allow
        if ($this->changeApprovalModeForZone($zone_id) === ChangeApprovalPolicy::MODE_REQUEST) {
            $this->setMessage('edit', 'error', ChangeRequestMessages::requiresApproval());
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

    /**
     * Files the edited rows as a change request instead of writing them.
     */
    private function requestRecordEdits(int $zone_id, string $zone_name): void
    {
        $records = $this->httpRequest->getPostParam('record');
        $serial = $this->httpRequest->getPostParam('serial');
        $zoneComment = $this->httpRequest->getPostParam('zone_comment');

        $result = $this->services()->zoneChangeRequestService()->fileRecordEdits(new ZoneEditSubmission(
            $zone_id,
            $zone_name,
            (int)$this->getCurrentUserId(),
            (string)$this->userContextService->getLoggedInUsername(),
            is_array($records) ? $records : null,
            $this->httpRequest->getPostParam('form_complete') !== null,
            $serial === null ? null : (string)$serial,
            $this->httpRequest->getPostParam('changed_rows_only') === '1',
            $zoneComment === null ? null : (string)$zoneComment
        ), $this->requestComment());

        if ($result->success) {
            $this->setMessage('edit', 'success', ChangeRequestMessages::submitted());
            $this->redirect('/zones/' . $zone_id . '/edit');
            return;
        }

        foreach ($result->errors as $error) {
            $this->addSystemMessage('error', $error);
        }
        $this->setMessage('edit', $result->code === ZoneChangeRequestResult::CODE_NO_CHANGES ? 'info' : 'error', ChangeRequestMessages::forResult($result));
    }

    /**
     * Files the inline add form as a change request. Returns true when it was filed.
     */
    private function requestRecordAdd(int $zone_id, string $zone_name): bool
    {
        $ttl = $this->httpRequest->getPostParam('ttl');
        $prio = $this->httpRequest->getPostParam('prio');
        $result = $this->services()->zoneChangeRequestService()->fileRecordAdd($zone_id, $zone_name, [
            'name' => (string)$this->httpRequest->getPostParam('name', ''),
            'type' => (string)$this->httpRequest->getPostParam('type', ''),
            'content' => (string)$this->httpRequest->getPostParam('content', ''),
            'ttl' => $ttl !== null && $ttl !== '' ? (int)$ttl : null,
            'prio' => $prio !== null && $prio !== '' ? (int)$prio : 0,
            'comment' => (string)$this->httpRequest->getPostParam('comment', ''),
        ], (int)$this->getCurrentUserId(), (string)$this->userContextService->getLoggedInUsername(), $this->requestComment());

        if (!$result->success) {
            $this->formStateService->rememberAddRecordError([
                'error' => true,
                'errorMessage' => ChangeRequestMessages::forResult($result),
                'fieldError' => 'content',
            ]);
            return false;
        }

        $this->formStateService->forgetAddRecordForm();
        $this->setMessage('edit', 'success', ChangeRequestMessages::submitted());
        $this->redirect('/zones/' . $zone_id . '/edit');

        return true;
    }

    private function requestComment(): ?string
    {
        $comment = trim((string)$this->httpRequest->getPostParam('request_comment', ''));

        return $comment === '' ? null : $comment;
    }

    public function saveRecords(int $zone_id, string $zone_name): void
    {
        $records = $this->httpRequest->getPostParam('record');
        $serial = $this->httpRequest->getPostParam('serial');
        $zoneComment = $this->httpRequest->getPostParam('zone_comment');

        $result = $this->services()->zoneEditService()->save(new ZoneEditSubmission(
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
            $this->formStateService->rememberAddRecordError([
                'error' => true,
                'errorMessage' => _('Please provide all required fields.'),
                'fieldError' => !empty($this->httpRequest->getPostParam('content')) ? 'type' : 'content'
            ]);

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
        $added = $this->services()->recordAddService()->add(
            $zone_id,
            $zone_name,
            $name,
            $type,
            $content,
            $ttl !== null && $ttl !== '' ? (int)$ttl : null,
            $prio,
            $comment,
            (int)$this->getCurrentUserId(),
            (string)$this->userContextService->getLoggedInUsername(),
            RecordAddResult::companionFrom($this->httpRequest->getPostParams())
        );
        if (!$added->isOk()) {
            $this->formStateService->rememberAddRecordError([
                'error' => true,
                'errorMessage' => $added->record->message,
                'fieldError' => RecordFormFieldPresenter::fieldId($added->record->field, (string)$added->record->message)
            ]);
            return false;
        }

        // Clear session data when record is successfully created
        $this->formStateService->forgetAddRecordForm();

        // Clear form data if it exists in the session
        $formToken = $this->httpRequest->getPostParam('form_token');
        if ($formToken !== null) {
            $this->formStateService->clearFormData($formToken);
        }

        [$messageType, $message] = RecordAddMessages::forAdded($added);
        $this->setMessage('edit', $messageType, $message);

        return true;
    }
}
