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

use Poweradmin\Application\Controller\Api\V2\ServerStatusController;
use Poweradmin\Application\Service\Backend\PowerdnsStatusService;
use Poweradmin\Domain\Model\ApiKeyScope;
use Poweradmin\Domain\Service\Auth\ApiPermissionService;
use Poweradmin\Domain\Service\Dns\SupermasterManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use TestHelpers\FakeConfiguration;

/**
 * Test double that injects mocks and skips the parent constructor (which performs
 * authentication and a real DB connection).
 */
class TestableServerStatusController extends ServerStatusController
{
    /**
     * @phpstan-ignore-next-line constructor.unusedParameter
     */
    public function __construct(array $request = [], array $pathParameters = [])
    {
        $this->request = new Request();
        $this->pathParameters = $pathParameters;
        $this->authenticatedUserId = 7;
        $this->config = new FakeConfiguration();
    }

    public function setApiPermissionService(ApiPermissionService $service): void
    {
        $this->apiPermissionService = $service;
    }

    public function setStatusService(PowerdnsStatusService $service): void
    {
        $this->statusService = $service;
    }

    public function setSupermasterManager(SupermasterManager $manager): void
    {
        $this->supermasterManager = $manager;
    }

    public function setApiKeyScope(ApiKeyScope $scope): void
    {
        $this->apiKeyScope = $scope;
    }

    public function setQuery(array $query): void
    {
        $this->request = new Request($query);
    }

    public function callGetStatus(): JsonResponse
    {
        return $this->getStatus();
    }
}
