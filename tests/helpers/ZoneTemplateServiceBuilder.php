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

namespace TestHelpers;

use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Port\DnsBackendProviderInterface;
use Poweradmin\Domain\Repository\ZoneTemplateRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Auth\UserContextService;
use Poweradmin\Domain\Service\Template\ZoneTemplateAccessPolicy;
use Poweradmin\Domain\Service\Template\ZoneTemplateRecordService;
use Poweradmin\Domain\Service\Template\ZoneTemplateService;
use Poweradmin\Domain\Service\Template\ZoneTemplateWriteService;
use Psr\Log\LoggerInterface;

/**
 * Wires the zone template facade the way ZoneServices does, from the raw
 * collaborators, so tests build it in one call.
 */
final class ZoneTemplateServiceBuilder
{
    public static function build(
        ZoneTemplateRepositoryInterface $repository,
        ConfigurationInterface $config,
        DnsBackendProviderInterface $backend,
        PermissionService $permissionService,
        UserContextService $userContext,
        LoggerInterface $logger
    ): ZoneTemplateService {
        $access = new ZoneTemplateAccessPolicy($repository, $permissionService, $userContext);

        return new ZoneTemplateService(
            $repository,
            $access,
            new ZoneTemplateWriteService($repository, $access, $config, $logger),
            new ZoneTemplateRecordService($repository, $access, $config, $backend)
        );
    }
}
