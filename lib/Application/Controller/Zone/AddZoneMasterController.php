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

namespace Poweradmin\Application\Controller\Zone;

use Poweradmin\Application\Controller\BaseController;
use Poweradmin\Application\Service\ZoneCreateRequest;
use Poweradmin\Domain\Model\MetadataDefinitions;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\ZoneType;
use Poweradmin\Domain\Service\Zone\ZoneSigningOutcome;
use Poweradmin\Domain\Service\Auth\UserContextService;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipModeService;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Handles the add-primary-zone form: validates name and kind, applies the template and creates the zone.
 */
class AddZoneMasterController extends BaseController
{

    private UserContextService $userContext;

    /** @var array<int, string>|null */
    private ?array $soaEditApiChoices = null;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $this->userContext = new UserContextService();
    }

    public function run(): void
    {
        $this->checkPermission(Permission::PERM_ZONE_MASTER_ADD, _("You do not have the permission to add a master zone."));

        // Set the current page for navigation highlighting
        $this->setCurrentPage('add_zone_master');
        $this->setPageTitle(_('Add Primary Zone'));

        $blocker = $this->services()->zoneOwnershipFormResolver()->blocker((int)$this->getCurrentUserId());
        if ($blocker !== null) {
            $this->showError($blocker);
            return;
        }

        if ($this->isPost()) {
            $this->addZone();
        } else {
            $this->showForm();
        }
    }


    /**
     * Zone kinds this install may create.
     *
     * @return array<string>
     */
    private function getAvailableZoneTypes(): array
    {
        // A consumer replicates from a remote primary, which is what zone_slave_add governs.
        return ZoneType::getCreatableTypes(
            $this->supportsCatalogKinds(),
            $this->hasPermission(Permission::PERM_ZONE_SLAVE_ADD)
        );
    }

    /**
     * Catalog kinds need PowerDNS 4.7+, and an unknown version must count as
     * unsupported: the 4.7 schema widened domains.type from VARCHAR(6) to
     * VARCHAR(8), so writing PRODUCER/CONSUMER to an older one truncates it.
     */
    private function supportsCatalogKinds(): bool
    {
        return $this->getPdnsCapabilities()->supportsCatalogZones();
    }

    private function addZone(): void
    {
        $constraints = [
            'domain' => [
                new Assert\NotBlank()
            ],
            'dom_type' => [
                new Assert\NotBlank()
            ],
            'zone_template' => [
                new Assert\NotBlank()
            ]
        ];

        $this->setValidationConstraints($constraints);

        $postData = $this->httpRequest->getPostParams();
        if (!$this->doValidateRequest($postData)) {
            $this->showFirstValidationError($postData);
        }

        $dom_type = $this->httpRequest->getPostParam('dom_type', '');

        // The dropdown only populates the form; without this the submit path would
        // accept any string, including kinds this server does not support.
        if (!in_array($dom_type, $this->getAvailableZoneTypes(), true)) {
            $this->setMessage('add_zone_master', 'error', _('Invalid or unexpected input given.'));
            $this->showForm();
            return;
        }

        $zone_template = $this->httpRequest->getPostParam('zone_template', 'none');
        $zoneTemplateModel = $this->services()->zoneTemplateService();
        if (!$zoneTemplateModel->canCurrentUserUseTemplate($zone_template)) {
            $this->setMessage('add_zone_master', 'error', _('Invalid or unexpected input given.'));
            $this->showForm();
            return;
        }

        $soa_edit_api_input = $this->httpRequest->getPostParam('soa_edit_api');
        if (!$this->isOfferedSoaEditApiInput($soa_edit_api_input)) {
            $this->setMessage('add_zone_master', 'error', _('Invalid or unexpected input given.'));
            $this->showForm();
            return;
        }

        // A consumer takes its catalog by transfer, so it needs a primary and gets
        // neither template records, a serial policy nor a signature.
        $pdnssec_use = $this->config->get('dnssec', 'enabled', false);
        $replicates = ZoneType::replicatesFromPrimary($dom_type);
        $created = $this->createZoneCreateService()->create(new ZoneCreateRequest(
            name: (string)$this->httpRequest->getPostParam('domain', ''),
            type: $dom_type,
            ownerInput: $this->httpRequest->getPostParam('owner'),
            groupsInput: $this->httpRequest->getPostParam('groups'),
            callerUserId: (int)$this->getCurrentUserId(),
            slaveMaster: $replicates ? trim((string)$this->httpRequest->getPostParam('slave_master', '')) : '',
            template: $replicates ? 'none' : $zone_template,
            signRequested: $pdnssec_use && !$replicates && $this->httpRequest->getPostParam('dnssec') !== null,
            soaEditApi: $this->sanitizeSoaEditApiInput($soa_edit_api_input),
            reverseNetwork: $this->httpRequest->getPostParam('type') === 'reverse'
        ));
        if (!$created->success) {
            $this->setMessage('add_zone_master', 'error', (string)$created->message);
            $this->showForm();
            return;
        }

        $signed = $created->dnssec;
        $dnssecMessage = $signed === null ? null : match ($signed->outcome) {
            ZoneSigningOutcome::SIGNED => ['success', _('Zone has been created and signed with DNSSEC successfully.')],
            ZoneSigningOutcome::INVALID_ZONE => ['warning', _('Zone was created successfully, but DNSSEC signing was skipped due to validation errors:') . "\n\n" . $signed->detail],
            ZoneSigningOutcome::SECURE_FAILED => ['warning', _('Zone was created, but securing it with DNSSEC failed. Zone validation passed, but PowerDNS API returned an error. Check PowerDNS logs for details.')],
            ZoneSigningOutcome::VERIFY_FAILED => ['warning', _('Zone was created and signing was requested, but verification failed. Check DNSSEC keys.')],
            default => null,
        };
        // Signing rectifies on its own; every other new primary is rectified here.
        if ($pdnssec_use && !$replicates && $signed?->outcome !== ZoneSigningOutcome::SIGNED) {
            $this->services()->dnssecProvider()->rectifyZone($created->zoneName);
        }

        $messageKey = $created->isReverseZone() ? 'list_reverse_zones' : 'list_forward_zones';
        [$messageType, $message] = $dnssecMessage ?? ['success', _('Zone has been added successfully.')];
        $this->setMessage($messageKey, $messageType, $message);
        $this->redirect($messageKey === 'list_reverse_zones' ? '/zones/reverse' : '/zones/forward');
    }

    /**
     * SOA-EDIT-API values offered by the add-zone selector; an empty list
     * hides the selector.
     *
     * @return array<int, string>
     */
    private function getSoaEditApiChoices(): array
    {
        return $this->soaEditApiChoices ??= MetadataDefinitions::getSoaEditApiChoices($this->config);
    }

    /**
     * Keep only offered SOA-EDIT-API choices from the form; null means
     * "server default" (the dns.soa_edit_api config default applies).
     */
    private function sanitizeSoaEditApiInput(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return in_array($value, $this->getSoaEditApiChoices(), true) ? $value : null;
    }

    /**
     * Empty means "server default"; anything else must be an offered choice,
     * as the API requires, instead of being silently dropped.
     */
    private function isOfferedSoaEditApiInput(?string $value): bool
    {
        return $value === null || $value === '' || in_array($value, $this->getSoaEditApiChoices(), true);
    }

    private function showForm(): void
    {
        $zone_templates = $this->services()->zoneTemplateService();
        $pdnssec_use = $this->config->get('dnssec', 'enabled', false);
        $users = $this->services()->userRepository()->getUsersWithZoneCounts();

        // Keep the submitted zone name if there was an error
        $domainInput = $this->httpRequest->getPostParam('domain');
        $domain_value = $domainInput ?? '';

        // Resolve the system-wide default template (DB flag → config setting → none)
        $default_template_id = $zone_templates->getDefaultTemplateId();

        // Safely handle the zone template value
        $zoneTemplateInput = $this->httpRequest->getPostParam('zone_template');
        if ($zoneTemplateInput !== null) {
            // If it's 'none', keep it as is
            if ($zoneTemplateInput === 'none') {
                $zone_template_value = 'none';
            } else {
                // Otherwise, ensure it's a valid integer
                $template_id = filter_var($zoneTemplateInput, FILTER_VALIDATE_INT);
                // Get the list of valid template IDs
                $templates = $zone_templates->getListZoneTempl((int)$this->getCurrentUserId());
                $valid_template_ids = array_column($templates, 'id');
                $zone_template_value = ($template_id !== false && in_array($template_id, $valid_template_ids)) ?
                    $template_id : 'none';
            }
        } else {
            $zone_template_value = $default_template_id !== null ? $default_template_id : 'none';
        }

        $assignableOwners = $this->assignableOwners($users);
        $owner_value = $this->preservedOwnerChoice($assignableOwners, $this->httpRequest->getPostParam('owner'));

        $valid_domain_types = $this->getAvailableZoneTypes();
        $domTypeInput = $this->httpRequest->getPostParam('dom_type');
        $dom_type_value = $domTypeInput !== null && in_array($domTypeInput, $valid_domain_types, true) ?
            $domTypeInput : $this->config->get('dns', 'zone_type_default', 'MASTER');

        $is_post_request = !empty($this->httpRequest->getPostParams());

        // Create a sanitized version of the DNSSEC checkbox status
        $dnssec_checked = $this->httpRequest->getPostParam('dnssec') == '1';

        // Get available templates for this user
        $userId = $this->userContext->getLoggedInUserId();
        $templates = $zone_templates->getListZoneTempl($userId);

        // Fetch groups for the dropdown - admins see all, others see only their own
        $userGroupRepo = $this->services()->userGroupRepository();
        $isAdmin = $this->hasPermission(Permission::PERM_USER_IS_UEBERUSER);
        $allGroups = $isAdmin ? $userGroupRepo->findAll() : $userGroupRepo->findByUserId($userId);

        // Fetch member counts for all groups in a single query
        $groupIds = array_map(fn($g) => $g->getId(), $allGroups);
        $memberCounts = $userGroupRepo->getMemberCountsByGroupIds($groupIds);

        // Handle selected groups on error re-render
        $groupsInput = $this->httpRequest->getPostParam('groups');
        $selected_groups = is_array($groupsInput) ? array_map('intval', $groupsInput) : [];

        $ownershipMode = new ZoneOwnershipModeService($this->config);

        // Preserve reverse-zone context so the form returns to the reverse list
        $is_reverse_zone = $this->httpRequest->getQueryParam('type') === 'reverse'
            || $this->httpRequest->getPostParam('type') === 'reverse';

        $this->render('add_zone_master.html', [
            'is_reverse_zone' => $is_reverse_zone,
            'session_user_id' => $userId,
            'available_zone_types' => $valid_domain_types,
            'users' => $users,
            'selectable_owners' => $assignableOwners,
            'zone_templates' => $templates,
            'can_use_templates' => !empty($templates),
            'default_template_id' => $default_template_id,
            'iface_zone_type_default' => $this->config->get('dns', 'zone_type_default', 'MASTER'),
            'iface_add_domain_record' => $this->config->get('interface', 'add_domain_record', false),
            'pdnssec_use' => $pdnssec_use,
            'domain_value' => $domain_value,
            'zone_template_value' => $zone_template_value,
            'owner_value' => $owner_value,
            'dom_type_value' => $dom_type_value,
            'slave_master_value' => (string)$this->httpRequest->getPostParam('slave_master', ''),
            'zone_replicates_from_primary' => ZoneType::replicatesFromPrimary($dom_type_value),
            'replicating_zone_types' => ZoneType::getReplicatingTypes(),
            'is_post' => $is_post_request,
            'dnssec_checked' => $dnssec_checked,
            'all_groups' => $allGroups,
            'group_member_counts' => $memberCounts,
            'selected_groups' => $selected_groups,
            'user_owner_allowed' => $ownershipMode->isUserOwnerAllowed(),
            'group_owner_allowed' => $ownershipMode->isGroupOwnerAllowed(),
            'soa_edit_api_options' => $this->getSoaEditApiChoices(),
            // Preselect the submitted value on error re-render, else the config default
            'soa_edit_api_value' => $this->sanitizeSoaEditApiInput(
                $this->httpRequest->getPostParam('soa_edit_api') ?? $this->config->get('dns', 'soa_edit_api', '')
            ) ?? '',
            // Don't pass raw POST data to the template for security
        ]);
    }
}
