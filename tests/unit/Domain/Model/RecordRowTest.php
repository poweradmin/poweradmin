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
 *
 */

namespace Poweradmin\Tests\Unit\Domain\Model;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\RecordRow;

/**
 * The read model exists so a consumer does not have to know which backend
 * produced the row, so the two backend shapes are what it is pinned against.
 */
#[CoversClass(RecordRow::class)]
class RecordRowTest extends TestCase
{
    public function testTheSqlRowShapeIsRead(): void
    {
        $row = RecordRow::fromRow([
            'id' => '12',
            'domain_id' => '3',
            'name' => 'www.example.com',
            'type' => 'A',
            'content' => '192.0.2.1',
            'ttl' => '3600',
            'prio' => '0',
            'disabled' => true,
            'auth' => false,
            'ordername' => 'www',
            'change_date' => '1700000000',
            'comment' => 'web',
            'comment_account' => 'ops',
            'comment_modified_at' => '1700000001',
        ]);

        $this->assertSame(12, $row->id);
        $this->assertSame(3, $row->domainId);
        $this->assertTrue($row->disabled);
        $this->assertFalse($row->auth);
        $this->assertSame('web', $row->comment);
        $this->assertSame(1700000001, $row->commentModifiedAt);
        $this->assertSame('www', $row->ordername);
    }

    public function testTheApiRowShapeIsReadIntoTheSameProperties(): void
    {
        $row = RecordRow::fromRow([
            'id' => 'd3d3LmV4YW1wbGUuY29tOkE6MTkyLjAuMi4x',
            'domain_id' => 3,
            'name' => 'www.example.com',
            'type' => 'A',
            'content' => '192.0.2.1',
            'ttl' => 3600,
            'prio' => 0,
            'disabled' => 0,
            'api_comment' => 'web',
            'api_comment_account' => 'ops',
            'api_comment_modified_at' => 1700000001,
            'modified_at' => 1700000002,
        ]);

        $this->assertSame('d3d3LmV4YW1wbGUuY29tOkE6MTkyLjAuMi4x', $row->id, 'An encoded id must not be cast to an int');
        $this->assertFalse($row->disabled, 'The API sends 0 and 1 where SQL sends bools');
        $this->assertSame('web', $row->comment, 'The API comment naming maps onto the same property');
        $this->assertSame(1700000002, $row->modifiedAt);
        $this->assertNull($row->auth, 'auth is a SQL-only column');
    }

    /**
     * The SQL listing selects NULL AS comment while the API listing simply had
     * no such key, so a consumer saw a different shape per backend.
     */
    public function testAMissingCommentIsNullRatherThanAbsent(): void
    {
        $row = RecordRow::fromRow(['id' => 1, 'name' => 'example.com', 'type' => 'SOA', 'content' => 'ns1 hostmaster 1']);

        $this->assertArrayHasKey('comment', $row->toArray());
        $this->assertNull($row['comment']);
    }

    public function testItReadsAsTheColumnKeyedRowTemplatesAndExportersExpect(): void
    {
        $row = RecordRow::fromRow([
            'id' => 7,
            'domain_id' => 2,
            'name' => 'mail.example.com',
            'type' => 'MX',
            'content' => 'mx.example.com',
            'ttl' => 300,
            'prio' => 10,
            'disabled' => false,
        ]);

        $this->assertSame('mail.example.com', $row['name']);
        $this->assertSame(10, $row['prio']);
        $this->assertTrue(isset($row['type']));
        $this->assertFalse(isset($row['nope']));
        $this->assertNull($row['nope']);
        $this->assertSame($row->toArray(), $row->jsonSerialize());
        $this->assertStringContainsString('"name":"mail.example.com"', (string) json_encode($row));
    }

    public function testBackendOnlyColumnsAppearOnlyWhenTheRowCarriedThem(): void
    {
        $api = RecordRow::fromRow(['id' => 1, 'name' => 'a', 'type' => 'A', 'content' => '192.0.2.1']);

        $this->assertArrayNotHasKey('auth', $api->toArray());
        $this->assertArrayNotHasKey('ordername', $api->toArray());
        $this->assertArrayNotHasKey('change_date', $api->toArray());
    }

    public function testItRefusesToBeWrittenTo(): void
    {
        $row = RecordRow::fromRow(['id' => 1, 'name' => 'a', 'type' => 'A', 'content' => '192.0.2.1']);

        $this->expectException(LogicException::class);
        $row['name'] = 'changed';
    }
}
