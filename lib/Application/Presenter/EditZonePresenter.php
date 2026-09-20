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
 */

namespace Poweradmin\Application\Presenter;

use Poweradmin\Application\Service\RejectedZoneEditPresenter;
use Poweradmin\Domain\Model\ZoneType;
use Poweradmin\Domain\Service\Dns\SOARecordManager;
use Poweradmin\Domain\Service\DnsIdnService;
use Poweradmin\Domain\Service\PdnsCapabilities;
use Poweradmin\Domain\Service\RecordTypeService;
use Poweradmin\Domain\Service\ReverseTtlResolver;
use Poweradmin\Domain\Service\ZoneAccessPolicy;

/**
 * The zone editor's view model: maps the facts the controller has already
 * resolved (zone, permissions, preferences, listing) onto the variables
 * edit.html reads. Everything derived for the page - editability flags,
 * record locks, the comment conflict, the offered record types - is computed
 * here from those inputs alone.
 */
final class EditZonePresenter
{
    /**
     * @param string $storedZoneComment The comment as the zone holds it now
     * @param ?string $rejectedZoneComment The comment a stale submission carried, when one was refused
     * @param array<int, array<string, mixed>> $records Rows as the display service produced them
     * @param array<int|string, mixed> $rejectedRecords Rows a stale submission carried, when one was refused
     * @param bool $requestsOnly The caller may only file change requests, and $permEdit is their request level
     * @param string $editMode One of the ChangeApprovalPolicy::MODE_* values
     * @param list<array<string, mixed>> $pendingChangeRequests Summaries of the zone's open requests
     * @param ?int $ptrDefaultTtl The configured dns.ttl_reverse, null when unset
     * @param array<string, int> $typeDefaultTtls Admin-configured per-type TTLs keyed by uppercase type
     */
    public function __construct(
        private readonly int $zoneId,
        private readonly string $zoneName,
        private readonly string $storedZoneComment,
        private readonly ?string $rejectedZoneComment,
        private readonly string $domainType,
        private readonly ?string $slaveMaster,
        private readonly array $zoneTemplates,
        private readonly int $zoneTemplateId,
        private readonly array $zoneTemplateDetails,
        private readonly int $recordCount,
        private readonly int $filteredRecordCount,
        private readonly array $records,
        private readonly array $rejectedRecords,
        private readonly string $soaRecord,
        private readonly bool $isReverseZone,
        private readonly bool $supportsCatalogZones,
        private readonly bool $catalogSelectorView,
        private readonly array $catalogProducers,
        private readonly ?int $catalogProducerId,
        private readonly string $catalogName,
        private readonly int $userId,
        private readonly bool $userIsZoneOwner,
        private readonly string $permView,
        private readonly string $permEdit,
        private readonly bool $requestsOnly,
        private readonly bool $permEditNsSubzone,
        private readonly string $permMetaEdit,
        private readonly bool $metaEdit,
        private readonly string $permMetadataView,
        private readonly string $permOwnershipView,
        private readonly string $logPermission,
        private readonly bool $canManageDnssec,
        private readonly bool $permZoneTemplAdd,
        private readonly bool $permIsGodlike,
        private readonly bool $permViewZoneOwn,
        private readonly bool $permViewZoneOther,
        private readonly string $editMode,
        private readonly array $pendingChangeRequests,
        private readonly bool $canReviewChangeRequests,
        private readonly bool $dnssecEnabled,
        private readonly bool $isSecured,
        private readonly bool $isPresigned,
        private readonly ?int $signedSerial,
        private readonly RecordTypeService $recordTypeService,
        private readonly ?PdnsCapabilities $recordTypeCapabilities,
        private readonly int $forwardTtl,
        private readonly ?int $ptrDefaultTtl,
        private readonly array $typeDefaultTtls,
        private readonly bool $isApiBackend,
        private readonly bool $showRecordId,
        private readonly bool $showAddRecordForm,
        private readonly bool $showRecordEditButton,
        private readonly bool $showRecordDeleteButton,
        private readonly bool $addRecordFormTop,
        private readonly bool $saveChangesTop,
        private readonly bool $displayHostnameOnly,
        private readonly bool $recordComments,
        private readonly bool $zoneComments,
        private readonly bool $requireChangeComment,
        private readonly bool $dblogUse,
        private readonly bool $addReverseRecord,
        private readonly bool $addDomainRecord,
        private readonly int $rowStart,
        private readonly int $rowAmount,
        private readonly string $recordSortBy,
        private readonly string $sortDirection,
        private readonly string $pagination,
        private readonly string $searchTerm,
        private readonly string $recordTypeFilter,
        private readonly string $contentFilter,
        private readonly string $formToken,
        private readonly ?array $formData,
        private readonly array $whoisActions,
        private readonly array $rdapActions,
        private readonly array $dnsWizardActions,
        private readonly array $exportFormats,
        private readonly bool $importEnabled,
    ) {
    }

