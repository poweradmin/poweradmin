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

namespace Poweradmin\Application\Controller;

use Poweradmin\Application\Http\Request;
use Poweradmin\Application\Service\DnsBackendProviderFactory;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\MetadataDefinitions;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Model\Zone;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\DnsIdnService;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Poweradmin\Infrastructure\Utility\IpAddressRetriever;
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

    private Request $request;

    /**
     * Repository used for loading zones and replacing domainmetadata rows.
     */
    private ZoneRepositoryInterface $zoneRepository;

    /**
     * Cached PowerDNS version used to hide metadata kinds unsupported by the current server.
     */
    private ?string $powerDnsVersion = null;

    /**
     * Cached API client for metadata operations in API mode.
     */
    private ?PowerdnsApiClient $apiClient = null;

    /**
     * @param array<string, mixed> $request
     */
    public function __construct(array $request)
    {
        parent::__construct($request);
        $this->request = new Request();
        $this->zoneRepository = $this->getRepositoryFactory()->createZoneRepository();
        if (DnsBackendProviderFactory::isApiBackend($this->getConfig())) {
            $this->apiClient = DnsBackendProviderFactory::createApiClient($this->getConfig(), $this->logger);
        }
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

        $canEditMetadata = UserManager::verifyPermission($this->db, 'zone_meta_edit_others')
            || (
                UserManager::verifyPermission($this->db, 'zone_meta_edit_own')
                && UserManager::verifyUserIsOwnerZoneId($this->db, $zoneId)
            );

        $canViewMetadata = $canEditMetadata
            || UserManager::verifyPermission($this->db, 'zone_content_view_others')
            || (
                UserManager::verifyPermission($this->db, 'zone_content_view_own')
                && UserManager::verifyUserIsOwnerZoneId($this->db, $zoneId)
            );

        $this->checkCondition(!$canViewMetadata, _('You do not have the permission to view zone metadata.'));

        if ($this->isPost() && !$canEditMetadata) {
            $this->showError(_('You do not have the permission to edit zone metadata.'));
            return;
        }

        if ($this->isPost()) {
            $this->validateCsrfToken();
            $submittedMetadata = $this->normalizeSubmittedMetadata($this->request->getPostParam('metadata', []));
            $validationErrors = $this->validateMetadataRows($submittedMetadata);

            if (!empty($validationErrors)) {
                $this->setMessage('zone_metadata', 'error', $validationErrors[0]);
                $this->renderPage($zoneId, $zone, $submittedMetadata, $canEditMetadata);
                return;
            }

            $saveResult = $this->saveMetadata($zone, $submittedMetadata);

            if ($saveResult['success']) {
                $kinds = array_unique(array_column($submittedMetadata, 'kind'));
                $auditLogger = new LegacyLogger($this->db);
                $ipRetriever = new IpAddressRetriever($_SERVER);
                $auditLogger->logInfo(sprintf(
                    'client_ip:%s user:%s operation:edit_zone_metadata zone:%s kinds:%s',
                    $ipRetriever->getClientIp(),
                    $this->getUserContextService()->getLoggedInUsername(),
                    $zone['name'],
                    implode(',', $kinds)
                ), $zoneId);

                $this->setMessage('zone_metadata', 'success', _('Zone metadata has been updated successfully.'));
                $this->redirect('/zones/' . $zoneId . '/metadata');
                return;
            }

            $this->setMessage('zone_metadata', 'error', _('Failed to update zone metadata.'));
            $this->renderPage($zoneId, $zone, $submittedMetadata, $canEditMetadata);
            return;
        }

        $this->renderPage($zoneId, $zone, $this->loadMetadata($zoneId, $zone['name']), $canEditMetadata);
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

        $this->render('edit_zone_metadata.html', [
            'zone_id' => $zoneId,
            'zone' => $zone,
            'idn_zone_name' => $idnZoneName,
            'metadata_rows' => $this->prepareRowsForTemplate($metadataRows),
            'metadata_definitions' => $this->getMetadataDefinitionsForTemplate(),
            'is_reverse_zone' => DnsHelper::isReverseZone($zone['name']),
            'can_edit_metadata' => $canEdit,
        ]);
    }

    /**
     * Load raw PowerDNS domainmetadata rows for the given zone.
     *
     * @return array<int, array<string, string>>
     */
    private function loadMetadata(int $zoneId, string $zoneName): array
    {
        if ($this->apiClient !== null) {
            return $this->loadMetadataViaApi($zoneName);
        }

        return $this->zoneRepository->getDomainMetadata($zoneId);
    }

    /**
     * Load metadata via the PowerDNS API.
     *
     * @return array<int, array<string, string>>
     */
    private function loadMetadataViaApi(string $zoneName): array
    {
        $zone = new Zone(str_ends_with($zoneName, '.') ? $zoneName : $zoneName . '.');
        $apiMetadata = $this->apiClient->getZoneMetadata($zone);
        $rows = [];
        foreach ($apiMetadata as $entry) {
            $kind = $entry['kind'] ?? '';
            foreach (($entry['metadata'] ?? []) as $value) {
                $rows[] = ['kind' => $kind, 'content' => (string) $value];
            }
        }

        usort($rows, fn($a, $b) => strcmp($a['kind'], $b['kind']));
        return $rows;
    }

    /**
     * Replace all stored metadata rows for the zone.
     *
     * @param array<string, mixed> $zone
     * @param array<int, array<string, string>> $metadataRows
     * @return array<string, bool|string>
     */
    private function saveMetadata(array $zone, array $metadataRows): array
    {
        if ($this->apiClient !== null) {
            return $this->saveMetadataViaApi($zone['name'], $metadataRows);
        }

        return [
            'success' => $this->zoneRepository->replaceDomainMetadata((int) $zone['id'], $metadataRows),
        ];
    }

    /**
     * Save metadata via the PowerDNS API.
     *
     * Groups rows by kind and uses PUT per kind. Handles SOA-EDIT-API specially
     * via the zone properties endpoint. Deletes kinds that were removed.
     *
     * @param array<int, array<string, string>> $metadataRows
     * @return array<string, bool|string>
     */
    private function saveMetadataViaApi(string $zoneName, array $metadataRows): array
    {
        $zone = new Zone($zoneName);

        // Group submitted rows by kind
        $grouped = [];
        foreach ($metadataRows as $row) {
            $grouped[$row['kind']][] = $row['content'];
        }

        // Load current metadata to detect removed kinds
        $currentMetadata = $this->apiClient->getZoneMetadata($zone);
        $currentKinds = [];
        foreach ($currentMetadata as $entry) {
            $currentKinds[] = $entry['kind'] ?? '';
        }

        $success = true;

        // Handle SOA-EDIT-API via zone properties endpoint
        if (isset($grouped['SOA-EDIT-API'])) {
            $soaEditApi = $grouped['SOA-EDIT-API'][0] ?? '';
            $success = $this->apiClient->updateZoneProperties($zoneName, ['soa_edit_api' => $soaEditApi]);
            unset($grouped['SOA-EDIT-API']);
        }

        // Update or create each metadata kind
        foreach ($grouped as $kind => $values) {
            $definition = $this->getMetadataDefinition($kind);
            if (isset($definition['api_write']) && $definition['api_write'] === false) {
                continue;
            }
            $result = $this->apiClient->updateZoneMetadata($zone, $kind, $values);
            $success = $success && $result;
        }

        // Delete kinds that were removed
        foreach ($currentKinds as $kind) {
            if ($kind !== '' && !isset($grouped[$kind]) && $kind !== 'SOA-EDIT-API') {
                $definition = $this->getMetadataDefinition($kind);
                if (isset($definition['api_write']) && $definition['api_write'] === false) {
                    continue;
                }
                $this->apiClient->deleteZoneMetadata($zone, $kind);
            }
        }

        return ['success' => $success];
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
            $kind = $this->resolveSubmittedKind($row);
            $content = trim((string) ($row['content'] ?? ''));

            if ($kind === '' && $content === '') {
                continue;
            }

            if ($kind === '' || $content === '') {
                continue;
            }

            $rows[] = [
                'kind' => substr($kind, 0, 32),
                'content' => $content,
            ];
        }

        return $rows;
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
     * Validate metadata rows against single-value metadata constraints.
     *
     * @param array<int, array{kind: string, content: string}> $rows
     * @return array<int, string>
     */
    private function validateMetadataRows(array $rows): array
    {
        $errors = [];
        $countsByKind = [];

        foreach ($rows as $row) {
            $kind = $row['kind'];
            $definition = $this->getMetadataDefinition($kind);
            $countsByKind[$kind] = ($countsByKind[$kind] ?? 0) + 1;

            if (!$definition['multi'] && $countsByKind[$kind] > 1) {
                $errors[] = sprintf(_('Metadata kind %s accepts only a single value. Add only one row for this kind.'), $kind);
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * Build metadata definitions for the template, already localized for display.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getMetadataDefinitionsForTemplate(): array
    {
        $definitions = [];

        foreach (MetadataDefinitions::DEFINITIONS as $kind => $definition) {
            // Hide version-gated metadata kinds when the connected PowerDNS server
            // is known to be too old to support them.
            if (!$this->isDefinitionSupportedInCurrentEnvironment($definition)) {
                continue;
            }

            $definitions[] = [
                'kind' => $kind,
                'label' => $definition['label'],
                'multi' => $definition['multi'],
                'placeholder' => $definition['placeholder'],
                'help' => _($definition['help']),
                'badges' => $this->buildBadgeDescriptors($kind, $definition),
            ];
        }

        return $definitions;
    }

    /**
     * Expand stored metadata rows with UI-specific fields used by the editor template.
     *
     * @param array<int, array<string, string>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function prepareRowsForTemplate(array $rows): array
    {
        $preparedRows = [];

        foreach ($rows as $row) {
            $definition = $this->getMetadataDefinition($row['kind']);
            $isKnownKind = isset(MetadataDefinitions::DEFINITIONS[$row['kind']]);

            $preparedRows[] = [
                'kind' => $row['kind'],
                'content' => $row['content'],
                'kind_key' => $isKnownKind ? $row['kind'] : self::CUSTOM_KIND,
                'custom_kind' => $isKnownKind ? '' : $row['kind'],
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
            'api_write' => true,
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
     * Check whether a metadata definition should be shown for the current environment.
     *
     * @param array<string, mixed> $definition
     */
    private function isDefinitionSupportedInCurrentEnvironment(array $definition): bool
    {
        if ($this->apiClient === null) {
            return true;
        }

        $minVersion = $definition['min_version'] ?? null;
        if ($minVersion === null) {
            return true;
        }

        $currentVersion = $this->getPowerDnsVersion();
        if ($currentVersion === '') {
            return true;
        }

        return version_compare($currentVersion, $minVersion, '>=');
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
     * Get and cache the PowerDNS version string returned by the API.
     */
    private function getPowerDnsVersion(): string
    {
        if ($this->powerDnsVersion !== null) {
            return $this->powerDnsVersion;
        }

        if ($this->apiClient === null) {
            $this->powerDnsVersion = '';
            return $this->powerDnsVersion;
        }

        $serverInfo = $this->apiClient->getServerInfo();
        $version = (string) ($serverInfo['version'] ?? '');
        $version = preg_replace('/^[^0-9]*/', '', $version);
        $this->powerDnsVersion = $version ?: '';

        return $this->powerDnsVersion;
    }
}
