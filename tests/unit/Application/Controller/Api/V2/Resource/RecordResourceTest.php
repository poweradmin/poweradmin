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

namespace Poweradmin\Tests\Unit\Application\Controller\Api\V2\Resource;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\Api\V2\Resource\RecordResource;
use Poweradmin\Domain\Utility\RecordIdHelper;

class RecordResourceTest extends TestCase
{
    /** @var array<string, mixed> */
    private const ROW = [
        'id' => '7',
        'domain_id' => 81,
        'name' => 'www.example.com',
        'type' => 'TXT',
        'content' => '"hello"',
        'ttl' => '300',
        'prio' => '5',
        'disabled' => 1,
        'auth' => 0,
    ];

    public function testAnItemCarriesTheRelativeNameWithTheZoneIdSecond(): void
    {
        $this->assertSame([
            'id' => 7,
            'zone_id' => 81,
            'name' => 'www',
            'type' => 'TXT',
            'content' => 'hello',
            'ttl' => 300,
            'priority' => 5,
            'disabled' => true,
            'auth' => false,
        ], RecordResource::item(self::ROW, 81, 'example.com', RecordIdHelper::normalizeId(...)));
    }

    public function testAListItemKeepsTheFullyQualifiedNameAndHasNoZoneId(): void
    {
        $this->assertSame([
            'id' => 7,
            'name' => 'www.example.com',
            'type' => 'TXT',
            'content' => 'hello',
            'ttl' => 300,
            'priority' => 5,
            'disabled' => true,
            'auth' => false,
        ], RecordResource::listItem(self::ROW, RecordIdHelper::normalizeId(...)));
    }

    public function testTheApexReadsAsAtAndMissingFlagsTakeTheirDefaults(): void
    {
        $item = RecordResource::item(
            ['id' => 3, 'name' => 'example.com', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 60],
            81,
            'example.com',
            RecordIdHelper::normalizeId(...)
        );

        $this->assertSame('@', $item['name']);
        $this->assertSame(0, $item['priority']);
        $this->assertFalse($item['disabled']);
        $this->assertTrue($item['auth']);
    }

    public function testARowWithoutAnIdReportsNull(): void
    {
        $item = RecordResource::item(
            ['name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 60],
            81,
            'example.com',
            RecordIdHelper::normalizeId(...)
        );

        $this->assertNull($item['id']);
    }

    public function testTheIdFormatterDecidesTheIdType(): void
    {
        $item = RecordResource::listItem(['id' => 'pdns:abc', 'name' => 'a', 'type' => 'A', 'content' => '', 'ttl' => 1], fn($id) => 'x-' . $id);

        $this->assertSame('x-pdns:abc', $item['id']);
    }

    public function testAnRRSetMemberHasContentPriorityAndDisabledOnly(): void
    {
        $this->assertSame(
            ['content' => 'hello', 'priority' => 5, 'disabled' => true],
            RecordResource::rrsetMember(self::ROW)
        );
        $this->assertSame(
            ['content' => '', 'priority' => 0, 'disabled' => false],
            RecordResource::rrsetMember([])
        );
    }

    public function testOnlySingleStringTxtContentIsUnquoted(): void
    {
        $this->assertSame('hello', RecordResource::stripTxtQuotes(' "hello" ', 'TXT'));
        $this->assertSame('"part1" "part2"', RecordResource::stripTxtQuotes('"part1" "part2"', 'TXT'));
        $this->assertSame('"', RecordResource::stripTxtQuotes('"', 'TXT'));
        $this->assertSame('"quoted"', RecordResource::stripTxtQuotes('"quoted"', 'A'));
    }
}
