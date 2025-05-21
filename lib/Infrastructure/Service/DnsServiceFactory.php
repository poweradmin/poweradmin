<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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

use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\DnsRecordValidationService;
use Poweradmin\Domain\Service\DnsRecordValidationServiceInterface;
use Poweradmin\Domain\Service\DnsValidation\DnsCommonValidator;
use Poweradmin\Domain\Service\DnsValidation\DnsValidatorRegistry;
use Poweradmin\Domain\Service\DnsValidation\DNSViolationValidator;
use Poweradmin\Domain\Service\DnsValidation\TTLValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOCommon;
use Poweradmin\Infrastructure\Repository\DbZoneRepository;

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
     * @param PDOCommon $db Database connection
     * @param ConfigurationManager $config Configuration manager
     * @return DnsRecordValidationServiceInterface The DNS record validation service
     */
    public static function createDnsRecordValidationService(
        PDOCommon $db,
        ConfigurationManager $config
    ): DnsRecordValidationServiceInterface {
        $validatorRegistry = new DnsValidatorRegistry($config, $db);
        $ttlValidator = new TTLValidator();
        $dnsCommonValidator = new DnsCommonValidator($db, $config);
        $messageService = new MessageService();
        $zoneRepository = new DbZoneRepository($db, $config);
        $dnsViolationValidator = new DNSViolationValidator($db, $config);

        return new DnsRecordValidationService(
            $validatorRegistry,
            $dnsCommonValidator,
            $ttlValidator,
            $messageService,
            $zoneRepository,
            $dnsViolationValidator
        );
    }
}
