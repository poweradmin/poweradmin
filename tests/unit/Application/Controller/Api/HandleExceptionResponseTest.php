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

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\Api\PublicApiController;
use Poweradmin\BaseController;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * handleException() must log the exception's details but never put them in the
 * client response: a database fault's SQL text or file paths in a 500 body is
 * an information leak.
 */
class HandleExceptionResponseTest extends TestCase
{
    public function testClientResponseCarriesOnlyTheContextualMessage(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')
            ->with(
                $this->anything(),
                $this->callback(fn(array $ctx) => $ctx['message'] === 'SQLSTATE[42S02]: secret table missing')
            );

        $controller = (new ReflectionClass(TestableHandleExceptionController::class))
            ->newInstanceWithoutConstructor();
        $loggerProperty = (new ReflectionClass(BaseController::class))->getProperty('logger');
        $loggerProperty->setAccessible(true);
        $loggerProperty->setValue($controller, $logger);

        $method = (new ReflectionClass(PublicApiController::class))->getMethod('handleException');
        $method->setAccessible(true);
        /** @var JsonResponse $response */
        $response = $method->invoke(
            $controller,
            new RuntimeException('SQLSTATE[42S02]: secret table missing'),
            'ZonesController::listZones',
            'Failed to list zones'
        );

        $this->assertSame(500, $response->getStatusCode());
        $body = json_decode((string)$response->getContent(), true);
        $this->assertFalse($body['success']);
        $this->assertSame('Failed to list zones', $body['message']);
        $this->assertStringNotContainsString('SQLSTATE', (string)$response->getContent());
    }
}
