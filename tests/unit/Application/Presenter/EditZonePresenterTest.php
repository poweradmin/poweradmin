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

namespace Poweradmin\Tests\Unit\Application\Presenter;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Presenter\EditZonePresenter;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Service\Zone\ChangeApprovalPolicy;
use Poweradmin\Domain\Service\Zone\ZoneEditRow;
use Poweradmin\Domain\Service\Dns\RecordTypeService;

/**
 * The zone editor's view model: pins the flags and lists the edit page derives
 * from the facts the controller resolves, so the template contract holds.
 */
class EditZonePresenterTest extends TestCase
{
    private function present(array $overrides = []): array
    {
        return $this->presenter($overrides)->toTemplateVariables();
    }

    private function presenter(array $overrides = []): EditZonePresenter
    {
        $config = $this->createMock(ConfigurationInterface::class);
        $config->method('get')->willReturn(null);

        $defaults = [
            'zoneId' => 42,
            'zoneName' => 'example.com',
            'storedZoneComment' => 'stored',
            'rejectedZoneComment' => null,
            'domainType' => 'MASTER',
            'slaveMaster' => null,
            'zoneTemplates' => [],
            'zoneTemplateId' => 0,
            'zoneTemplateDetails' => [],
            'recordCount' => 3,
            'filteredRecordCount' => 3,
            'records' => [
                ['id' => 1, 'name' => 'example.com', 'type' => 'SOA', 'content' => 'ns1.example.com hostmaster.example.com 2024010101 10800 3600 604800 3600'],
                ['id' => 2, 'name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1'],
            ],
            'rejectedRecords' => [],
            'soaRecord' => 'ns1.example.com hostmaster.example.com 2024010101 10800 3600 604800 3600',
            'isReverseZone' => false,
            'supportsCatalogZones' => true,
            'catalogSelectorView' => true,
            'catalogProducers' => [],
            'catalogProducerId' => null,
            'catalogName' => '',
            'userId' => 7,
            'userIsZoneOwner' => true,
            'permView' => 'all',
            'permEdit' => 'all',
            'requestsOnly' => false,
            'permEditNsSubzone' => false,
            'permMetaEdit' => 'all',
            'metaEdit' => true,
            'permMetadataView' => 'own',
            'permOwnershipView' => 'none',
            'logPermission' => 'own',
            'canManageDnssec' => true,
            'permZoneTemplAdd' => false,
            'permIsGodlike' => false,
            'permViewZoneOwn' => true,
            'permViewZoneOther' => false,
            'editMode' => ChangeApprovalPolicy::MODE_DIRECT,
            'pendingChangeRequests' => [],
            'canReviewChangeRequests' => false,
            'dnssecEnabled' => false,
            'isSecured' => false,
            'isPresigned' => false,
            'signedSerial' => null,
            'recordTypeService' => new RecordTypeService($config),
            'recordTypeCapabilities' => null,
            'forwardTtl' => 86400,
            'ptrDefaultTtl' => null,
            'typeDefaultTtls' => [],
            'isApiBackend' => false,
            'showRecordId' => true,
            'showAddRecordForm' => true,
            'showRecordEditButton' => true,
            'showRecordDeleteButton' => true,
            'addRecordFormTop' => true,
            'saveChangesTop' => false,
            'displayHostnameOnly' => false,
            'recordComments' => false,
            'zoneComments' => true,
            'requireChangeComment' => false,
            'dblogUse' => false,
            'addReverseRecord' => true,
            'addDomainRecord' => true,
            'rowStart' => 0,
            'rowAmount' => 50,
            'recordSortBy' => 'name',
            'sortDirection' => 'ASC',
            'pagination' => '<nav></nav>',
            'searchTerm' => '',
            'recordTypeFilter' => '',
            'contentFilter' => '',
            'formToken' => 'add_record_abc',
            'formData' => null,
            'whoisActions' => [],
            'rdapActions' => [],
            'dnsWizardActions' => [],
            'exportFormats' => [],
            'importEnabled' => false,
        ];

        return new EditZonePresenter(...array_replace($defaults, $overrides));
    }

    public function testPassesZoneFactsThroughAndDerivesTheDisplayNames(): void
    {
        $vars = $this->present(['zoneName' => 'xn--bcher-kva.example']);

        $this->assertSame(42, $vars['zone_id']);
        $this->assertSame('xn--bcher-kva.example', $vars['zone_name']);
        $this->assertSame('xn--bcher-kva.example', $vars['zone_name_to_display']);
        $this->assertSame('bücher.example', $vars['idn_zone_name']);
        $this->assertSame('bücher.example', $vars['zone_display_name']);
        $this->assertSame('2024010101', $vars['serial']);
        $this->assertSame(7, $vars['session_userid']);
    }

