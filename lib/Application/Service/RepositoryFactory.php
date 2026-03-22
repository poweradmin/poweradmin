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
use Poweradmin\Domain\Repository\RecordCommentRepositoryInterface;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;
use Poweradmin\Infrastructure\Repository\ApiDomainRepository;
use Poweradmin\Infrastructure\Repository\ApiRecordRepository;
use Poweradmin\Infrastructure\Repository\ApiZoneRepository;
use Poweradmin\Infrastructure\Repository\ApiRecordCommentRepository;
use Poweradmin\Infrastructure\Repository\DbRecordCommentRepository;
use Poweradmin\Infrastructure\Repository\DbZoneRepository;
use Poweradmin\Infrastructure\Repository\SqlDomainRepository;
use Poweradmin\Infrastructure\Repository\SqlRecordRepository;
use Psr\Log\LoggerInterface;

/**
 * Centralized factory for creating repository instances.
 *
 * Selects between SQL and API backend implementations based on
 * the configured DnsBackendProvider.
 */
class RepositoryFactory
{
    private PDO $db;
    private ConfigurationInterface $config;
    private DnsBackendProvider $backendProvider;
    private ?LoggerInterface $logger;

    public function __construct(
        PDO $db,
        ConfigurationInterface $config,
        DnsBackendProvider $backendProvider,
        ?LoggerInterface $logger = null
    ) {
        $this->db = $db;
        $this->config = $config;
        $this->backendProvider = $backendProvider;
        $this->logger = $logger;
    }

    public function createRecordRepository(): RecordRepositoryInterface
    {
        if ($this->backendProvider->isApiBackend()) {
            return new ApiRecordRepository($this->backendProvider);
        }
        return new SqlRecordRepository($this->db, $this->config);
    }

    public function createZoneRepository(): ZoneRepositoryInterface
    {
        if ($this->backendProvider->isApiBackend()) {
            $dbType = $this->config->get('database', 'type');
            return new ApiZoneRepository($this->db, $this->backendProvider, $dbType);
        }
        return new DbZoneRepository($this->db, $this->config, $this->backendProvider);
    }

    public function createRecordCommentRepository(): RecordCommentRepositoryInterface
    {
        if ($this->backendProvider->isApiBackend()) {
            $apiClient = DnsBackendProviderFactory::createApiClient($this->config, $this->logger);
            if ($apiClient !== null) {
                return new ApiRecordCommentRepository($apiClient, $this->backendProvider);
            }
        }
        return new DbRecordCommentRepository($this->db, $this->config, $this->backendProvider);
    }

    public function createDomainRepository(): DomainRepositoryInterface
    {
        if ($this->backendProvider->isApiBackend()) {
            return new ApiDomainRepository($this->db, $this->config, $this->backendProvider);
        }
        return new SqlDomainRepository($this->db, $this->config);
    }

    public function getBackendProvider(): DnsBackendProvider
    {
        return $this->backendProvider;
    }
}
