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
use Poweradmin\Domain\Repository\UserRepository;
use Poweradmin\Domain\Repository\ZoneGroupRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\Dns\DomainManagerInterface;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\CatalogZoneService;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Domain\Service\ReverseTtlResolver;
use Poweradmin\Domain\Service\UserPreferenceService;
use Poweradmin\Domain\Service\UserTimezoneService;
use Poweradmin\Domain\Service\DnssecProvider;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
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

    private ?DnsBackendProvider $dnsBackendProvider = null;
    private ?PermissionService $permissionService = null;
    private ?CatalogZoneService $catalogZoneService = null;
    private ?UserPreferenceService $userPreferenceService = null;
    private ?RepositoryFactory $repositoryFactory = null;
    private ?SOARecordManagerInterface $soaRecordManager = null;
    private ?DnssecProvider $dnssecProvider = null;
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
    public function dnsBackendProvider(): DnsBackendProvider
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

    public function dnssecProvider(): DnssecProvider
    {
        return $this->dnssecProvider ??= DnssecProviderFactory::create($this->db, $this->config, $this->apiClient());
    }

    public function dnsDataService(): DnsDataService
    {
        return new DnsDataService($this->dnsBackendProvider(), $this->db, $this->config);
    }

    public function zoneRepository(): ZoneRepositoryInterface
    {
        return $this->repositoryFactory()->createZoneRepository();
    }

    public function domainRepository(): DomainRepositoryInterface
    {
        return $this->repositoryFactory()->createDomainRepository();
    }

    public function recordRepository(): RecordRepositoryInterface
    {
        return $this->repositoryFactory()->createRecordRepository();
    }

    public function userRepository(): UserRepository
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

    public function userGroupRepository(): UserGroupRepositoryInterface
    {
        return new DbUserGroupRepository($this->db);
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
        return DnsServiceFactory::createRecordManager($this->db, $this->config, $this->dnsBackendProvider());
    }

    public function domainManager(): DomainManagerInterface
    {
        return DnsServiceFactory::createDomainManager($this->db, $this->config, $this->dnsBackendProvider());
    }

    public function catalogZoneService(): CatalogZoneService
    {
        return $this->catalogZoneService ??= new CatalogZoneService($this->dnsBackendProvider(), $this->permissionService());
    }

    public function repositoryFactory(?DnsBackendProvider $backendProvider = null): RepositoryFactory
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
