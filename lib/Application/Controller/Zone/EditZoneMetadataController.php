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
use Poweradmin\Application\Service\ZoneMetadataFormMessages;
use Poweradmin\Domain\Model\MetadataDefinitions;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Repository\ZoneReadRepositoryInterface;
use Poweradmin\Domain\Utility\DnsIdnService;
use Poweradmin\Domain\Model\PdnsCapabilities;
use Poweradmin\Domain\Service\Zone\ZoneMetadataService;
use Poweradmin\Domain\Utility\DnsHelper;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Handles reading and replacing raw PowerDNS domain metadata for a single zone.
 */
class EditZoneMetadataController extends BaseController
{
    /**
     * Sentinel value used by the UI when the user selects a free-form metadata kind.
     */
    private const CUSTOM_KIND = '__CUSTOM__';

    /**
     * Repository used for loading the zone.
     */
    private ZoneReadRepositoryInterface $zoneRepository;

    /**
     * The rules and the persistence, shared with the API.
     */
    private ZoneMetadataService $metadataService;

    /**
     * @param array<string, mixed> $request
     */
    public function __construct(array $request)
    {
        parent::__construct($request);
        $this->zoneRepository = $this->services()->zoneRepository();
        $this->metadataService = $this->services()->zoneMetadataService();
    }

    /**
     * Render the editor on GET and replace zone metadata on POST.
     */
    public function run(): void
    {
        $constraints = [
            'id' => [
                new Assert\NotBlank(),
                new Assert\Type('numeric'),
            ],
        ];

        $this->setValidationConstraints($constraints);
        if (!$this->doValidateRequest($this->getRequest())) {
            $this->showFirstValidationError($this->getRequest());
            return;
        }

        $zoneId = (int) $this->getSafeRequestValue('id');
        $zone = $this->zoneRepository->getZone($zoneId);

        if ($zone === null) {
            $this->showError(_('Zone not found.'));
            return;
        }

        $permissions = $this->services()->permissionService();
        $userId = (int)$this->getCurrentUserId();
        $canEditMetadata = $permissions->canEditZoneMeta($userId, $zoneId);
        // The view level folds meta-edit in, so editors always retain view access.
        $canViewMetadata = $permissions->canViewZoneMetadata($userId, $zoneId);

        $this->checkCondition(!$canViewMetadata, _('You do not have the permission to view zone metadata.'));

        if ($this->isPost() && !$canEditMetadata) {
            $this->showError(_('You do not have the permission to edit zone metadata.'));
            return;
        }

        if ($this->isPost()) {
            $submittedMetadata = $this->normalizeSubmittedMetadata($this->httpRequest->getPostParam('metadata', []));

            $result = $this->metadataService->replaceAll($zoneId, $zone['name'], $submittedMetadata, (int)$this->getCurrentUserId());
            if (!$result->isOk()) {
                $this->setMessage('edit_zone_metadata', 'error', ZoneMetadataFormMessages::errorMessage($result));
                $this->renderPage($zoneId, $zone, $submittedMetadata, $canEditMetadata);
                return;
            }

            $this->setMessage('edit_zone_metadata', 'success', _('Zone metadata has been updated successfully.'));
            $this->redirect('/zones/' . $zoneId . '/metadata');
            return;
        }

        $this->renderPage($zoneId, $zone, $this->metadataService->load($zoneId, $zone['name']), $canEditMetadata);
    }

