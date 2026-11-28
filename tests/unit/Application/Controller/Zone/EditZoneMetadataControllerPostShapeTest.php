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

namespace Poweradmin\Tests\Unit\Application\Controller\Zone;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use Poweradmin\Application\Controller\RequestHalted;
use Poweradmin\Application\Controller\Zone\EditZoneMetadataController;
use Poweradmin\Domain\Model\ZoneDetail;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Zone\ZoneMetadataResult;
use Poweradmin\Domain\Service\Zone\ZoneMetadataService;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;

/**
 * A metadata post of the wrong shape ends in an error or is skipped row by row, never a crash.
 */
#[CoversClass(EditZoneMetadataController::class)]
class EditZoneMetadataControllerPostShapeTest extends SeamControllerTestCase
{
    /** @var ZoneMetadataService&MockObject */
    private ZoneMetadataService $metadata;

    protected function setUp(): void
    {
        parent::setUp();

        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('canViewZoneMetadata')->willReturn(true);
        $permissions->method('canEditZoneMeta')->willReturn(true);

        $zones = $this->createMock(ZoneRepositoryInterface::class);
        $zones->method('getZone')->willReturn(new ZoneDetail(5, 'example.com', 'example.com', 'MASTER', null, null, 4, '', 0, false, [], []));

        $this->metadata = $this->createMock(ZoneMetadataService::class);

        $this->factory->method('permissionService')->willReturn($permissions);
        $this->factory->method('zoneRepository')->willReturn($zones);
        $this->factory->method('zoneMetadataService')->willReturn($this->metadata);
    }

    private function postMetadata(mixed $metadata): RequestHalted
    {
        $this->post(['metadata' => $metadata]);
        $controller = new EditZoneMetadataController(['id' => '5'] + $this->requestData(), true, $this->environment($this->configure()));

        try {
            $controller->run();
        } catch (RequestHalted $halt) {
            return $halt;
        }

        $this->fail('Expected the request to end early.');
    }

    public function testAScalarMetadataFieldIsRefusedWithoutAWrite(): void
    {
        $this->metadata->expects($this->never())->method('replaceAll');

        $halt = $this->postMetadata('x');

        $this->assertSame(RequestHalted::KIND_ERROR, $halt->kind);
        $this->assertSame('Invalid or unexpected input given.', $halt->target);
    }

    public function testRowsThatAreNotArraysAreSkipped(): void
    {
        $this->metadata->expects($this->once())->method('replaceAll')
            ->with(5, 'example.com', [['kind' => 'API-RECTIFY', 'content' => '1']], $this->anything())
            ->willReturn(ZoneMetadataResult::ok());

        $halt = $this->postMetadata(['junk', ['kind_key' => 'API-RECTIFY', 'content' => '1'], ['kind_key' => ['x'], 'content' => ['y']]]);

        $this->assertSame(RequestHalted::KIND_REDIRECT, $halt->kind);
    }
}
