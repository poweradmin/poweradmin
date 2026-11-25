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

namespace Poweradmin\Tests\Unit\Application\Controller;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\DnssecController;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Signing or unsigning moves the SOA serial of a zone Poweradmin owns, not of a replicated one.
 */
class DnssecSerialBumpTest extends TestCase
{
    public function testSerialIsNotBumpedForASlaveZone(): void
    {
        $dnsRecord = $this->createMock(DnsRecord::class);
        $dnsRecord->method('getDomainType')->with(5)->willReturn('SLAVE');
        $dnsRecord->expects($this->never())->method('updateSOASerial');

        $this->controller()->bump($dnsRecord, 5);
    }

    public function testSerialIsBumpedForAMasterZone(): void
    {
        $dnsRecord = $this->createMock(DnsRecord::class);
        $dnsRecord->method('getDomainType')->with(5)->willReturn('MASTER');
        $dnsRecord->expects($this->once())->method('updateSOASerial')->with(5);

        $this->controller()->bump($dnsRecord, 5);
    }

    private function controller(): DnssecSerialBumpProbe
    {
        return new DnssecSerialBumpProbe([], $this->createMock(PDO::class), $this->createMock(ConfigurationManager::class));
    }
}
