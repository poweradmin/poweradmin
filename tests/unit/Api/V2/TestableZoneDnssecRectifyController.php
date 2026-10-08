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

namespace Poweradmin\Tests\Unit\Api\V2;

use Poweradmin\Application\Controller\Api\V2\ZoneDnssecRectifyController;
use Poweradmin\Domain\Port\DnssecProviderInterface;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Auth\ApiPermissionService;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use TestHelpers\FakeConfiguration;

class TestableZoneDnssecRectifyController extends ZoneDnssecRectifyController
{
    public function __construct(
        DomainRepositoryInterface $domainRepository,
        ApiPermissionService $apiPermissionService,
        DnssecProviderInterface $dnssecProvider,
        ?PowerdnsApiClient $apiClient,
        array $pathParameters = ['id' => 1]
    ) {
        $this->request = new Request();
        $this->pathParameters = $pathParameters;
        $this->authenticatedUserId = 1;
        $this->config = new FakeConfiguration();
        $this->domainRepository = $domainRepository;
        $this->apiPermissionService = $apiPermissionService;
        $this->dnssecProvider = $dnssecProvider;
        $this->apiClient = $apiClient;
    }

    public function callRectify(): JsonResponse
    {
        return $this->rectify();
    }
}
