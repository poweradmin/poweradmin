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

namespace Poweradmin\Application\Service;

use PDO;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Repository\UserGroupMemberRepositoryInterface;
use Poweradmin\Domain\Repository\UserGroupRepositoryInterface;
use Poweradmin\Domain\Repository\UserRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneGroupRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Model\ZoneTemplate;
use Poweradmin\Domain\Service\Dns\DomainManager;
use Poweradmin\Domain\Service\Dns\DomainManagerInterface;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Domain\Service\Dns\SupermasterManager;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Domain\Service\CatalogZoneService;
use Poweradmin\Domain\Service\DnsBackendProviderInterface;
use Poweradmin\Domain\Service\PdnsCapabilities;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Domain\Service\BatchReverseRecordCreator;
use Poweradmin\Domain\Service\DomainRecordCreator;
use Poweradmin\Domain\Service\ReverseRecordCreator;
use Poweradmin\Domain\Service\ReverseTtlResolver;
use Poweradmin\Domain\Service\ZoneEditService;
use Poweradmin\Domain\Service\ZoneListPermissionService;
use Poweradmin\Domain\Service\UserProfileAssembler;
use Poweradmin\Domain\Service\UserManagementService;
use Poweradmin\Domain\Service\UserPreferenceService;
use Poweradmin\Domain\Service\UserTimezoneService;
use Poweradmin\Domain\Service\ZoneCreateOwnershipResolver;
use Poweradmin\Domain\Service\ZoneManagementService;
use Poweradmin\Domain\Service\ZoneMetadataService;
use Poweradmin\Domain\Service\ZoneOwnershipModeService;
use Poweradmin\Domain\Service\ZoneSigningService;
use Poweradmin\Domain\Service\ZoneValidationService;
use Poweradmin\Domain\Service\DnssecProviderInterface;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\RecordChangeLogger;
use Poweradmin\Infrastructure\Repository\DbPermissionTemplateRepository;
use Poweradmin\Infrastructure\Repository\DbRecordTypeDefaultRepository;
use Poweradmin\Infrastructure\Repository\DbUserGroupMemberRepository;
use Poweradmin\Infrastructure\Repository\DbUserGroupRepository;
use Poweradmin\Infrastructure\Repository\DbUserPreferenceRepository;
use Poweradmin\Infrastructure\Repository\DbUserRepository;
use Poweradmin\Infrastructure\Repository\DbZoneGroupRepository;
use Poweradmin\Infrastructure\Service\DnsServiceFactory;
use Psr\Log\LoggerInterface;

/**
 * Builds the repositories and domain services controllers ask for, and
 * memoizes the per-request shared instances (backend provider, permission
 * cache). Owns the wiring so BaseController only exposes thin accessors.
 */
class ControllerServiceFactory
{
    private PDO $db;
    private ConfigurationManager $config;
    private LoggerInterface $logger;

    private ?DnsBackendProviderInterface $dnsBackendProvider = null;
    private ?PermissionService $permissionService = null;
    private ?ApiPermissionService $apiPermissionService = null;
    private ?UserManagementService $userManagementService = null;
    private ?ZoneOwnershipModeService $zoneOwnershipModeService = null;
    private ?ZoneSigningService $zoneSigningService = null;
    private ?AuditService $auditService = null;
    private ?RecordManagerInterface $recordManager = null;
    private ?RecordCommentService $recordCommentService = null;
    private ?CatalogZoneService $catalogZoneService = null;
    private ?UserPreferenceService $userPreferenceService = null;
    private ?RepositoryFactory $repositoryFactory = null;
    private ?DomainManagerInterface $domainManager = null;
    private ?SupermasterManager $supermasterManager = null;
    private ?ZoneTemplate $zoneTemplate = null;
    private ?DnsDataService $dnsDataService = null;
    private ?ZoneRepositoryInterface $zoneRepository = null;
    private ?DomainRepositoryInterface $domainRepository = null;
    private ?RecordRepositoryInterface $recordRepository = null;
    private ?SOARecordManagerInterface $soaRecordManager = null;
    private ?DnssecProviderInterface $dnssecProvider = null;
    private ?PowerdnsApiClient $apiClient = null;
    private bool $apiClientResolved = false;

