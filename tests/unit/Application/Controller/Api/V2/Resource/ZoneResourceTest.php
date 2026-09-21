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
use Poweradmin\Application\Controller\Api\V2\Resource\ZoneResource;

class ZoneResourceTest extends TestCase
{
    public function testTheSummaryCarriesTheCanonicalIdAndNoMastersOrAccount(): void
    {
        $this->assertSame(
            ['id' => 1, 'canonical_id' => 9, 'name' => 'a.example.com', 'type' => 'SLAVE', 'created_at' => '2026-01-01 00:00:00'],
            ZoneResource::summary(['id' => '1', 'canonical_id' => '9', 'name' => 'a.example.com', 'type' => 'SLAVE', 'created_at' => '2026-01-01 00:00:00', 'master' => '192.0.2.1', 'account' => 'x'])
        );
    }

    public function testASummaryWithoutTypeCanonicalIdOrCreatedAtTakesTheDefaults(): void
    {
        $this->assertSame(
            ['id' => 2, 'canonical_id' => 2, 'name' => 'b.example.com', 'type' => 'MASTER', 'created_at' => null],
            ZoneResource::summary(['id' => 2, 'name' => 'b.example.com'])
        );
    }

    public function testTheDetailCarriesMastersAccountAndDescription(): void
    {
        $this->assertSame([
            'id' => 81,
            'name' => 'example.com',
            'type' => 'SLAVE',
            'masters' => '192.0.2.1,192.0.2.2',
            'account' => 'team-dns',
            'description' => 'the corporate zone',
            'created_at' => '2026-02-02 10:00:00',
        ], ZoneResource::detail([
            'id' => '81',
            'name' => 'example.com',
            'type' => 'SLAVE',
            'account' => 'team-dns',
            'master' => '192.0.2.1,192.0.2.2',
            'created_at' => '2026-02-02 10:00:00',
        ], 'the corporate zone'));
    }

    public function testEmptyDetailAttributesReadAsNull(): void
    {
        $detail = ZoneResource::detail(['id' => 81, 'name' => 'example.com', 'account' => '', 'master' => ''], '');

        $this->assertSame('MASTER', $detail['type']);
        $this->assertNull($detail['masters']);
        $this->assertNull($detail['account']);
        $this->assertNull($detail['description']);
        $this->assertNull($detail['created_at']);

        $this->assertNull(ZoneResource::detail(['id' => 81, 'name' => 'example.com'], null)['description']);
    }
}
