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

namespace Poweradmin\Tests\Unit\Module\SecondaryZoneImport;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Http\Request;
use Poweradmin\Domain\Service\Dns\DomainManagerInterface;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Module\SecondaryZoneImport\Controller\SecondaryZoneImportController;
use ReflectionClass;
use ReflectionMethod;

/**
 * Converting an imported secondary zone to a primary is creating a primary zone,
 * so it needs the same create grant as the zone editor's type change.
 */
class SecondaryZoneImportConvertTest extends TestCase
{
    protected function tearDown(): void
    {
        $_POST = [];
    }

    public function testConvertIsRefusedWithoutTheCreateGrant(): void
    {
        $domainManager = $this->createMock(DomainManagerInterface::class);
        $domainManager->expects($this->never())->method('changeZoneType');

        $error = null;
        $this->convert(false, $domainManager, $error);

        $this->assertSame('You do not have permission to change this zone to that type.', $error);
    }

    public function testConvertProceedsWithTheCreateGrant(): void
    {
        $domainManager = $this->createMock(DomainManagerInterface::class);
        $domainManager->expects($this->once())->method('changeZoneType')->with('NATIVE', 7)
            ->willThrowException(new \RuntimeException('reached'));

        $this->expectExceptionMessage('reached');
        $error = null;
        $this->convert(true, $domainManager, $error);
    }

    private function convert(bool $mayCreate, DomainManagerInterface $domainManager, ?string &$error): void
    {
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('canCreateZone')->with(5, 'NATIVE')->willReturn($mayCreate);

        $backend = $this->createMock(DnsBackendProvider::class);
        $backend->method('countZoneRecords')->willReturn(3);

        $controller = $this->getMockBuilder(SecondaryZoneImportController::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['hasPermission', 'createPermissionService', 'createDnsBackendProvider', 'createDomainManager', 'getCurrentUserId', 'showError'])
            ->getMock();
        $controller->method('hasPermission')->willReturn(true);
        $controller->method('createPermissionService')->willReturn($permissions);
        $controller->method('createDnsBackendProvider')->willReturn($backend);
        $controller->method('createDomainManager')->willReturn($domainManager);
        $controller->method('getCurrentUserId')->willReturn(5);
        $controller->method('showError')->willReturnCallback(function (string $message) use (&$error): void {
            $error = $message;
        });

        $_POST = ['zone_id' => '7'];
        (new ReflectionClass(SecondaryZoneImportController::class))->getProperty('request')->setValue($controller, new Request());

        (new ReflectionMethod($controller, 'handleConvert'))->invoke($controller);
    }
}