    public function testDirectModeEditorMayEditARegularZone(): void
    {
        $vars = $this->present();

        $this->assertSame('all', $vars['perm_edit']);
        $this->assertTrue($vars['user_can_edit_zone']);
        $this->assertFalse($vars['zone_is_read_only']);
        $this->assertTrue($vars['zone_is_editable']);
        $this->assertTrue($vars['can_edit_records']);
        $this->assertSame(ChangeApprovalPolicy::MODE_DIRECT, $vars['edit_mode']);
    }

    public function testOwnLevelEditorOnlyEditsAnOwnedZone(): void
    {
        $owned = $this->present(['permEdit' => 'own', 'userIsZoneOwner' => true]);
        $foreign = $this->present(['permEdit' => 'own', 'userIsZoneOwner' => false]);

        $this->assertTrue($owned['user_can_edit_zone']);
        $this->assertTrue($owned['zone_is_editable']);
        $this->assertFalse($foreign['user_can_edit_zone']);
        $this->assertFalse($foreign['zone_is_editable']);
        // The level itself still grants row editing, which the template uses for the inputs
        $this->assertTrue($foreign['can_edit_records']);
    }

    public function testRequestModeTreatsTheRequesterAsAnEditor(): void
    {
        // The controller swapped perm_edit for the change request level before building the presenter
        $vars = $this->present([
            'permEdit' => 'own',
            'userIsZoneOwner' => false,
            'requestsOnly' => true,
            'editMode' => ChangeApprovalPolicy::MODE_REQUEST,
        ]);

        $this->assertSame('own', $vars['perm_edit']);
        $this->assertTrue($vars['user_can_edit_zone']);
        $this->assertTrue($vars['zone_is_editable']);
        $this->assertTrue($vars['can_edit_records']);
        $this->assertSame(ChangeApprovalPolicy::MODE_REQUEST, $vars['edit_mode']);
    }

    public function testNoEditLevelLeavesTheRecordsReadOnly(): void
    {
        $vars = $this->present(['permEdit' => 'none']);

        $this->assertFalse($vars['user_can_edit_zone']);
        $this->assertFalse($vars['zone_is_editable']);
        $this->assertFalse($vars['can_edit_records']);
    }

    public function testSecondaryZoneIsReadOnlyEvenForAFullEditor(): void
    {
        $vars = $this->present(['domainType' => 'SLAVE', 'slaveMaster' => '192.0.2.10']);

        $this->assertTrue($vars['zone_is_read_only']);
        $this->assertTrue($vars['user_can_edit_zone']);
        $this->assertFalse($vars['zone_is_editable']);
        $this->assertTrue($vars['zone_replicates_from_primary']);
        $this->assertTrue($vars['records'][0]['record_locked']);
        $this->assertTrue($vars['records'][1]['record_locked']);
    }

    public function testRetrieveButtonNeedsTheApiBackendAndASecondaryWithAPrimary(): void
    {
        $this->assertTrue($this->present(['isApiBackend' => true, 'domainType' => 'SLAVE', 'slaveMaster' => '192.0.2.10'])['can_retrieve_zone']);
        $this->assertFalse($this->present(['isApiBackend' => false, 'domainType' => 'SLAVE', 'slaveMaster' => '192.0.2.10'])['can_retrieve_zone']);
        $this->assertFalse($this->present(['isApiBackend' => true, 'domainType' => 'MASTER', 'slaveMaster' => '192.0.2.10'])['can_retrieve_zone']);
        $this->assertFalse($this->present(['isApiBackend' => true, 'domainType' => 'SLAVE', 'slaveMaster' => null])['can_retrieve_zone']);
        $this->assertFalse($this->present(['isApiBackend' => true, 'domainType' => 'SLAVE', 'slaveMaster' => ''])['can_retrieve_zone']);
    }

    public function testCatalogKindsCannotBeRetypedFromTheBasicSelector(): void
    {
        $this->assertTrue($this->present(['domainType' => 'MASTER'])['zone_type_change_allowed']);
        $this->assertFalse($this->present(['domainType' => 'PRODUCER'])['zone_type_change_allowed']);
    }

