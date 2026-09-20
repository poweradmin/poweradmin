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

use Poweradmin\Domain\Service\DnsRecordValidationService;
use Poweradmin\Domain\Service\DnsRecordValidationServiceInterface;
use Poweradmin\Domain\Service\Dns\DomainManager;
use Poweradmin\Domain\Service\Dns\DomainManagerInterface;
use Poweradmin\Domain\Service\Dns\RecordManager;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\Dns\SOARecordManager;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Domain\Service\Dns\SupermasterManager;
use Poweradmin\Domain\Service\DnsValidation\DnsCommonValidator;
use Poweradmin\Domain\Service\DnsValidation\DnsValidatorRegistry;
use Poweradmin\Domain\Service\DnsValidation\DNSViolationValidator;
use Poweradmin\Application\Service\DnsBackendProviderFactory;
use Poweradmin\Application\Service\DnssecProviderFactory;
use Poweradmin\Application\Service\RepositoryFactory;
use Poweradmin\Domain\Service\DnsBackendProviderInterface;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use PDO;

/**
 * Builds the DNS manager services with their backend provider and repositories.
 *
 * Creates and configures DNS-related services with proper dependencies
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
        ?DnsBackendProviderInterface $backendProvider = null
    ): DnsRecordValidationServiceInterface {
        $backendProvider = $backendProvider ?? DnsBackendProviderFactory::create($db, $config);
        $repositoryFactory = new RepositoryFactory($db, $config, $backendProvider);
        $validatorRegistry = new DnsValidatorRegistry($config, $backendProvider);
        $dnsCommonValidator = new DnsCommonValidator($backendProvider);
        $dnsViolationValidator = new DNSViolationValidator($repositoryFactory->createRecordRepository());

        return new DnsRecordValidationService(
            $validatorRegistry,
            $dnsCommonValidator,
            $repositoryFactory->createDomainRepository(),
            $dnsViolationValidator
        );
    }

    /**
     * Create SOARecordManager instance with all dependencies
     */
    public static function createSOARecordManager(
        PDO $db,
        ConfigurationManager $config,
        ?DnsBackendProviderInterface $backendProvider = null
    ): SOARecordManagerInterface {
        $backendProvider = $backendProvider ?? DnsBackendProviderFactory::create($db, $config);
        return new SOARecordManager($db, $config, $backendProvider);
    }

    /**
     * Create RecordManager instance with all dependencies
     */
    public static function createRecordManager(
        PDO $db,
        ConfigurationManager $config,
        ?DnsBackendProviderInterface $backendProvider = null
    ): RecordManagerInterface {
        $backendProvider = $backendProvider ?? DnsBackendProviderFactory::create($db, $config);
        $repositoryFactory = new RepositoryFactory($db, $config, $backendProvider);
        return new RecordManager(
            $db,
            $config,
            self::createDnsRecordValidationService($db, $config, $backendProvider),
            self::createSOARecordManager($db, $config, $backendProvider),
            $repositoryFactory->createDomainRepository(),
            $repositoryFactory,
            fn() => DnssecProviderFactory::create($db, $config, DnsBackendProviderFactory::apiClientFrom($backendProvider)),
            $backendProvider
        );
    }

    /**
     * Create DomainManager instance with all dependencies
     */
    public static function createDomainManager(
        PDO $db,
        ConfigurationManager $config,
        ?DnsBackendProviderInterface $backendProvider = null
    ): DomainManagerInterface {
        $backendProvider = $backendProvider ?? DnsBackendProviderFactory::create($db, $config);
        $repositoryFactory = new RepositoryFactory($db, $config, $backendProvider);
        return new DomainManager(
            $db,
            $config,
            self::createSOARecordManager($db, $config, $backendProvider),
            $repositoryFactory->createDomainRepository(),
            $repositoryFactory,
            $backendProvider
        );
    }

    /**
     * Create SupermasterManager instance with all dependencies
     */
    public static function createSupermasterManager(
        PDO $db,
        ConfigurationManager $config,
        ?DnsBackendProviderInterface $backendProvider = null
    ): SupermasterManager {
        $backendProvider = $backendProvider ?? DnsBackendProviderFactory::create($db, $config);
        return new SupermasterManager($db, $config, $backendProvider);
    }
}
