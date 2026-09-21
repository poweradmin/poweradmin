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

namespace Poweradmin\Domain\Service;

use Closure;
use Exception;
use Poweradmin\Domain\Model\MetadataDefinitions;
use Poweradmin\Domain\Model\ZoneTemplate;
use Poweradmin\Domain\Model\ZoneType;
use Poweradmin\Domain\Service\DnsValidation\IPAddressValidator;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\RepositoryFactoryInterface;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneTemplateRepositoryInterface;
use Poweradmin\Domain\Service\Dns\DomainManagerInterface;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Domain\Utility\DomainUtility;
use Poweradmin\Domain\Config\ConfigurationInterface;
use PDO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use Poweradmin\Domain\Enum\ZoneKind;

/**
 * Creates zones for the API with the same checks the web form applies. Failures come back as
 * ['success' => false, 'message' => ..., 'status' => ..., 'code' => ...]: the
 * message is the API wording, the code lets the web forms word it themselves.
 */
class ZoneManagementService
{
    public const ERR_NO_OWNER = 'no_owner';
    public const ERR_INVALID_NAME = 'invalid_name';
    public const ERR_EXISTS = 'exists';
    public const ERR_OVERLAP = 'overlap';
    public const ERR_INVALID_TYPE = 'invalid_type';
    public const ERR_MASTER_REQUIRED = 'master_required';
    public const ERR_INVALID_MASTER = 'invalid_master';
    public const ERR_INVALID_SOA_EDIT_API = 'invalid_soa_edit_api';
    public const ERR_TEMPLATE_NOT_FOUND = 'template_not_found';
    public const ERR_TEMPLATE_AMBIGUOUS = 'template_ambiguous';
    public const ERR_TEMPLATE_FORBIDDEN = 'template_forbidden';
    public const ERR_ZONE_WRITE = 'zone_write';
    public const ERR_NOT_FOUND = 'not_found';
    public const ERR_READ_ONLY = 'read_only';

    private ZoneRepositoryInterface $zoneRepository;
    private ConfigurationInterface $config;
    private PDO $db;
    private LoggerInterface $logger;
    private RecordChangeWriterInterface $changeLogger;
    /** @var PdnsCapabilities|Closure|null Resolved on first use so a lookup only happens for a catalog kind */
    private PdnsCapabilities|Closure|null $capabilities;
    private ?ZoneSigningService $signing;
    private DnsBackendProviderInterface $backendProvider;
    private RepositoryFactoryInterface $repositoryFactory;
    private ?DomainRepositoryInterface $domainRepository;
    private PermissionService $permissions;
    private ?ZoneTemplateRepositoryInterface $zoneTemplateRepository;
    private DomainManagerInterface|Closure $domainManager;
    private ?ZoneOverlapService $overlapService = null;
    private ?HostnameValidator $hostnameValidator = null;
    /** @var array<string, array{id: string}|array{success: false, message: string, status: int, code: string}> */
    private array $resolvedTemplates = [];

    /**
     * @param RepositoryFactoryInterface $repositoryFactory Builds the record and domain repositories
     * @param DnsBackendProviderInterface $backendProvider Backend used by the zone template model and the domain manager
     * @param PermissionService $permissions Shares the request's permission cache
     * @param RecordChangeWriterInterface $changeLogger Receives the zone create and delete snapshots
     * @param DomainManagerInterface|Closure $domainManager Writes the zone, or a closure returning the writer, resolved on first use
     * @param PdnsCapabilities|Closure|null $capabilities What the connected server supports, or a closure returning it; null admits only the basic kinds
     * @param ZoneSigningService|null $signing Needed for enable_dnssec; without it a create is never signed
     * @param DomainRepositoryInterface|null $domainRepository Zone lookups; built from the repository factory when omitted
     */
    public function __construct(
        ZoneRepositoryInterface $zoneRepository,
        ConfigurationInterface $config,
        object $db,
        RepositoryFactoryInterface $repositoryFactory,
        DnsBackendProviderInterface $backendProvider,
        PermissionService $permissions,
        RecordChangeWriterInterface $changeLogger,
        DomainManagerInterface|Closure $domainManager,
        ?LoggerInterface $logger = null,
        PdnsCapabilities|Closure|null $capabilities = null,
        ?ZoneSigningService $signing = null,
        ?DomainRepositoryInterface $domainRepository = null,
        ?ZoneTemplateRepositoryInterface $zoneTemplateRepository = null
    ) {
        $this->zoneTemplateRepository = $zoneTemplateRepository;
        $this->repositoryFactory = $repositoryFactory;
        $this->backendProvider = $backendProvider;
        $this->zoneRepository = $zoneRepository;
        $this->domainRepository = $domainRepository;
        $this->permissions = $permissions;
        $this->config = $config;
        $this->db = $db;
        $this->logger = $logger ?? new NullLogger();
        $this->changeLogger = $changeLogger;
        $this->domainManager = $domainManager;
        $this->capabilities = $capabilities;
        $this->signing = $signing;
    }

