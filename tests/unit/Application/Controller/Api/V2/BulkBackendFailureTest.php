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

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\Api\V2\ZonesRecordsBulkController;
use Poweradmin\Domain\Error\ApiErrorException;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Service\ApiPermissionService;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use ReflectionMethod;
use ReflectionProperty;

/**
 * A backend failure inside a bulk batch must surface as an ApiErrorException, so the
 * controller reports the per-operation results instead of a bare server error.
 */
class BulkBackendFailureTest extends TestCase
{
    #[Test]
    public function aFailedDeleteThrowsAnApiErrorExceptionWith500(): void
    {
        $records = $this->createMock(RecordRepositoryInterface::class);
        $records->method('getRecordById')->willReturn(['id' => 5, 'domain_id' => 7, 'name' => 'a.example.com', 'type' => 'A']);
        $permissions = $this->createMock(ApiPermissionService::class);
        $permissions->method('canEditZoneRecord')->willReturn(true);
        $manager = $this->createMock(RecordManagerInterface::class);
        $manager->method('deleteRecord')->willReturn(false);

        $class = ZonesRecordsBulkController::class;
        $controller = $this->getMockBuilder($class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAuthenticatedUserId'])
            ->getMock();
        $controller->method('getAuthenticatedUserId')->willReturn(1);
        foreach (['recordRepository' => $records, 'permissionService' => $permissions, 'recordManager' => $manager] as $property => $value) {
            (new ReflectionProperty($class, $property))->setValue($controller, $value);
        }

        try {
            (new ReflectionMethod($class, 'performDeleteOperation'))->invoke($controller, 7, ['id' => 5], null, 'example.com');
            $this->fail('Expected an ApiErrorException');
        } catch (ApiErrorException $e) {
            $this->assertSame(500, $e->getCode());
            $this->assertSame('Failed to delete record', $e->getMessage());
        }
    }
}