    public function testCatalogMembersViewNeedsAProducerMetadataViewAndServerSupport(): void
    {
        $producer = ['domainType' => 'PRODUCER', 'permMetadataView' => 'all'];

        $this->assertTrue($this->present($producer)['catalog_members_view']);
        $this->assertFalse($this->present($producer + ['supportsCatalogZones' => false])['catalog_members_view']);
        $this->assertFalse($this->present(['domainType' => 'MASTER', 'permMetadataView' => 'all'])['catalog_members_view']);
        $this->assertFalse($this->present(['domainType' => 'PRODUCER', 'permMetadataView' => 'none'])['catalog_members_view']);
    }

    public function testOwnScopedViewLevelsFollowZoneOwnership(): void
    {
        $owner = $this->present(['permMetadataView' => 'own', 'permOwnershipView' => 'own', 'logPermission' => 'own', 'userIsZoneOwner' => true]);
        $other = $this->present(['permMetadataView' => 'own', 'permOwnershipView' => 'own', 'logPermission' => 'own', 'userIsZoneOwner' => false]);

        $this->assertTrue($owner['metadata_view']);
        $this->assertTrue($owner['ownership_view']);
        $this->assertTrue($owner['can_view_zone_logs']);
        $this->assertFalse($other['metadata_view']);
        $this->assertFalse($other['ownership_view']);
        $this->assertFalse($other['can_view_zone_logs']);
    }

    public function testForwardZoneOffersTheDomainRecordTypes(): void
    {
        $vars = $this->present();

        $this->assertContains('A', $vars['record_types']);
        $this->assertContains('MX', $vars['record_types']);
        $this->assertNotContains('DNSKEY', $vars['record_types']);
        $this->assertFalse($vars['is_reverse_zone']);
    }

    public function testReverseZoneOffersTheReverseRecordTypes(): void
    {
        $vars = $this->present(['zoneName' => '2.0.192.in-addr.arpa', 'isReverseZone' => true]);

        $this->assertContains('PTR', $vars['record_types']);
        $this->assertNotContains('A', $vars['record_types']);
        $this->assertNotContains('MX', $vars['record_types']);
        $this->assertTrue($vars['is_reverse_zone']);
    }

    public function testDnssecAddsTheSigningRecordTypesAndCarriesTheFlags(): void
    {
        $vars = $this->present(['dnssecEnabled' => true, 'isSecured' => true, 'isPresigned' => true, 'signedSerial' => 2024010102]);

        $this->assertContains('DNSKEY', $vars['record_types']);
        $this->assertContains('DS', $vars['record_types']);
        $this->assertTrue($vars['pdnssec_use']);
        $this->assertTrue($vars['is_secured']);
        $this->assertTrue($vars['is_presigned']);
        $this->assertSame(2024010102, $vars['signed_serial']);
        $this->assertTrue($vars['can_manage_dnssec']);
    }

    public function testTtlDefaultsFollowTheOfferedTypesAndTheReverseTtl(): void
    {
        $forward = $this->present(['typeDefaultTtls' => ['MX' => 300], 'ptrDefaultTtl' => 600]);
        $reverse = $this->present(['isReverseZone' => true, 'ptrDefaultTtl' => 600]);

        $this->assertSame(86400, $forward['dns_ttl']);
        $this->assertSame(86400, $forward['default_ttl']);
        $this->assertSame(600, $forward['ptr_default_ttl']);
        $this->assertSame(['MX' => 300], $forward['type_default_ttls']);
        $this->assertSame(300, $forward['ttl_defaults_by_type']['MX']);
        $this->assertSame(86400, $forward['ttl_defaults_by_type']['A']);
        $this->assertSame(600, $reverse['ttl_defaults_by_type']['PTR']);
        $this->assertSame(86400, $reverse['ttl_defaults_by_type']['NS']);
        $this->assertSame(array_keys($reverse['ttl_defaults_by_type']), $reverse['record_types']);
    }

    public function testApiBackendHidesTheIdColumnRegardlessOfPreference(): void
    {
        $this->assertTrue($this->present(['showRecordId' => true, 'isApiBackend' => false])['iface_edit_show_id']);
        $this->assertFalse($this->present(['showRecordId' => true, 'isApiBackend' => true])['iface_edit_show_id']);
        $this->assertFalse($this->present(['showRecordId' => false, 'isApiBackend' => false])['iface_edit_show_id']);
    }

    public function testStoredCommentIsShownWhenNothingWasRejected(): void
    {
        $vars = $this->present();

        $this->assertSame('stored', $vars['zone_comment']);
        $this->assertSame('stored', $vars['stored_zone_comment']);
        $this->assertFalse($vars['zone_comment_conflict']);
    }

