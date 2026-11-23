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

namespace Poweradmin\Tests\Unit\Application\Service\Auth;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\Auth\ApiKeyAuthenticationMiddleware;
use Poweradmin\Domain\Service\Auth\ApiKeyService;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpFoundation\Request;
use TestHelpers\FakeConfiguration;

class ApiKeyAuthenticationMiddlewareDeprecationTest extends TestCase
{
    private const KEY = 'sup3r-secret-key-value';

    private object $logger;

    protected function setUp(): void
    {
        unset($_SERVER['HTTP_X_API_KEY']);
        $this->logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->lines[] = $level . ' ' . $message . ' ' . json_encode($context);
            }
        };
    }

    private function middleware(): ApiKeyAuthenticationMiddleware
    {
        $service = $this->createMock(ApiKeyService::class);
        $service->method('authenticate')->willReturn(true);
        $service->method('getUserIdFromApiKey')->willReturn(7);

        return new ApiKeyAuthenticationMiddleware($service, new FakeConfiguration(['api' => ['enabled' => true]]), $this->logger);
    }

    public function testQueryParameterStillAuthenticatesAndWarnsOncePerRequest(): void
    {
        $middleware = $this->middleware();
        $request = new Request(['api_key' => self::KEY]);

        $this->assertTrue($middleware->process($request));
        $this->assertSame(7, $middleware->getAuthenticatedUserId($request));

        $this->assertCount(1, $this->logger->lines);
        $this->assertStringStartsWith('warning ', $this->logger->lines[0]);
        $this->assertStringContainsString('deprecated', $this->logger->lines[0]);
        $this->assertStringNotContainsString(self::KEY, $this->logger->lines[0]);
    }

    public function testHeaderKeyDoesNotWarn(): void
    {
        $request = new Request();
        $request->headers->set('X-API-Key', self::KEY);

        $this->assertTrue($this->middleware()->process($request));
        $this->assertSame([], $this->logger->lines);
    }

    public function testAnArrayQueryValueIsNoKeyAndDoesNotThrow(): void
    {
        $middleware = $this->middleware();
        $request = new Request(['api_key' => ['a', 'b']]);

        $this->assertFalse($middleware->process($request));
        $this->assertSame([], $this->logger->lines);
    }
}
