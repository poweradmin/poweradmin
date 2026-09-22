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

namespace Poweradmin\Application\Service\Factory;

use Closure;
use PDO;
use Poweradmin\Application\Service\ControllerServiceFactory;
use Poweradmin\Application\Service\Web\DashboardStatsService;
use Poweradmin\Application\Service\Backend\DnsBackendProviderFactory;
use Poweradmin\Application\Service\Zone\ZoneCreateService;
use Poweradmin\Application\Service\Zone\ZoneGroupService;
use Poweradmin\Application\Service\Zone\ZoneOwnershipFormResolver;
use Poweradmin\Domain\Repository\TemplateRecordLinkRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneTemplateSyncRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneGroupRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneTemplateRepositoryInterface;
use Poweradmin\Domain\Service\Zone\CatalogZoneService;
use Poweradmin\Domain\Service\Dns\DomainManager;
use Poweradmin\Domain\Service\Dns\DomainManagerInterface;
use Poweradmin\Domain\Service\Dns\SupermasterManager;
use Poweradmin\Domain\Service\Dns\ZoneTemplateApplier;
use Poweradmin\Domain\Model\PdnsCapabilities;
use Poweradmin\Domain\Service\Auth\UserContextService;
use Poweradmin\Domain\Service\Zone\ZoneAccountSyncService;
use Poweradmin\Domain\Service\Zone\ZoneCreateOwnershipResolver;
use Poweradmin\Domain\Service\Auth\ZoneListPermissionService;
use Poweradmin\Domain\Service\Zone\ZoneManagementService;
use Poweradmin\Domain\Service\Zone\ZoneMetadataService;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipGuard;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipModeService;
use Poweradmin\Domain\Service\Zone\ZoneSigningService;
use Poweradmin\Application\Service\Zone\ZoneSortingService;
use Poweradmin\Domain\Service\Template\ZoneTemplateAccessPolicy;
use Poweradmin\Domain\Service\Template\ZoneTemplatePlaceholders;
use Poweradmin\Domain\Service\Template\ZoneTemplateRecordService;
use Poweradmin\Domain\Service\Template\ZoneTemplateService;
use Poweradmin\Domain\Service\Template\ZoneTemplateWriteService;
use Poweradmin\Domain\Service\Zone\ZoneValidationService;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Infrastructure\Logger\DbZoneLogger;
use Poweradmin\Infrastructure\Repository\DbZoneAccountOwnerRepository;
use Poweradmin\Infrastructure\Repository\DbZoneTemplateSyncRepository;
use Poweradmin\Infrastructure\Repository\DbTemplateRecordLinkRepository;
use Poweradmin\Infrastructure\Repository\DbZoneGroupRepository;
use Poweradmin\Infrastructure\Repository\DbZoneTemplateRepository;
use Poweradmin\Infrastructure\Utility\ReverseZoneSorting;
use Psr\Log\LoggerInterface;

/**
 * Zone lifecycle: creation, ownership, templates, signing, metadata and the
 * zone lists.
 */
final class ZoneServices
{
    private PDO $db;
    private ConfigurationInterface $config;
    private LoggerInterface $logger;
    private ControllerServiceFactory $services;

    private ?ZoneOwnershipModeService $zoneOwnershipModeService = null;
    private ?ZoneSigningService $zoneSigningService = null;
    private ?CatalogZoneService $catalogZoneService = null;
    private ?DomainManagerInterface $domainManager = null;
    private ?ZoneTemplateSyncRepositoryInterface $zoneTemplateSync = null;
    private ?SupermasterManager $supermasterManager = null;
    private ?ZoneTemplateService $zoneTemplateService = null;
    private ?ZoneTemplateAccessPolicy $zoneTemplateAccessPolicy = null;
    private ?ZoneTemplateWriteService $zoneTemplateWriteService = null;
    private ?ZoneTemplateRecordService $zoneTemplateRecordService = null;
    private ?ZoneTemplateRepositoryInterface $zoneTemplateRepository = null;