    /**
     * Prepare and render the metadata editor page.
     *
     * @param array<string, mixed> $zone
     * @param array<int, array<string, string>> $metadataRows
     */
    private function renderPage(int $zoneId, array $zone, array $metadataRows, bool $canEdit = true): void
    {
        $idnZoneName = str_starts_with($zone['name'], 'xn--') ? DnsIdnService::toUtf8($zone['name']) : '';
        if (empty($metadataRows)) {
            $metadataRows = [['kind' => '', 'content' => '']];
        }

        $this->setCurrentPage('zone_metadata');
        $this->setPageTitle($canEdit ? _('Edit Zone Metadata') : _('Zone Metadata'));

        $definitions = $this->getMetadataDefinitionsForTemplate($this->hasPermission(Permission::PERM_USER_IS_UEBERUSER), $this->serverCapabilities());

        $this->render('edit_zone_metadata.html', [
            'zone_id' => $zoneId,
            'zone' => $zone,
            'idn_zone_name' => $idnZoneName,
            'zone_display_name' => DnsIdnService::toDisplay($zone['name']),
            'metadata_rows' => $this->prepareRowsForTemplate($metadataRows, array_column($definitions, 'kind')),
            'metadata_definitions' => $definitions,
            'is_reverse_zone' => DnsHelper::isReverseZoneName($zone['name']),
            'can_edit_metadata' => $canEdit,
            // Rows rendered through the custom-kind path have their badges
            // rebuilt client-side, so the list has to reach the template's JS.
            'server_managed_kinds' => MetadataDefinitions::SERVER_MANAGED_KINDS,
        ]);
    }

    /**
     * Convert submitted rows into a compact list of valid domainmetadata entries.
     *
     * Empty rows are ignored and partially filled rows are dropped so the editor can
     * preserve a simple add/remove-row workflow without producing invalid writes.
     *
     * @param array<int, array<string, mixed>> $submittedMetadata
     * @return array<int, array{kind: string, content: string}>
     */
    private function normalizeSubmittedMetadata(array $submittedMetadata): array
    {
        $rows = [];
        foreach ($submittedMetadata as $row) {
            $rows[] = ['kind' => $this->resolveSubmittedKind($row), 'content' => $row['content'] ?? ''];
        }

        return ZoneMetadataService::normalizeRows($rows);
    }

    /**
     * Resolve the effective metadata kind from either a predefined selection or custom input.
     *
     * @param array<string, mixed> $row
     */
    private function resolveSubmittedKind(array $row): string
    {
        $selectedKind = strtoupper(trim((string) ($row['kind_key'] ?? '')));
        if ($selectedKind === self::CUSTOM_KIND) {
            $selectedKind = strtoupper(trim((string) ($row['custom_kind'] ?? '')));
        }

        return $selectedKind;
    }

    /**
     * Build metadata definitions for the template, already localized for display.
     *
     * @param bool $includeOperatorOnly Whether the caller may set operator-only kinds
     * @param callable(): PdnsCapabilities $caps The connected server's capabilities, read on demand
     * @return array<int, array<string, mixed>>
     */
    private function getMetadataDefinitionsForTemplate(bool $includeOperatorOnly, callable $caps): array
    {
        $definitions = [];

        foreach (MetadataDefinitions::DEFINITIONS as $kind => $definition) {
            $support = $this->metadataService->kindSupport($definition, $caps);
            // Strict mode: hide kinds whose support cannot be confirmed (version
            // detection failed). Older known-but-unsupported kinds remain visible
            // but disabled, so admins can still see what newer servers add.
            if ($support === ZoneMetadataService::SUPPORT_UNKNOWN) {
                continue;
            }

            // An empty configured option list disables the kind in the editor
            $options = MetadataDefinitions::getOfferedOptions($kind, $this->getConfig());
            if ($options === []) {
                continue;
            }

            // Do not offer kinds the caller is not allowed to set; the POST
            // handler rejects them regardless.
            if (!$includeOperatorOnly && MetadataDefinitions::isOperatorOnly($kind)) {
                continue;
            }

            $definitions[] = [
                'kind' => $kind,
                'label' => $definition['label'],
                'multi' => $definition['multi'],
                'placeholder' => $definition['placeholder'],
                'help' => _($definition['help']),
                'badges' => $this->buildBadgeDescriptors($kind, $definition),
                'disabled' => $support === ZoneMetadataService::SUPPORT_UNSUPPORTED_KNOWN,
                'min_version' => $definition['min_version'] ?? null,
                'options' => $options,
            ];
        }

        return $definitions;
    }

