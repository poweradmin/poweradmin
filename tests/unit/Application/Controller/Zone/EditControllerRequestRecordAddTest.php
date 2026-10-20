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

use PHPUnit\Framework\MockObject\MockObject;
use Poweradmin\Application\Controller\RequestHalted;
use Poweradmin\Application\Controller\Zone\EditController;
use Poweradmin\Domain\Service\Zone\ZoneChangeRequestResult;
use Poweradmin\Domain\Service\Zone\ZoneChangeRequestService;
use Poweradmin\Domain\Service\Auth\SessionKeys;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;
use ReflectionMethod;

/**
 * The add card on the zone page in change-request mode: an IP address typed
 * as the name of a reverse-zone record is filed as its PTR name.
 */
class EditControllerRequestRecordAddTest extends SeamControllerTestCase
{
    private const ZONE_ID = 12;
    private const ZONE = '8.b.d.0.1.0.0.2.ip6.arpa';

    /** @var ZoneChangeRequestService&MockObject */
    private ZoneChangeRequestService $changeRequests;

    protected function setUp(): void
    {
        parent::setUp();

        $this->changeRequests = $this->createMock(ZoneChangeRequestService::class);
        $this->factory->method('zoneChangeRequestService')->willReturn($this->changeRequests);
    }

    private function requestRecordAdd(string $name): bool
    {
        $this->post(['name' => $name, 'type' => 'PTR', 'content' => 'host.example.com', 'ttl' => '300']);
        $controller = new EditController($this->requestData(), true, $this->environment($this->configure()));

        return (new ReflectionMethod(EditController::class, 'requestRecordAdd'))->invoke($controller, self::ZONE_ID, self::ZONE);
    }

    public function testAnAddressNameIsFiledAsItsNibbleName(): void
    {
        $filed = null;
        $this->changeRequests->expects($this->once())->method('fileRecordAdd')
            ->willReturnCallback(function (int $zoneId, string $zoneName, array $record) use (&$filed): ZoneChangeRequestResult {
                $filed = $record;
                return ZoneChangeRequestResult::ok(9, 'filed');
            });

        try {
            $this->requestRecordAdd('2001:db8::1');
            $this->fail('Expected the filed request to redirect.');
        } catch (RequestHalted $halt) {
            $this->assertSame('/zones/12/edit', $halt->target);
        }

        $this->assertSame('1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.' . self::ZONE, $filed['name']);
    }

    public function testAnAddressOutsideTheZoneFilesNothing(): void
    {
        $this->changeRequests->expects($this->never())->method('fileRecordAdd');

        $this->assertFalse($this->requestRecordAdd('2001:db9::1'));
        $this->assertSame(
            ['error' => true, 'errorMessage' => 'The address 2001:db9::1 is outside the zone ' . self::ZONE . '.', 'fieldError' => 'name'],
            $this->session->get(SessionKeys::ADD_RECORD_ERROR)
        );
    }
}