    /**
     * The record types the add form offers for this zone.
     *
     * @return array<int, string>
     */
    public function recordTypes(): array
    {
        return $this->isReverseZone
            ? $this->recordTypeService->getReverseZoneTypes($this->dnssecEnabled, $this->recordTypeCapabilities)
            : $this->recordTypeService->getDomainZoneTypes($this->dnssecEnabled, $this->recordTypeCapabilities);
    }

    /**
     * @return array<string, mixed> The variables edit.html reads
     */
    public function toTemplateVariables(): array
    {
        $zoneTypes = ZoneType::getTypes();
        $zoneIsReadOnly = ZoneType::isReadOnly($this->domainType);
        // A requester gets the same inputs as an editor; the save files a request instead
        $userCanEditZone = $this->requestsOnly || ZoneAccessPolicy::canEditZone($this->permEdit, $this->userIsZoneOwner);
        $metadataView = ZoneAccessPolicy::levelAppliesToZone($this->permMetadataView, $this->userIsZoneOwner);

        $records = RecordLockPresenter::decorate(
            $this->records,
            $this->zoneName,
            $this->permEdit,
            $this->permEditNsSubzone,
            $zoneIsReadOnly
        );
        $staleFormDropped = RejectedZoneEditPresenter::restore($records, $this->rejectedRecords);

        $zoneComment = $this->storedZoneComment;
        $zoneCommentConflict = false;
        if ($this->rejectedZoneComment !== null) {
            // The retry writes the submitted comment over the stored one, so say what
            // the zone holds when another writer has changed it in the meantime.
            $zoneCommentConflict = $this->storedZoneComment !== $this->rejectedZoneComment;
            $zoneComment = $this->rejectedZoneComment;
        }

        $recordTypes = $this->recordTypes();

        return [
            'zone_id' => $this->zoneId,
            'zone_name' => $this->zoneName,
            'zone_name_to_display' => $this->zoneName,
            'idn_zone_name' => DnsIdnService::toIdnAlias($this->zoneName),
            'zone_display_name' => DnsIdnService::toDisplay($this->zoneName),
            'zone_comment' => $zoneComment,
            'zone_comment_conflict' => $zoneCommentConflict,
            'stored_zone_comment' => $this->storedZoneComment,
            'domain_type' => $this->domainType,
            'slave_master' => $this->slaveMaster,
            'zone_types' => $zoneTypes,
            'zone_replicates_from_primary' => ZoneType::replicatesFromPrimary($this->domainType),
            // Only the API backend can ask PowerDNS for a transfer, so the button is hidden otherwise
            'can_retrieve_zone' => $this->isApiBackend && $this->domainType === ZoneType::SLAVE && ($this->slaveMaster ?? '') !== '',
            // Catalog kinds are absent from the basic types, so the browser would preselect
            // the first option and one click would silently retype the zone.
            'zone_type_change_allowed' => in_array($this->domainType, $zoneTypes, true),
            'catalog_members_view' => $this->domainType === ZoneType::PRODUCER && $metadataView && $this->supportsCatalogZones,
            'catalog_selector_view' => $this->catalogSelectorView,
            'catalog_producers' => $this->catalogProducers,
            'catalog_producer_id' => $this->catalogProducerId,
            // Non-empty with a null producer id means the zone is in a catalog whose
            // producer this install does not manage. Shown so it is not silently lost.
            'catalog_name' => $this->catalogName,
            'zone_templates' => $this->zoneTemplates,
            'zone_template_id' => $this->zoneTemplateId,
            'zone_template_details' => $this->zoneTemplateDetails,
            'record_count' => $this->recordCount,
            'filtered_record_count' => $this->filteredRecordCount,
            'records' => $records,
            'stale_form_dropped' => $staleFormDropped,
            'perm_view' => $this->permView,
            'perm_edit' => $this->permEdit,
            'perm_edit_ns_subzone' => $this->permEditNsSubzone,
            'perm_meta_edit' => $this->permMetaEdit,
            'meta_edit' => $this->metaEdit,
            'metadata_view' => $metadataView,
            'ownership_view' => ZoneAccessPolicy::levelAppliesToZone($this->permOwnershipView, $this->userIsZoneOwner),
            'zone_is_read_only' => $zoneIsReadOnly,
            'user_can_edit_zone' => $userCanEditZone,
            'zone_is_editable' => $userCanEditZone && !$zoneIsReadOnly,
            'can_edit_records' => $this->permEdit !== 'none',
            'edit_mode' => $this->editMode,
            'require_change_comment' => $this->requireChangeComment,
            'pending_change_requests' => $this->pendingChangeRequests,
            'can_review_change_requests' => $this->pendingChangeRequests !== [] && $this->canReviewChangeRequests,
            'can_view_zone_logs' => ZoneAccessPolicy::levelAppliesToZone($this->logPermission, $this->userIsZoneOwner),
            'can_manage_dnssec' => $this->canManageDnssec,
            'perm_zone_templ_add' => $this->permZoneTemplAdd,
            'perm_is_godlike' => $this->permIsGodlike,
            'dblog_use' => $this->dblogUse,
            'perm_view_zone_own' => $this->permViewZoneOwn,
            'perm_view_zone_other' => $this->permViewZoneOther,
            'user_is_zone_owner' => $this->userIsZoneOwner,
            'row_start' => $this->rowStart,
            'row_amount' => $this->rowAmount,
            'record_sort_by' => $this->recordSortBy,
            'sort_direction' => $this->sortDirection,
            'pagination' => $this->pagination,
            'pdnssec_use' => $this->dnssecEnabled,
            'is_secured' => $this->isSecured,
            'is_presigned' => $this->isPresigned,
            'signed_serial' => $this->signedSerial,
            'session_userid' => $this->userId,
            // Form pre-fill stays on dns.ttl; JS updateTtlForType() swaps in dns.ttl_reverse
            // for PTR selections so display tracks what's persisted.
            'dns_ttl' => $this->forwardTtl,
            'default_ttl' => $this->forwardTtl,
            'ptr_default_ttl' => $this->ptrDefaultTtl,
            'type_default_ttls' => $this->typeDefaultTtls,
            'ttl_defaults_by_type' => ReverseTtlResolver::ttlsForTypes($recordTypes, $this->typeDefaultTtls, $this->forwardTtl, $this->ptrDefaultTtl, $this->isReverseZone),
            'is_reverse_zone' => $this->isReverseZone,
            'record_types' => $recordTypes,
            'iface_add_reverse_record' => $this->addReverseRecord,
            'iface_add_domain_record' => $this->addDomainRecord,
            // API-backend records have no numeric ID, only an opaque composite identifier;
            // showing it as a column is unreadable, so suppress it regardless of preference.
            'iface_edit_show_id' => $this->showRecordId && !$this->isApiBackend,
            'iface_show_add_record_form' => $this->showAddRecordForm,
            'iface_show_record_edit_button' => $this->showRecordEditButton,
            'iface_show_record_delete_button' => $this->showRecordDeleteButton,
            'iface_edit_add_record_top' => $this->addRecordFormTop,
            'iface_edit_save_changes_top' => $this->saveChangesTop,
            'iface_record_comments' => $this->recordComments,
            'iface_zone_comments' => $this->zoneComments,
            'serial' => SOARecordManager::getSOASerial($this->soaRecord),
            'whois_actions' => $this->whoisActions,
            'rdap_actions' => $this->rdapActions,
            'form_token' => $this->formToken,
            'form_data' => $this->formData,
            'search_term' => $this->searchTerm,
            'record_type_filter' => $this->recordTypeFilter,
            'content_filter' => $this->contentFilter,
            'display_hostname_only' => $this->displayHostnameOnly,
            'dns_wizard_actions' => $this->dnsWizardActions,
            'export_formats' => $this->exportFormats,
            'import_enabled' => $this->importEnabled,
        ];
    }
}