    public function __construct(PDO $db, ConfigurationInterface $config, LoggerInterface $logger, ControllerServiceFactory $services)
    {
        $this->db = $db;
        $this->config = $config;
        $this->logger = $logger;
        $this->services = $services;
    }

    public function zoneManagementService(PdnsCapabilities|Closure|null $capabilities = null): ZoneManagementService
    {
        return new ZoneManagementService(
            $this->services->zoneRepository(),
            $this->config,
            $this->services->repositoryFactory(),
            $this->services->permissionService(),
            $this->services->recordChangeLogger(),
            fn(): DomainManagerInterface => $this->domainManager(),
            $this->zoneTemplateService(),
            $this->logger,
            capabilities: $capabilities,
            signing: $this->zoneSigningService(),
            domainRepository: $this->services->domainRepository()
        );
    }

    /**
     * The zone creation flow of the web forms, told what the connected server
     * supports the same way zoneManagementService() is.
     */
    public function zoneCreateService(PdnsCapabilities|Closure|null $capabilities = null): ZoneCreateService
    {
        return new ZoneCreateService(
            $this->zoneOwnershipFormResolver(),
            $this->zoneManagementService($capabilities),
            $this->services->apiPermissionService(),
            $this->services->auditService()
        );
    }

    public function zoneSigningService(): ZoneSigningService
    {
        return $this->zoneSigningService ??= new ZoneSigningService(
            $this->services->dnssecProvider(),
            new ZoneValidationService($this->services->recordRepository()),
            $this->services->soaRecordManager(),
            $this->services->auditService(),
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
        return new ZoneCreateOwnershipResolver($this->zoneOwnershipModeService(), $this->services->permissionService(), $this->services->userGroupRepository(), $this->services->userRepository());
    }

    public function zoneOwnershipFormResolver(): ZoneOwnershipFormResolver
    {
        return new ZoneOwnershipFormResolver($this->zoneOwnershipModeService(), $this->zoneCreateOwnershipResolver(), $this->services->permissionService());
    }

    public function zoneOwnershipGuard(): ZoneOwnershipGuard
    {
        return new ZoneOwnershipGuard($this->services->zoneRepository(), $this->zoneGroupRepository(), $this->zoneOwnershipModeService());
    }

    public function zoneGroupService(): ZoneGroupService
    {
        return new ZoneGroupService($this->zoneGroupRepository(), $this->services->userGroupRepository(), $this->zoneOwnershipGuard());
    }

    public function zoneListPermissionService(): ZoneListPermissionService
    {
        return new ZoneListPermissionService($this->services->zoneRepository(), $this->zoneGroupRepository(), $this->services->userGroupRepository());
    }

    public function zoneGroupRepository(): ZoneGroupRepositoryInterface
    {
        return new DbZoneGroupRepository($this->db, $this->config, DnsBackendProviderFactory::isApiBackend($this->config));
    }

    /**
     * @param UserContextService $userContext The controller's session view, where the sort state lives
     */
    public function zoneSortingService(UserContextService $userContext): ZoneSortingService
    {
        return new ZoneSortingService(new ReverseZoneSorting(), $userContext);
    }

    public function zoneMetadataService(): ZoneMetadataService
    {
        return new ZoneMetadataService(
            $this->services->zoneMetadataStore(),
            $this->config,
            $this->services->permissionService(),
            $this->services->auditService(),
            $this->services->recordChangeLogger(),
            $this->logger
        );
    }

    public function dashboardStatsService(): DashboardStatsService
    {
        return new DashboardStatsService(
            $this->logger,
            $this->services->userRepository(),
            $this->services->userGroupRepository(),
            $this->services->zoneRepository(),
            $this->services->dnsBackendProvider(),
            $this->services->session()
        );
    }

    public function domainManager(): DomainManagerInterface
    {
        return $this->domainManager ??= new DomainManager(
            $this->services->transaction(),
            $this->config,
            $this->services->domainRepository(),
            $this->services->repositoryFactory(),
            $this->services->dnsBackendProvider(),
            $this->services->permissionService(),
            $this->services->userRepository(),
            $this->services->recordChangeLogger(),
            $this->zoneTemplateApplier(),
            $this->zoneTemplateRepository(),
            new ZoneTemplatePlaceholders($this->config),
            $this->zoneTemplateSync(),
            $this->templateRecordLinkRepository(),
            $this->zoneGroupRepository(),
            $this->zoneAccountSyncService(),
            $this->services->actor()
        );
    }

    public function zoneTemplateSync(): ZoneTemplateSyncRepositoryInterface
    {
        return $this->zoneTemplateSync ??= new DbZoneTemplateSyncRepository($this->db, $this->config);
    }

    public function templateRecordLinkRepository(): TemplateRecordLinkRepositoryInterface
    {
        return new DbTemplateRecordLinkRepository($this->db, $this->config, $this->services->dnsBackendProvider());
    }

    public function zoneTemplateApplier(): ZoneTemplateApplier
    {
        return new ZoneTemplateApplier(
            $this->services->transaction(),
            $this->services->dnsBackendProvider(),
            $this->services->soaRecordManager(),
            $this->services->domainRepository(),
            $this->zoneTemplateRepository(),
            $this->templateRecordLinkRepository(),
            $this->zoneTemplateSync(),
            new ZoneTemplatePlaceholders($this->config),
            $this->services->recordChangeLogger(),
            $this->logger
        );
    }

    public function supermasterManager(): SupermasterManager
    {
        return $this->supermasterManager ??= new SupermasterManager($this->services->userRepository(), $this->config, $this->services->dnsBackendProvider());
    }

    public function zoneAccountSyncService(): ZoneAccountSyncService
    {
        $backend = $this->services->dnsBackendProvider();
        return new ZoneAccountSyncService(new DbZoneAccountOwnerRepository($this->db, $backend->allocatesZoneIdsLocally()), $this->config, $backend);
    }

    /**
     * Shared so the zone template model, the domain manager and the zone
     * management service all read through one instance.
     */
    public function zoneTemplateRepository(): ZoneTemplateRepositoryInterface
    {
        return $this->zoneTemplateRepository ??= new DbZoneTemplateRepository($this->db, $this->config, $this->services->dnsBackendProvider());
    }

    public function zoneTemplateAccessPolicy(): ZoneTemplateAccessPolicy
    {
        return $this->zoneTemplateAccessPolicy ??= new ZoneTemplateAccessPolicy(
            $this->zoneTemplateRepository(),
            $this->services->permissionService(),
            $this->services->actor()
        );
    }

    public function zoneTemplateWriteService(): ZoneTemplateWriteService
    {
        return $this->zoneTemplateWriteService ??= new ZoneTemplateWriteService(
            $this->zoneTemplateRepository(),
            $this->zoneTemplateAccessPolicy(),
            $this->config,
            $this->logger
        );
    }

    public function zoneTemplateRecordService(): ZoneTemplateRecordService
    {
        return $this->zoneTemplateRecordService ??= new ZoneTemplateRecordService(
            $this->zoneTemplateRepository(),
            $this->zoneTemplateAccessPolicy(),
            $this->config,
            $this->services->dnsBackendProvider()
        );
    }

    public function zoneTemplateService(): ZoneTemplateService
    {
        return $this->zoneTemplateService ??= new ZoneTemplateService(
            $this->zoneTemplateRepository(),
            $this->zoneTemplateAccessPolicy(),
            $this->zoneTemplateWriteService(),
            $this->zoneTemplateRecordService()
        );
    }

    public function catalogZoneService(): CatalogZoneService
    {
        return $this->catalogZoneService ??= new CatalogZoneService(
            $this->services->dnsBackendProvider(),
            $this->services->permissionService(),
            $this->services->auditService()
        );
    }

    public function zoneLogger(): DbZoneLogger
    {
        return new DbZoneLogger($this->db, $this->config, $this->services->dnsBackendProvider());
    }
}
