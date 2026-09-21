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
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\Api\PublicApiController;
use Poweradmin\Domain\Service\UserContextService;
use RuntimeException;

/**
 * The API principal lives in process-wide state, so a persistent worker must not
 * carry one request's user into the next whatever way the handler ended.
 */
#[CoversClass(PublicApiController::class)]
class PublicApiControllerHandleTest extends TestCase
{
    protected function tearDown(): void
    {
        UserContextService::clearApiUserContext();
        parent::tearDown();
    }

    private function controller(callable $handler): PublicApiController
    {
        return new class ($handler) extends PublicApiController {
            /** @var callable */
            private $handler;

            // Skip the bootstrap (config, DB, API key auth).
            public function __construct(callable $handler)
            {
                $this->handler = $handler;
            }

            public function run(): void
            {
                ($this->handler)();
            }

            public function other(): void
            {
                ($this->handler)('other');
            }
        };
    }

    public function testHandleClearsTheApiUserAfterAHandlerThatThrows(): void
    {
        UserContextService::setApiUserContext(42, 'apikey');
        $controller = $this->controller(function (): void {
            throw new RuntimeException('handler failed');
        });

        try {
            PublicApiController::handle(fn(): object => $controller, 'run');
            $this->fail('the handler exception must propagate');
        } catch (RuntimeException $e) {
            $this->assertSame('handler failed', $e->getMessage());
        }

        $context = new UserContextService();
        $this->assertNull($context->getLoggedInUserId());
        $this->assertNull($context->getLoggedInUsername());
    }

    public function testHandleClearsTheApiUserAfterASuccessfulHandler(): void
    {
        $seenDuringRun = null;
        $controller = $this->controller(function () use (&$seenDuringRun): void {
            $seenDuringRun = (new UserContextService())->getLoggedInUserId();
        });

        PublicApiController::handle(function () use ($controller): object {
            // Authentication in the real constructor sets the principal.
            UserContextService::setApiUserContext(42, 'apikey');
            return $controller;
        }, 'run');

        $this->assertSame(42, $seenDuringRun, 'the handler itself still sees the principal');
        $this->assertNull((new UserContextService())->getLoggedInUserId());
    }

    public function testHandleClearsTheApiUserWhenConstructionFailsAfterAuthentication(): void
    {
        try {
            PublicApiController::handle(function (): object {
                UserContextService::setApiUserContext(42, 'apikey');
                throw new RuntimeException('scope check failed');
            }, 'run');
            $this->fail('the constructor exception must propagate');
        } catch (RuntimeException $e) {
            $this->assertSame('scope check failed', $e->getMessage());
        }

        $this->assertNull((new UserContextService())->getLoggedInUserId());
    }

    public function testHandleStartsEveryRequestWithoutAPrincipalLeftByAPreviousOne(): void
    {
        // A handler that exits skips finally, so the next request must clear on entry.
        UserContextService::setApiUserContext(7, 'stale');
        $seenDuringRun = 'unset';
        $controller = $this->controller(function () use (&$seenDuringRun): void {
            $seenDuringRun = (new UserContextService())->getLoggedInUserId();
        });

        PublicApiController::handle(fn(): object => $controller, 'run');

        $this->assertNull($seenDuringRun);
    }

    public function testHandleRunsTheRoutedMethod(): void
    {
        $called = null;
        $controller = $this->controller(function (string $name = 'run') use (&$called): void {
            $called = $name;
        });

        PublicApiController::handle(fn(): object => $controller, 'other');

        $this->assertSame('other', $called);
    }
}
