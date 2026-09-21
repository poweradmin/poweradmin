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

use PDO;
use Poweradmin\Infrastructure\Session\ApiStatusService;
use Poweradmin\Application\Service\DnsBackendProviderFactory;
use Poweradmin\Application\Service\DnsDataService;
use Poweradmin\Application\Service\DnssecProviderFactory;
use Poweradmin\Application\Service\PowerdnsStatusService;
use Poweradmin\Application\Service\RepositoryFactory;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneMetadataStoreInterface;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\Consistency\ConsistencyCheckerInterface;
use Poweradmin\Domain\Port\ActorInterface;
use Poweradmin\Domain\Port\DnsBackendProviderInterface;
use Poweradmin\Domain\Service\Dns\SOARecordManager;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Domain\Port\DnssecProviderInterface;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Database\TableNameService;
use Poweradmin\Infrastructure\Repository\ApiZoneMetadataStore;
use Poweradmin\Infrastructure\Repository\DbZoneMetadataStore;
use Poweradmin\Infrastructure\Service\Consistency\ApiConsistencyChecks;
use Poweradmin\Infrastructure\Service\Consistency\SqlConsistencyChecks;
use Poweradmin\Infrastructure\Service\Consistency\ZoneOwnerRepair;
use Poweradmin\Infrastructure\Service\ZoneSyncService;
use Psr\Log\LoggerInterface;

/**
 * The connection to the DNS backend: the provider, its API client, and the
 * repositories and record managers that read and write through it. Every
 * memoized instance is shared for the request so read caches are not split.
 */
class BackendServices
{
    private PDO $db;
    private ConfigurationInterface $config;
    private LoggerInterface $logger;
    private ActorInterface $actor;

    private ?DnsBackendProviderInterface $dnsBackendProvider = null;
    private ?RepositoryFactory $repositoryFactory = null;
    private ?DnsDataService $dnsDataService = null;
    private ?ZoneRepositoryInterface $zoneRepository = null;
    private ?DomainRepositoryInterface $domainRepository = null;
    private ?RecordRepositoryInterface $recordRepository = null;
    private ?SOARecordManagerInterface $soaRecordManager = null;
    private ?DnssecProviderInterface $dnssecProvider = null;
    private ?PowerdnsApiClient $apiClient = null;
    private bool $apiClientResolved = false;
    private ?PowerdnsStatusService $powerdnsStatusService = null;
    private ?ZoneSyncService $zoneSyncService = null;

    public function __construct(PDO $db, ConfigurationInterface $config, LoggerInterface $logger, ActorInterface $actor)
    {
        $this->db = $db;
        $this->config = $config;
        $this->logger = $logger;
        $this->actor = $actor;
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

    public function soaRecordManager(): SOARecordManagerInterface
    {
        return $this->soaRecordManager ??= new SOARecordManager($this->dnsBackendProvider());
    }

    public function dnssecProvider(): DnssecProviderInterface
    {
        return $this->dnssecProvider ??= DnssecProviderFactory::create($this->db, $this->config, $this->actor, $this->apiClient());
    }

    public function dnsDataService(): DnsDataService
    {
        return $this->dnsDataService ??= new DnsDataService($this->repositoryFactory(), $this->dnsBackendProvider(), $this->db, $this->actor);
    }

    public function zoneMetadataStore(): ZoneMetadataStoreInterface
    {
        $apiClient = $this->apiClient();

        return $apiClient === null
            ? new DbZoneMetadataStore($this->db, $this->config)
            : new ApiZoneMetadataStore($apiClient);
    }

    public function consistencyChecker(): ConsistencyCheckerInterface
    {
        $provider = $this->dnsBackendProvider();
        $ownerRepair = new ZoneOwnerRepair($this->db);

        return $provider->isApiBackend()
            ? new ApiConsistencyChecks($this->db, $provider, new ApiStatusService(), $ownerRepair)
            : new SqlConsistencyChecks($this->db, new TableNameService($this->config), $ownerRepair);
    }

    public function powerdnsStatusService(): PowerdnsStatusService
    {
        return $this->powerdnsStatusService ??= new PowerdnsStatusService($this->config, $this->logger);
    }

    /**
     * The forced zone-list sync for API-backed installs; the 300s interval is
     * the same floor the automatic sync uses.
     */
    public function zoneSyncService(): ZoneSyncService
    {
        return $this->zoneSyncService ??= new ZoneSyncService($this->db, $this->dnsBackendProvider(), 300, $this->logger);
    }
}