    public function testRejectedCommentIsRestoredAndFlaggedWhenTheStoredOneMoved(): void
    {
        $vars = $this->present(['rejectedZoneComment' => 'mine']);

        $this->assertSame('mine', $vars['zone_comment']);
        $this->assertSame('stored', $vars['stored_zone_comment']);
        $this->assertTrue($vars['zone_comment_conflict']);
    }

    public function testRejectedCommentMatchingTheStoredOneIsNoConflict(): void
    {
        $vars = $this->present(['rejectedZoneComment' => 'stored']);

        $this->assertSame('stored', $vars['zone_comment']);
        $this->assertFalse($vars['zone_comment_conflict']);
    }

    public function testRejectedRowsAreRestoredIntoTheListing(): void
    {
        $vars = $this->present([
            'rejectedRecords' => [
                new ZoneEditRow(2, 'www.example.com', 'A', '192.0.2.2', 60, 0, false, ''),
                new ZoneEditRow(9, 'gone.example.com', 'A', '192.0.2.9', 60, 0, false, ''),
            ],
        ]);

        $this->assertSame('192.0.2.2', $vars['records'][1]['content']);
        $this->assertTrue($vars['records'][1]['unsaved_edit']);
        $this->assertCount(1, $vars['stale_form_dropped']);
        $this->assertStringContainsString('gone.example.com', $vars['stale_form_dropped'][0]);
    }

    public function testRecordsAreDecoratedWithTheirLocks(): void
    {
        $vars = $this->present(['permEdit' => 'own']);

        $this->assertTrue($vars['records'][0]['record_locked']);
        $this->assertFalse($vars['records'][1]['record_locked']);
        $this->assertSame('www.example.com', $vars['records'][1]['display_name']);
        $this->assertSame([], $vars['stale_form_dropped']);
    }

    public function testReviewLinkNeedsPendingRequests(): void
    {
        $pending = [['id' => 1, 'kind' => 'edit']];

        $this->assertTrue($this->present(['pendingChangeRequests' => $pending, 'canReviewChangeRequests' => true])['can_review_change_requests']);
        $this->assertFalse($this->present(['pendingChangeRequests' => [], 'canReviewChangeRequests' => true])['can_review_change_requests']);
        $this->assertFalse($this->present(['pendingChangeRequests' => $pending, 'canReviewChangeRequests' => false])['can_review_change_requests']);
    }

    public function testEveryTemplateVariableIsPresent(): void
    {
        $expected = [
            'zone_id', 'zone_name', 'zone_name_to_display', 'idn_zone_name', 'zone_display_name', 'zone_comment',
            'zone_comment_conflict', 'stored_zone_comment', 'domain_type', 'slave_master', 'zone_types',
            'zone_replicates_from_primary', 'can_retrieve_zone', 'zone_type_change_allowed', 'catalog_members_view',
            'catalog_selector_view', 'catalog_producers', 'catalog_producer_id', 'catalog_name', 'zone_templates',
            'zone_template_id', 'zone_template_details', 'record_count', 'filtered_record_count', 'records',
            'stale_form_dropped', 'perm_view', 'perm_edit', 'perm_edit_ns_subzone', 'perm_meta_edit', 'meta_edit',
            'metadata_view', 'ownership_view', 'zone_is_read_only', 'user_can_edit_zone', 'zone_is_editable',
            'can_edit_records', 'edit_mode', 'require_change_comment', 'pending_change_requests',
            'can_review_change_requests', 'can_view_zone_logs', 'can_manage_dnssec', 'perm_zone_templ_add',
            'perm_is_godlike', 'dblog_use', 'perm_view_zone_own', 'perm_view_zone_other', 'user_is_zone_owner',
            'row_start', 'row_amount', 'record_sort_by', 'sort_direction', 'pagination', 'pdnssec_use', 'is_secured',
            'is_presigned', 'signed_serial', 'session_userid', 'dns_ttl', 'default_ttl', 'ptr_default_ttl',
            'type_default_ttls', 'ttl_defaults_by_type', 'is_reverse_zone', 'record_types', 'iface_add_reverse_record',
            'iface_add_domain_record', 'iface_edit_show_id', 'iface_show_add_record_form',
            'iface_show_record_edit_button', 'iface_show_record_delete_button', 'iface_edit_add_record_top',
            'iface_edit_save_changes_top', 'iface_record_comments', 'iface_zone_comments', 'serial', 'whois_actions',
            'rdap_actions', 'form_token', 'form_data', 'search_term', 'record_type_filter', 'content_filter',
            'display_hostname_only', 'dns_wizard_actions', 'export_formats', 'import_enabled',
        ];

        $this->assertSame($expected, array_keys($this->present()));
    }
}