    /**
     * Resolves a template given by name or numeric id and checks the acting user
     * may apply it (own, global, or ueberuser - the same rule the web UI enforces).
     *
     * @return array{id: string}|array{success: false, message: string, status: int, code: string}
     */
    public function resolveZoneTemplate(string $zoneTemplate, ?int $actingUserId): array
    {
        if ($zoneTemplate === 'none' || $zoneTemplate === '') {
            return ['id' => 'none'];
        }

        // Bulk registration resolves the same template for every domain.
        return $this->resolvedTemplates["$zoneTemplate:$actingUserId"] ??= $this->lookUpZoneTemplate($zoneTemplate, $actingUserId);
    }

    /**
     * @return array{id: string}|array{success: false, message: string, status: int, code: string}
     */
    private function lookUpZoneTemplate(string $zoneTemplate, ?int $actingUserId): array
    {

        $zoneTemplateModel = new ZoneTemplate($this->db, $this->config, $this->backendProvider, $this->permissions, $this->logger, $this->zoneTemplateRepository);
        if (is_numeric($zoneTemplate)) {
            if (!ZoneTemplate::zoneTemplIdExists($this->db, (int)$zoneTemplate)) {
                return ['success' => false, 'message' => 'Zone template not found', 'status' => 404, 'code' => self::ERR_TEMPLATE_NOT_FOUND];
            }
            $templateId = (int)$zoneTemplate;
        } else {
            $matchingIds = $zoneTemplateModel->getZoneTemplIdsByName($zoneTemplate);
            if (count($matchingIds) === 0) {
                return ['success' => false, 'message' => 'Zone template not found', 'status' => 404, 'code' => self::ERR_TEMPLATE_NOT_FOUND];
            } elseif (count($matchingIds) > 1) {
                return ['success' => false, 'message' => 'Multiple zone templates found with this name, please use template ID instead', 'status' => 409, 'code' => self::ERR_TEMPLATE_AMBIGUOUS];
            }
            $templateId = (int)$matchingIds[0];
        }

        if ($actingUserId !== null) {
            $isAdmin = $this->permissions->isAdmin($actingUserId);
            if (!$zoneTemplateModel->canUseTemplate($templateId, $actingUserId, $isAdmin)) {
                return ['success' => false, 'message' => 'You do not have permission to use this zone template', 'status' => 403, 'code' => self::ERR_TEMPLATE_FORBIDDEN];
            }
        }

        return ['id' => (string)$templateId];
    }

