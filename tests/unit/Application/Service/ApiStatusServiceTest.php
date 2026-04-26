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

namespace Poweradmin\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\ApiStatusService;

class ApiStatusServiceTest extends TestCase
{
    protected function setUp(): void
    {
        // Session is used as the backing store; clear between tests.
        $_SESSION = [];
    }

    public function testGetLastErrorReturnsNullWhenNoneRecorded(): void
    {
        $this->assertNull((new ApiStatusService())->getLastError());
    }

    public function testRecordErrorStoresMessageContextAndTimestamp(): void
    {
        $service = new ApiStatusService();
        $service->recordError('boom', ['endpoint' => 'zones', 'http_code' => 500]);

        $last = $service->getLastError();
        $this->assertIsArray($last);
        $this->assertSame('boom', $last['message']);
        $this->assertSame(['endpoint' => 'zones', 'http_code' => 500], $last['context']);
        $this->assertIsInt($last['timestamp']);
        $this->assertLessThanOrEqual(time(), $last['timestamp']);
    }

    public function testRecordErrorOverwritesPrevious(): void
    {
        $service = new ApiStatusService();
        $service->recordError('first', ['endpoint' => 'zones']);
        $service->recordError('second', ['endpoint' => 'servers']);

        $last = $service->getLastError();
        $this->assertSame('second', $last['message']);
        $this->assertSame(['endpoint' => 'servers'], $last['context']);
    }

    public function testClearErrorRemovesStoredError(): void
    {
        $service = new ApiStatusService();
        $service->recordError('boom');
        $this->assertNotNull($service->getLastError());

        $service->clearError();
        $this->assertNull($service->getLastError());
    }

    public function testClearErrorIsSafeWhenNothingStored(): void
    {
        $service = new ApiStatusService();
        $service->clearError();
        $this->assertNull($service->getLastError());
    }
}