    /**
     * Expand stored metadata rows with UI-specific fields used by the editor template.
     *
     * @param array<int, array<string, string>> $rows
     * @param array<int, string> $offeredKinds Kinds present in the kind dropdown
     * @return array<int, array<string, mixed>>
     */
    private function prepareRowsForTemplate(array $rows, array $offeredKinds): array
    {
        $preparedRows = [];

        foreach ($rows as $row) {
            $definition = $this->getMetadataDefinition($row['kind']);
            $isKnownKind = isset(MetadataDefinitions::DEFINITIONS[$row['kind']]);
            // A kind absent from the kind dropdown (hidden by config or
            // unsupported by the server) must go through the custom-kind path,
            // or the browser would submit a different kind and rewrite the row.
            $isOffered = in_array($row['kind'], $offeredKinds, true);

            $preparedRows[] = [
                'kind' => $row['kind'],
                'content' => $row['content'],
                'kind_key' => $isOffered ? $row['kind'] : self::CUSTOM_KIND,
                'custom_kind' => $isOffered ? '' : $row['kind'],
                'kind_help' => $isKnownKind
                    ? _($definition['help'])
                    : $this->getCustomMetadataKindHelpText(),
                'kind_placeholder' => $definition['placeholder'] ?? $this->getDefaultValueLabel(),
                'kind_multi' => $isKnownKind ? $definition['multi'] : null,
                'kind_badges' => $this->buildBadgeDescriptors($row['kind'], $definition, !$isKnownKind),
            ];
        }

        return $preparedRows;
    }

    /**
     * Return the metadata definition for a known kind or a generic fallback for custom kinds.
     *
     * @return array<string, mixed>
     */
    private function getMetadataDefinition(string $kind): array
    {
        return MetadataDefinitions::DEFINITIONS[$kind] ?? [
            'label' => $kind,
            'multi' => true,
            'placeholder' => $this->getDefaultValueLabel(),
            'help' => $this->getCustomMetadataKindHelpText(),
        ];
    }

    /**
     * Default help text for custom metadata kinds that are not part of the built-in list.
     */
    private function getCustomMetadataKindHelpText(): string
    {
        return _('Custom metadata kind stored directly in the PowerDNS domainmetadata table.');
    }

    /**
     * Default placeholder shown for metadata values without a kind-specific example.
     */
    private function getDefaultValueLabel(): string
    {
        return _('Value');
    }

    /**
     * Build UI badges describing whether a kind is custom, single-value, multi-value, or version-gated.
     *
     * @param array<string, mixed> $definition
     * @return array<int, array{label: string, class: string}>
     */
    private function buildBadgeDescriptors(string $kind, array $definition, bool $isCustom = false): array
    {
        $badges = [];

        if ($kind !== '' && $this->metadataService->writeRejection($kind) !== null) {
            $badges[] = [
                'label' => _('Read-only'),
                'class' => 'bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle',
            ];
        }

        if ($isCustom) {
            $badges[] = ['label' => _('Custom'), 'class' => 'bg-warning-subtle text-warning-emphasis border border-warning-subtle'];
            return $badges;
        }

        $badges[] = $definition['multi']
            ? ['label' => _('Multi'), 'class' => 'bg-info-subtle text-info-emphasis border border-info-subtle']
            : ['label' => _('Single'), 'class' => 'bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle'];

        if (!empty($definition['min_version']) && version_compare($definition['min_version'], '4.0.0', '>')) {
            $badges[] = [
                'label' => $definition['min_version'] . '+',
                'class' => 'bg-light text-dark border',
            ];
        }

        return $badges;
    }

    /**
     * What the connected PowerDNS supports, from the session cache the version
     * probe keeps. Resolved once, and only when a store asks for it.
     *
     * @return callable(): PdnsCapabilities
     */
    private function serverCapabilities(): callable
    {
        $capabilities = null;

        return function () use (&$capabilities): PdnsCapabilities {
            if ($capabilities === null) {
                $this->refreshPdnsCapabilities();
                $capabilities = $this->getPdnsCapabilities();
            }

            return $capabilities;
        };
    }
}