    /**
     * Create a new DNS zone
     *
     * @param string $domain Domain name
     * @param string $type Zone type (MASTER, SLAVE, NATIVE)
     * @param int|null $owner Owner user ID, or null when only group ownership is set
     * @param string $slaveMaster Master IP for slave zones
     * @param string $zoneTemplate Zone template to use
     * @param bool $enableDnssec Whether to enable DNSSEC
     * @param array<int> $groupIds Optional list of group IDs to assign as owners
     * @param int|null $actingUserId User performing the creation, used for the overlap check
     * @param string|null $soaEditApi Per-zone SOA-EDIT-API choice; null applies the dns.soa_edit_api default
     * @return array{success: true, zone_id: int, domain: string, type: string, dnssec: ?ZoneSigningResult}|array{success: false, message: string, status: int, code: string}
     */
    public function createZone(
        string $domain,
        string $type,
        ?int $owner,
        string $slaveMaster = '',
        string $zoneTemplate = 'none',
        bool $enableDnssec = false,
        array $groupIds = [],
        ?int $actingUserId = null,
        ?string $soaEditApi = null
    ): array {
        if ($owner === null && empty($groupIds)) {
            return ['success' => false, 'message' => 'At least one user or group must be assigned as owner', 'status' => 400, 'code' => self::ERR_NO_OWNER];
        }

        // Stored names are punycode, as the web form writes them.
        $domain = DnsIdnService::toPunycode(trim($domain));

        $this->hostnameValidator ??= new HostnameValidator($this->config);
        if (!$this->hostnameValidator->isValid($domain)) {
            return ['success' => false, 'message' => 'Invalid domain name', 'status' => 400, 'code' => self::ERR_INVALID_NAME];
        }

        // The validator tolerates the absolute-name dot; the existence and parent
        // checks below compare against stored names, which carry none (root stays ".").
        if ($domain !== '.') {
            $domain = preg_replace('/\.$/', '', $domain);
        }

        $domainRepository = $this->domainRepository();

        // Check if domain already exists
        if ($domainRepository->domainExists($domain)) {
            return ['success' => false, 'message' => 'Domain already exists', 'status' => 409, 'code' => self::ERR_EXISTS];
        }

        if (
            $this->config->get('dns', 'third_level_check', false)
            && DomainUtility::getDomainLevel($domain) > 2
            && $domainRepository->domainExists(DomainUtility::getSecondLevelDomain($domain))
        ) {
            return ['success' => false, 'message' => 'Domain already exists', 'status' => 409, 'code' => self::ERR_EXISTS];
        }

        // Check if non-delegation records exist (prevents zone hijacking)
        // Only delegation records (NS, DS) are allowed
        if ($this->repositoryFactory->createRecordRepository()->hasNonDelegationRecords($domain)) {
            return ['success' => false, 'message' => 'Domain already exists', 'status' => 409, 'code' => self::ERR_EXISTS];
        }

        // Block a zone that would overlap an existing zone owned by another user.
        if ($actingUserId !== null) {
            $this->overlapService ??= new ZoneOverlapService($this->db, $this->config, $this->permissions);
            if ($this->overlapService->findConflictingZone($domain, $actingUserId) !== null) {
                return ['success' => false, 'message' => 'Cannot create this zone because it overlaps an existing zone owned by another user.', 'status' => 409, 'code' => self::ERR_OVERLAP];
            }
        }

        // Catalog kinds need a server that has them; an unknown server counts as too old.
        $validTypes = ZoneKind::basicValues();
        $isCatalogKind = !in_array($type, $validTypes, true) && in_array($type, ZoneKind::values(), true);
        if ($isCatalogKind && $this->capabilities()?->supportsCatalogZones()) {
            $validTypes = ZoneKind::values();
        }
        if (!in_array($type, $validTypes, true)) {
            return [
                'success' => false,
                'message' => 'Invalid zone type. Must be one of: ' . implode(', ', $validTypes),
                'status' => 400,
                'code' => self::ERR_INVALID_TYPE,
            ];
        }

        // A zone that replicates from a primary needs one; any master list given is stored normalised.
        if (trim($slaveMaster) !== '') {
            $masters = (new IPAddressValidator())->validateMultipleIPs($slaveMaster);
            if (!$masters->isValid()) {
                return ['success' => false, 'message' => 'Invalid master servers format: ' . implode('; ', $masters->getErrors()), 'status' => 400, 'code' => self::ERR_INVALID_MASTER];
            }
            $slaveMaster = implode(',', $masters->getData());
        } elseif (ZoneType::replicatesFromPrimary($type)) {
            return ['success' => false, 'message' => 'Master IP address is required for ' . $type . ' zones', 'status' => 400, 'code' => self::ERR_MASTER_REQUIRED];
        }

        // applySerialPolicy() only logs and ignores an unoffered value, which would quietly
        // create the zone with the wrong serial policy. Reject it here instead.
        if ($soaEditApi !== null && $soaEditApi !== '') {
            $soaEditApiChoices = MetadataDefinitions::getSoaEditApiChoices($this->config);
            if (!in_array($soaEditApi, $soaEditApiChoices, true)) {
                return [
                    'success' => false,
                    'message' => 'Invalid soa_edit_api value. Must be one of: ' . implode(', ', $soaEditApiChoices),
                    'status' => 400,
                    'code' => self::ERR_INVALID_SOA_EDIT_API,
                ];
            }
        }

        $resolvedTemplate = $this->resolveZoneTemplate($zoneTemplate, $actingUserId);
        if (isset($resolvedTemplate['success'])) {
            return $resolvedTemplate;
        }
        // @phan-suppress-next-line PhanTypeInvalidDimOffset - Phan narrows the union shape to the failure arm here
        $zoneTemplate = $resolvedTemplate['id'];

        $this->logger->info(
            '[ZoneManagementService] Creating zone: {domain}, Type: {type}, Owner: {owner}, Groups: {groups}',
            ['domain' => $domain, 'type' => $type, 'owner' => $owner ?? 'none', 'groups' => implode(',', $groupIds) ?: 'none']
        );

        $created = $this->domainManager()->addDomain($this->db, $domain, $owner, $type, $slaveMaster, $zoneTemplate, $groupIds, $soaEditApi);
        if (!$created->success) {
            // Backend faults keep the generic contract string; refusals carry their reason
            return [
                'success' => false,
                'message' => $created->status === 500 ? 'Failed to create zone' : (string)$created->message,
                'status' => $created->status,
                'code' => self::ERR_ZONE_WRITE,
            ];
        }

        $zoneId = (int)$created->zoneId;

        // The zone exists either way; a refused or failed signing is reported, not an error.
        $signed = null;
        if ($enableDnssec && $this->signing !== null) {
            try {
                $signed = $this->signing->sign($zoneId, $domain);
            } catch (Exception $e) {
                $this->logger->error('[ZoneManagementService] Failed to secure zone with DNSSEC: {error}', ['error' => $e->getMessage()]);
                $signed = new ZoneSigningResult(ZoneSigningOutcome::SECURE_FAILED);
            }
        }

        return [
            'success' => true,
            'zone_id' => $zoneId,
            'domain' => $domain,
            'type' => $type,
            'dnssec' => $signed,
        ];
    }

