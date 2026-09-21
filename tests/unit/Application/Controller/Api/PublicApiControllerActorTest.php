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

namespace Poweradmin\Tests\Unit\Application\Controller\Api;

use PHPUnit\Framework\Attributes\CoversClass;
use Poweradmin\Application\Controller\Api\PublicApiController;
use Poweradmin\Application\Service\Auth\ApiKeyActor;
use Poweradmin\Application\Service\Auth\ApiKeyAuthenticationMiddleware;
use Poweradmin\Application\Service\ControllerServiceFactory;
use Poweradmin\Domain\Model\ApiKeyScope;
use Poweradmin\Domain\Port\ActorInterface;
use Poweradmin\Domain\Repository\UserRepositoryInterface;
use Poweradmin\Tests\Unit\Application\Controller\Api\V2\V2ControllerTestCase;
use Symfony\Component\HttpFoundation\Request;
use TestHelpers\FakeConfiguration;

/**
 * A request authenticated by an API key acts as the key owner: the controller
 * rebinds the request's services to that user, with the username the change
 * log and audit lines will show.
 */
#[CoversClass(PublicApiController::class)]
class PublicApiControllerActorTest extends V2ControllerTestCase
{
    private const OWNER_ID = 42;

    public function testAKeyAuthenticatedRequestActsAsTheKeyOwner(): void
    {
        $factory = $this->createMock(ControllerServiceFactory::class);
        $factory->method('apiKeyAuthenticationMiddleware')->willReturn($this->middlewareFor(self::OWNER_ID));
        $factory->method('userRepository')->willReturn($this->users(['id' => self::OWNER_ID, 'username' => 'apikey-owner']));
        $factory->expects($this->once())->method('bindActor')->with($this->callback(
            fn(ActorInterface $actor): bool => $actor instanceof ApiKeyActor
                && $actor->userId() === self::OWNER_ID
                && $actor->username() === 'apikey-owner'
        ));

        $this->controller($factory)->authenticate();
    }

    public function testAKeyWhoseOwnerRowIsGoneStillNamesTheUserId(): void
    {
        $factory = $this->createMock(ControllerServiceFactory::class);
        $factory->method('apiKeyAuthenticationMiddleware')->willReturn($this->middlewareFor(self::OWNER_ID));
        $factory->method('userRepository')->willReturn($this->users(null));
        $factory->expects($this->once())->method('bindActor')->with($this->callback(
            fn(ActorInterface $actor): bool => $actor->userId() === self::OWNER_ID && $actor->username() === 'user_id:42'
        ));

        $this->controller($factory)->authenticate();
    }

    private function middlewareFor(int $userId): ApiKeyAuthenticationMiddleware
    {
        $middleware = $this->createMock(ApiKeyAuthenticationMiddleware::class);
        $middleware->method('process')->willReturn(true);
        $middleware->method('getAuthenticatedUserId')->willReturn($userId);
        $middleware->method('getApiKeyScope')->willReturn(ApiKeyScope::unrestricted());

        return $middleware;
    }

    /**
     * @param array<string, mixed>|null $row
     */
    private function users(?array $row): UserRepositoryInterface
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('getUserById')->willReturn($row);

        return $users;
    }

    private function controller(ControllerServiceFactory $factory): object
    {
        $controller = new class extends PublicApiController {
            // Skip the bootstrap (config, DB, API key auth).
            public function __construct()
            {
            }

            public function run(): void
            {
            }

            public function authenticate(): void
            {
                $this->authenticateApiRequest();
            }
        };

        $request = Request::create('/api/v2/zones', 'GET', [], [], [], ['HTTP_X_API_KEY' => 'pwa_test']);
        $this->inject($controller, 'request', $request);
        $this->inject($controller, 'config', new FakeConfiguration(['api' => ['enabled' => true]]));
        $this->inject($controller, 'serviceFactory', $factory);

        return $controller;
    }
}
