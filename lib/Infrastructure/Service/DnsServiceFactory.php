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

namespace Poweradmin\Infrastructure\Service;

use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\DnsRecordValidationService;
use Poweradmin\Domain\Service\DnsRecordValidationServiceInterface;
use Poweradmin\Domain\Service\Dns\DomainManager;
use Poweradmin\Domain\Service\Dns\DomainManagerInterface;
use Poweradmin\Domain\Service\Dns\RecordManager;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\Dns\SOARecordManager;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Domain\Service\Dns\SupermasterManager;
use Poweradmin\Domain\Service\Dns\SupermasterManagerInterface;
use Poweradmin\Domain\Service\DnsValidation\DnsCommonValidator;
use Poweradmin\Domain\Service\DnsValidation\DnsValidatorRegistry;
use Poweradmin\Domain\Service\DnsValidation\DNSViolationValidator;
use Poweradmin\Domain\Service\DnsValidation\TTLValidator;
use Poweradmin\Application\Service\DnsBackendProviderFactory;
use Poweradmin\Application\Service\RepositoryFactory;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use PDO;

/**
 * Factory for DNS services
 *
 * Creates and configures DNS-related services with proper dependencies
 *
 * @package Poweradmin
 * @copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright 2010-2025 Poweradmin Development Team
 * @license https://opensource.org/licenses/GPL-3.0 GPL
 */
class DnsServiceFactory
{
    /**
     * Create DnsRecordValidationService instance with all dependencies
     *
     * @param PDO $db Database connection
     * @param ConfigurationManager $config Configuration manager
     * @return DnsRecordValidationServiceInterface The DNS record validation service
     */
    public static function createDnsRecordValidationService(
        PDO $db,
        ConfigurationManager $config,
        ?DnsBackendProvider $backendProvider = null
    ): DnsRecordValidationServiceInterface {
        $backendProvider = $backendProvider ?? DnsBackendProviderFactory::create($db, $config);
        $repositoryFactory = new RepositoryFactory($db, $config, $backendProvider);
        $validatorRegistry = new DnsValidatorRegistry($config, $db, $backendProvider);
        $ttlValidator = new TTLValidator();
        $dnsCommonValidator = new DnsCommonValidator($db, $config, $backendProvider);
        $messageService = new MessageService();
        $zoneRepository = $repositoryFactory->createZoneRepository();
        $dnsViolationValidator = new DNSViolationValidator($repositoryFactory->createRecordRepository());

        return new DnsRecordValidationService(
            $validatorRegistry,
            $dnsCommonValidator,
            $ttlValidator,
            $messageService,
            $zoneRepository,
            $dnsViolationValidator
        );
    }

    /**
     * Create SOARecordManager instance with all dependencies
     */
    public static function createSOARecordManager(
        PDO $db,
        ConfigurationManager $config,
        ?DnsBackendProvider $backendProvider = null
    ): SOARecordManagerInterface {
        $backendProvider = $backendProvider ?? DnsBackendProviderFactory::create($db, $config);
        return new SOARecordManager($db, $config, $backendProvider);
    }

    /**
     * Build the domain repository the record and domain managers depend on.
     * Repository construction stays owned by RepositoryFactory.
     */
    private static function createDomainRepository(
        PDO $db,
        ConfigurationManager $config,
        ?DnsBackendProvider $backendProvider = null
    ): DomainRepositoryInterface {
        $backendProvider = $backendProvider ?? DnsBackendProviderFactory::create($db, $config);
        return (new RepositoryFactory($db, $config, $backendProvider))->createDomainRepository();
    }

    /**
     * Create RecordManager instance with all dependencies
     */
    public static function createRecordManager(
        PDO $db,
        ConfigurationManager $config,
        ?DnsBackendProvider $backendProvider = null
    ): RecordManagerInterface {
        $backendProvider = $backendProvider ?? DnsBackendProviderFactory::create($db, $config);
        return new RecordManager(
            $db,
            $config,
            self::createDnsRecordValidationService($db, $config, $backendProvider),
            self::createSOARecordManager($db, $config, $backendProvider),
            self::createDomainRepository($db, $config, $backendProvider),
            $backendProvider
        );
    }

    /**
     * Create DomainManager instance with all dependencies
     */
    public static function createDomainManager(
        PDO $db,
        ConfigurationManager $config,
        ?DnsBackendProvider $backendProvider = null
    ): DomainManagerInterface {
        $backendProvider = $backendProvider ?? DnsBackendProviderFactory::create($db, $config);
        return new DomainManager(
            $db,
            $config,
            self::createSOARecordManager($db, $config, $backendProvider),
            self::createDomainRepository($db, $config, $backendProvider),
            $backendProvider
        );
    }

    /**
     * Create SupermasterManager instance with all dependencies
     */
    public static function createSupermasterManager(
        PDO $db,
        ConfigurationManager $config,
        ?DnsBackendProvider $backendProvider = null
    ): SupermasterManagerInterface {
        $backendProvider = $backendProvider ?? DnsBackendProviderFactory::create($db, $config);
        return new SupermasterManager($db, $config, $backendProvider);
    }
}