    public function __construct(PDO $db, ConfigurationManager $config, LoggerInterface $logger)
    {
        $this->db = $db;
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Shared instance so its per-request preference cache survives across
     * consumers (pagination, timezone, and direct preference reads).
     */
    public function userPreferenceService(): UserPreferenceService
    {
        if ($this->userPreferenceService === null) {
            $repository = new DbUserPreferenceRepository($this->db);
            $this->userPreferenceService = new UserPreferenceService($repository, $this->config);
        }
        return $this->userPreferenceService;
    }

    public function userTimezoneService(): UserTimezoneService
    {
        return new UserTimezoneService($this->userPreferenceService(), $this->config);
    }

    public function paginationService(): PaginationService
    {
        return new PaginationService($this->userPreferenceService());
    }

    /**
     * Providers are stateless, so one shared instance serves the whole request.
     */
    public function dnsBackendProvider(): DnsBackendProviderInterface
    {
        return $this->dnsBackendProvider ??= DnsBackendProviderFactory::create($this->db, $this->config, $this->logger);
    }

    /**
     * The provider's API client, or null outside API backend mode. Sharing it
     * keeps one set of per-request read caches for the whole request.
     */
    public function apiClient(): ?PowerdnsApiClient
    {
        if (!$this->apiClientResolved) {
            $this->apiClientResolved = true;
            $this->apiClient = DnsBackendProviderFactory::apiClientFrom($this->dnsBackendProvider());
        }

        return $this->apiClient;
    }

    public function soaRecordManager(): SOARecordManagerInterface
    {
        return $this->soaRecordManager ??= DnsServiceFactory::createSOARecordManager(
            $this->db,
            $this->config,
            $this->dnsBackendProvider()
        );
    }

    public function dnssecProvider(): DnssecProviderInterface
    {
        return $this->dnssecProvider ??= DnssecProviderFactory::create($this->db, $this->config, $this->apiClient());
    }

    public function dnsDataService(): DnsDataService
    {
        return $this->dnsDataService ??= new DnsDataService($this->dnsBackendProvider(), $this->db, $this->config);
    }

    public function zoneRepository(): ZoneRepositoryInterface
    {
        return $this->zoneRepository ??= $this->repositoryFactory()->createZoneRepository();
    }

    public function domainRepository(): DomainRepositoryInterface
    {
        return $this->domainRepository ??= $this->repositoryFactory()->createDomainRepository();
    }

    public function recordRepository(): RecordRepositoryInterface
    {
        return $this->recordRepository ??= $this->repositoryFactory()->createRecordRepository();
    }

    public function userRepository(): UserRepositoryInterface
    {
        return new DbUserRepository($this->db, $this->config);
    }

    /**
     * Shared instance so the per-user permission cache spans the whole request
     */
    public function permissionService(): PermissionService
    {
        return $this->permissionService ??= new PermissionService($this->userRepository());
    }

    /**
     * The permission rules that need group or template lookups beyond PermissionService
     */
    public function apiPermissionService(): ApiPermissionService
    {
        return $this->apiPermissionService ??= new ApiPermissionService($this->db, $this->permissionService());
    }

    /**
     * The user service the API uses, over the request's shared permission cache
     */
    public function userManagementService(): UserManagementService
    {
        return $this->userManagementService ??= new UserManagementService(
            $this->userRepository(),
            $this->permissionService(),
            new UserProfileAssembler($this->permissionService(), $this->userGroupRepository()),
            new UserAuthenticationService(
                $this->config->get('security', 'password_encryption', 'bcrypt'),
                $this->config->get('security', 'password_cost', 12)
            ),
            new PasswordPolicyService($this->config),
            (bool)$this->config->get('ldap', 'enabled', false),
            $this->domainManager()
        );
    }

    public function userGroupRepository(): UserGroupRepositoryInterface
    {
        return new DbUserGroupRepository($this->db);
    }

    public function zoneManagementService(?PdnsCapabilities $capabilities = null): ZoneManagementService
    {
        return new ZoneManagementService(
            $this->zoneRepository(),
            $this->config,
            $this->db,
            $this->logger,
            capabilities: $capabilities,
            signing: $this->zoneSigningService(),
            domainRepository: $this->domainRepository(),
            permissions: $this->permissionService()
        );
    }

    public function auditService(): AuditService
    {
        return $this->auditService ??= new AuditService($this->db);
    }

    public function zoneMetadataService(): ZoneMetadataService
    {
        return new ZoneMetadataService(
            $this->zoneRepository(),
            $this->config,
            $this->permissionService(),
            $this->auditService(),
            new RecordChangeLogger($this->db),
            $this->apiClient(),
            $this->logger
        );
    }

    public function zoneSigningService(): ZoneSigningService
    {
        return $this->zoneSigningService ??= new ZoneSigningService(
            $this->dnssecProvider(),
            new ZoneValidationService($this->recordRepository()),
            $this->soaRecordManager(),
            $this->auditService(),
            $this->config,
            $this->logger
        );
    }

    public function zoneOwnershipModeService(): ZoneOwnershipModeService
    {
        return $this->zoneOwnershipModeService ??= new ZoneOwnershipModeService($this->config);
    }

    public function zoneCreateOwnershipResolver(): ZoneCreateOwnershipResolver
    {
        return new ZoneCreateOwnershipResolver($this->zoneOwnershipModeService(), $this->permissionService(), $this->userGroupRepository());
    }

    public function zoneOwnershipFormResolver(): ZoneOwnershipFormResolver
    {
        return new ZoneOwnershipFormResolver($this->zoneOwnershipModeService(), $this->zoneCreateOwnershipResolver());
    }

    public function userGroupMemberRepository(): UserGroupMemberRepositoryInterface
    {
        return new DbUserGroupMemberRepository($this->db);
    }

    public function permissionTemplateRepository(): DbPermissionTemplateRepository
    {
        return new DbPermissionTemplateRepository($this->db, $this->config);
    }

    public function permissionTemplateWriteService(): PermissionTemplateWriteService
    {
        return new PermissionTemplateWriteService($this->permissionTemplateRepository(), $this->userRepository());
    }

    public function dashboardStatsService(): DashboardStatsService
    {
        return new DashboardStatsService(
            $this->db,
            $this->config,
            $this->logger,
            $this->userRepository(),
            $this->userGroupRepository(),
            $this->zoneRepository(),
            $this->dnsBackendProvider()
        );
    }

    public function zoneListPermissionService(): ZoneListPermissionService
    {
        return new ZoneListPermissionService($this->zoneRepository(), $this->zoneGroupRepository(), $this->userGroupRepository());
    }

    public function zoneGroupRepository(): ZoneGroupRepositoryInterface
    {
        return new DbZoneGroupRepository($this->db, $this->config, DnsBackendProviderFactory::isApiBackend($this->config));
    }

    public function reverseTtlResolver(): ReverseTtlResolver
    {
        return new ReverseTtlResolver($this->config, new DbRecordTypeDefaultRepository($this->db));
    }

    public function recordManager(): RecordManagerInterface
    {
        return $this->recordManager ??= DnsServiceFactory::createRecordManager($this->db, $this->config, $this->dnsBackendProvider());
    }

    public function recordCommentService(): RecordCommentService
    {
        return $this->recordCommentService ??= new RecordCommentService($this->repositoryFactory()->createRecordCommentRepository());
    }

    public function zoneEditService(): ZoneEditService
    {
        $comments = $this->recordCommentService();

        return new ZoneEditService(
            $this->config,
            $this->permissionService(),
            $this->zoneRepository(),
            $this->domainRepository(),
            $this->recordRepository(),
            $this->recordManager(),
            $this->soaRecordManager(),
            $comments,
            new RecordCommentSyncService($comments, $this->recordRepository(), $this->dnsBackendProvider()),
            $this->auditService()
        );
    }

    public function recordManagerService(): RecordManagerService
    {
        return new RecordManagerService(
            $this->db,
            $this->domainRepository(),
            $this->recordManager(),
            $this->recordCommentService(),
            $this->auditService(),
            $this->config,
            $this->dnsBackendProvider()
        );
    }

    public function recordAddService(): RecordAddService
    {
        $ttlResolver = $this->reverseTtlResolver();

        return new RecordAddService(
            $this->recordManagerService(),
            $this->reverseRecordCreator(),
            new DomainRecordCreator($this->config, $this->domainRepository(), $this->recordManager(), null, $ttlResolver),
            $ttlResolver
        );
    }

    public function reverseRecordCreator(): ReverseRecordCreator
    {
        return new ReverseRecordCreator(
            $this->db,
            $this->config,
            $this->auditService(),
            $this->domainRepository(),
            $this->recordManager(),
            $this->dnsBackendProvider()
        );
    }

    public function batchReverseRecordCreator(): BatchReverseRecordCreator
    {
        return new BatchReverseRecordCreator(
            $this->db,
            $this->config,
            $this->auditService(),
            $this->domainRepository(),
            $this->recordManager(),
            null,
            $this->recordRepository()
        );
    }

    public function domainManager(): DomainManagerInterface
    {
        return $this->domainManager ??= new DomainManager(
            $this->db,
            $this->config,
            $this->soaRecordManager(),
            $this->domainRepository(),
            $this->dnsBackendProvider()
        );
    }

    public function supermasterManager(): SupermasterManager
    {
        return $this->supermasterManager ??= DnsServiceFactory::createSupermasterManager($this->db, $this->config, $this->dnsBackendProvider());
    }

    public function zoneTemplate(): ZoneTemplate
    {
        return $this->zoneTemplate ??= new ZoneTemplate($this->db, $this->config, $this->dnsBackendProvider(), $this->logger);
    }

    public function catalogZoneService(): CatalogZoneService
    {
        return $this->catalogZoneService ??= new CatalogZoneService(
            $this->dnsBackendProvider(),
            $this->permissionService(),
            $this->auditService()
        );
    }

    public function repositoryFactory(?DnsBackendProviderInterface $backendProvider = null): RepositoryFactory
    {
        // Only the default-provider factory is shared; an explicit provider
        // means the caller wants its own wiring
        // Handing back the shared provider must not build a second factory. Compare
        // the property, not the accessor, so an explicit provider never forces one
        if ($backendProvider !== null && $backendProvider !== $this->dnsBackendProvider) {
            return new RepositoryFactory($this->db, $this->config, $backendProvider, $this->logger);
        }
        return $this->repositoryFactory ??= new RepositoryFactory($this->db, $this->config, $this->dnsBackendProvider(), $this->logger);
    }
}