    /**
     * Update a zone
     *
     * @param int $zoneId Zone ID
     * @param array $updates Array of field => value pairs to update
     * @return array Result array with success status and message
     */
    public function updateZone(int $zoneId, array $updates): array
    {
        // Check if zone exists
        if (!$this->domainRepository()->zoneIdExists($zoneId)) {
            return ['success' => false, 'message' => 'Zone not found', 'status' => 404];
        }

        // Snapshot before
        $beforeZone = null;
        try {
            $beforeZone = $this->zoneRepository->getZoneById($zoneId);
        } catch (Throwable $e) {
            $this->logger->warning('Failed to fetch zone before metadata update for change log: {error}', ['error' => $e->getMessage()]);
        }

        // Update the zone
        try {
            $success = $this->zoneRepository->updateZone($zoneId, $updates);
        } catch (\InvalidArgumentException $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'status' => 400];
        }

        if (!$success) {
            return ['success' => false, 'message' => 'Failed to update zone', 'status' => 500];
        }

        if ($beforeZone !== null) {
            try {
                $afterZone = $this->zoneRepository->getZoneById($zoneId);
                if ($afterZone !== null) {
                    $this->changeLogger->logZoneMetadataEdit($beforeZone, $afterZone);
                }
            } catch (Throwable $e) {
                $this->logger->warning('Failed to write zone metadata edit log: {error}', ['error' => $e->getMessage()]);
            }
        }

