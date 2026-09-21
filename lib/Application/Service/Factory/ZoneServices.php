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
use Poweradmin\Application\Service\DashboardStatsService;
use Poweradmin\Application\Service\DnsBackendProviderFactory;
use Poweradmin\Application\Service\ZoneCreateService;
use Poweradmin\Application\Service\ZoneOwnershipFormResolver;
use Poweradmin\Domain\Repository\ZoneGroupRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneTemplateRepositoryInterface;
use Poweradmin\Domain\Service\Zone\CatalogZoneService;
use Poweradmin\Domain\Service\Dns\DomainManager;
use Poweradmin\Domain\Service\Dns\DomainManagerInterface;
use Poweradmin\Domain\Service\Dns\SupermasterManager;
use Poweradmin\Domain\Service\Dns\ZoneTemplateApplier;
use Poweradmin\Domain\Model\PdnsCapabilities;
use Poweradmin\Domain\Service\Auth\UserContextService;
use Poweradmin\Domain\Service\Zone\ZoneCreateOwnershipResolver;
use Poweradmin\Domain\Service\Auth\ZoneListPermissionService;
use Poweradmin\Domain\Service\Zone\ZoneManagementService;
use Poweradmin\Domain\Service\Zone\ZoneMetadataService;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipModeService;
use Poweradmin\Domain\Service\Zone\ZoneSigningService;
use Poweradmin\Domain\Service\Zone\ZoneSortingService;
use Poweradmin\Domain\Service\Template\ZoneTemplatePlaceholders;
use Poweradmin\Domain\Service\Template\ZoneTemplateService;
use Poweradmin\Domain\Service\Template\ZoneTemplateSyncService;
use Poweradmin\Domain\Service\Zone\ZoneValidationService;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Infrastructure\Logger\DbZoneLogger;
use Poweradmin\Infrastructure\Repository\DbTemplateRecordLinkRepository;
use Poweradmin\Infrastructure\Repository\DbZoneGroupRepository;
use Poweradmin\Infrastructure\Repository\DbZoneTemplateRepository;
use Poweradmin\Application\Service\DnsServiceFactory;
use Poweradmin\Infrastructure\Utility\ReverseZoneSorting;
use Psr\Log\LoggerInterface;

/**
 * Zone lifecycle: creation, ownership, templates, signing, metadata and the
 * zone lists.
 */
class ZoneServices
{
    private PDO $db;
    private ConfigurationInterface $config;
    private LoggerInterface $logger;
    private ControllerServiceFactory $services;

    private ?ZoneOwnershipModeService $zoneOwnershipModeService = null;
    private ?ZoneSigningService $zoneSigningService = null;
    private ?CatalogZoneService $catalogZoneService = null;
    private ?DomainManagerInterface $domainManager = null;
    private ?SupermasterManager $supermasterManager = null;
    private ?ZoneTemplateService $zoneTemplateService = null;
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
            $this->db,
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

    public function zoneListPermissionService(): ZoneListPermissionService
    {
        return new ZoneListPermissionService($this->services->zoneRepository(), $this->zoneGroupRepository(), $this->services->userGroupRepository());
    }

    public function zoneGroupRepository(): ZoneGroupRepositoryInterface
    {
        return new DbZoneGroupRepository($this->db, $this->config, DnsBackendProviderFactory::isApiBackend($this->config));
    }

    /**
     * @param UserContextService|null $userContext The controller's session view, so tests can plant one
     */
    public function zoneSortingService(?UserContextService $userContext = null): ZoneSortingService
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
            $this->services->dnsBackendProvider()
        );
    }

    public function domainManager(): DomainManagerInterface
    {
        return $this->domainManager ??= new DomainManager(
            $this->db,
            $this->config,
            $this->services->domainRepository(),
            $this->services->repositoryFactory(),
            $this->services->dnsBackendProvider(),
            $this->services->permissionService(),
            $this->services->userRepository(),
            $this->services->recordChangeLogger(),
            $this->zoneTemplateApplier(),
            $this->zoneTemplateRepository(),
            new ZoneTemplatePlaceholders($this->config)
        );
    }

    public function zoneTemplateApplier(): ZoneTemplateApplier
    {
        return new ZoneTemplateApplier(
            $this->db,
            $this->services->dnsBackendProvider(),
            $this->services->soaRecordManager(),
            $this->services->domainRepository(),
            $this->zoneTemplateRepository(),
            new DbTemplateRecordLinkRepository($this->db, $this->config, $this->services->dnsBackendProvider()),
            new ZoneTemplateSyncService($this->db, $this->config),
            new ZoneTemplatePlaceholders($this->config),
            $this->services->recordChangeLogger(),
            $this->logger
        );
    }

    public function supermasterManager(): SupermasterManager
    {
        return $this->supermasterManager ??= DnsServiceFactory::createSupermasterManager($this->db, $this->config, $this->services->dnsBackendProvider());
    }

    /**
     * Shared so the zone template model, the domain manager and the zone
     * management service all read through one instance.
     */
    public function zoneTemplateRepository(): ZoneTemplateRepositoryInterface
    {
        return $this->zoneTemplateRepository ??= new DbZoneTemplateRepository($this->db, $this->config, $this->services->dnsBackendProvider());
    }

    public function zoneTemplateService(): ZoneTemplateService
    {
        return $this->zoneTemplateService ??= new ZoneTemplateService(
            $this->zoneTemplateRepository(),
            $this->config,
            $this->services->dnsBackendProvider(),
            $this->services->permissionService(),
            new UserContextService(),
            $this->logger
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
