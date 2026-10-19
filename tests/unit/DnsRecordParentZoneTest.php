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


namespace unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\AppConfiguration;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Infrastructure\Database\PDOLayer;

/**
 * A new zone may not be created inside an enclosing zone owned by another user,
 * unless the operator turns dns_parent_zone_ownership_check off.
 */
class DnsRecordParentZoneTest extends TestCase
{
    public function testParentNamesAreListedClosestFirst(): void
    {
        $this->assertSame(
            ['b.example.com', 'example.com', 'com'],
            DnsRecord::get_parent_zone_names('a.b.example.com')
        );
    }

    public function testParentNamesIgnoreCaseAndTrailingDot(): void
    {
        $this->assertSame(
            ['example.com', 'com'],
            DnsRecord::get_parent_zone_names('Sub.Example.COM.')
        );
    }

    public function testSingleLabelHasNoParents(): void
    {
        $this->assertSame([], DnsRecord::get_parent_zone_names('com'));
    }

    public function testCheckIsSkippedWhenTurnedOff(): void
    {
        $db = $this->createMock(PDOLayer::class);
        $db->expects($this->never())->method('prepare');
        $db->expects($this->never())->method('queryOne');

        $config = $this->createMock(AppConfiguration::class);
        $config->method('get')->willReturnCallback(
            fn(string $name, mixed $default = null): mixed => $name === 'dns_parent_zone_ownership_check' ? false : $default
        );

        $dnsRecord = new DnsRecord($db, $config);

        $this->assertFalse($dnsRecord->is_subzone_of_foreign_zone('sub.example.com'));
    }
}