        return ['success' => true, 'message' => 'Zone updated successfully'];
    }

    /**
     * Delete a zone
     *
     * @param int $zoneId Zone ID
     * @return array Result array with success status and message
     */
    public function deleteZone(int $zoneId): array
    {
        // Check if zone exists
        if (!$this->domainRepository()->zoneIdExists($zoneId)) {
            return ['success' => false, 'message' => 'Zone not found', 'status' => 404, 'code' => self::ERR_NOT_FOUND];
        }

        // Snapshot the zone for the audit log before any state is touched.
        $zoneSnapshot = null;
        $recordCountBefore = 0;
        try {
            $zoneSnapshot = $this->zoneRepository->getZoneById($zoneId);
            if ($zoneSnapshot !== null && isset($zoneSnapshot['record_count'])) {
                $recordCountBefore = (int) $zoneSnapshot['record_count'];
            }
        } catch (Throwable $e) {
            $this->logger->warning('Failed to snapshot zone before delete: {error}', ['error' => $e->getMessage()]);
        }

        // zone_template_sync.zone_id cascades from zones.id, so deleting the zones rows
        // clears it. Resolving zones.id here would need CanonicalZoneSql to tell the two
        // overlapping id spaces apart, and a plain domain_id lookup hits the wrong zone.

        // Delete the zone
        $success = $this->zoneRepository->deleteZone($zoneId);

        if (!$success) {
            return ['success' => false, 'message' => 'Failed to delete zone', 'status' => 500, 'code' => self::ERR_ZONE_WRITE];
        }

        if ($zoneSnapshot !== null) {
            try {
                $this->changeLogger->logZoneDelete($zoneSnapshot, $recordCountBefore);
            } catch (Throwable $e) {
                $this->logger->warning('Failed to write zone delete log: {error}', ['error' => $e->getMessage()]);
            }
        }

        return ['success' => true, 'message' => 'Zone deleted successfully'];
    }

    /**
     * Applies a zone template (or "none" to unlink) to an existing zone, replacing
     * the template-managed records. The caller must be allowed to use the template.
     *
     * @return array{success: true, template_id: int}|array{success: false, message: string, status: int, code: string}
     */
    public function applyTemplate(int $zoneId, string $template, int $actingUserId): array
    {
        if (!$this->domainRepository()->zoneIdExists($zoneId)) {
            return ['success' => false, 'message' => 'Zone not found', 'status' => 404, 'code' => self::ERR_NOT_FOUND];
        }

        // Applying a template writes records, which read-only zones cannot accept
        if (ZoneType::isReadOnly($this->domainRepository()->getDomainType($zoneId))) {
            return ['success' => false, 'message' => 'Cannot apply a template to a read-only zone', 'status' => 400, 'code' => self::ERR_READ_ONLY];
        }

        $resolved = $this->resolveZoneTemplate($template, $actingUserId);
        $resolvedId = $resolved['id'] ?? null;
        if ($resolvedId === null) {
            // @phan-suppress-next-line PhanTypeMismatchReturn union shape narrowing
            return $resolved;
        }
        $templateId = $resolvedId === 'none' ? 0 : (int)$resolvedId;

        $written = $this->domainManager()->updateZoneRecords(
            (string)$this->config->get('database', 'type', 'mysql'),
            (int)$this->config->get('dns', 'ttl', 86400),
            $zoneId,
            $templateId
        );
        if (!$written->success) {
            return ['success' => false, 'message' => (string)$written->message, 'status' => $written->status, 'code' => self::ERR_ZONE_WRITE];
        }

        return ['success' => true, 'template_id' => $templateId];
    }

    private function capabilities(): ?PdnsCapabilities
    {
        if ($this->capabilities instanceof Closure) {
            $this->capabilities = ($this->capabilities)();
        }

        return $this->capabilities;
    }

    private function domainManager(): DomainManagerInterface
    {
        if ($this->domainManager instanceof Closure) {
            $this->domainManager = ($this->domainManager)();
        }

        return $this->domainManager;
    }

    private function domainRepository(): DomainRepositoryInterface
    {
        return $this->domainRepository ??= $this->repositoryFactory->createDomainRepository();
    }
}
