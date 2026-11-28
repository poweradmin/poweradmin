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

namespace Poweradmin\Tests\Unit\Application\Controller\Api\V2;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\Api\V2\ZonesRecordsBulkController;
use Poweradmin\Domain\Error\ApiErrorException;
use Poweradmin\Domain\Service\DnsBackendProvider;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * In API backend mode a failed backend write must be an ApiErrorException so the bulk
 * endpoint reports per-operation results; SQL mode keeps the plain exception.
 */
class ZonesRecordsBulkBackendFailureTest extends TestCase
{
    private function failure(bool $apiBackend): \Throwable
    {
        $backend = $this->createMock(DnsBackendProvider::class);
        $backend->method('isApiBackend')->willReturn($apiBackend);

        $controller = (new ReflectionClass(ZonesRecordsBulkController::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(ZonesRecordsBulkController::class, 'backendProvider'))->setValue($controller, $backend);

        return (new ReflectionMethod(ZonesRecordsBulkController::class, 'backendFailure'))
            ->invoke($controller, 'Failed to update record');
    }

    public function testApiBackendFailureIsAnApiErrorException(): void
    {
        $e = $this->failure(true);

        $this->assertInstanceOf(ApiErrorException::class, $e);
        $this->assertSame(500, $e->getCode());
        $this->assertSame('Failed to update record', $e->getMessage());
    }

    public function testSqlBackendFailureStaysAPlainException(): void
    {
        $e = $this->failure(false);

        $this->assertNotInstanceOf(ApiErrorException::class, $e);
        $this->assertSame('Failed to update record', $e->getMessage());
    }
}
