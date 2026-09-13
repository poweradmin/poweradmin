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

namespace Poweradmin\Tests\Unit\Domain\Service\Dns;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\Dns\RecordWriteResult;

#[CoversClass(RecordWriteResult::class)]
class RecordWriteResultTest extends TestCase
{
    public function testCreatedCarriesTheRecordId(): void
    {
        $result = RecordWriteResult::created(42);

        $this->assertTrue($result->success);
        $this->assertSame(42, $result->recordId);
        $this->assertNull($result->message);
        $this->assertSame(200, $result->status);
        $this->assertNull($result->field);
    }

    public function testForbiddenIs403WithoutAField(): void
    {
        $result = RecordWriteResult::forbidden('You do not have the permission to add a record to this zone.');

        $this->assertFalse($result->success);
        $this->assertSame(403, $result->status);
        $this->assertNull($result->field);
    }

    public function testExplicitFieldWins(): void
    {
        $result = RecordWriteResult::failure('A record with this hostname, type, and content already exists.', 409, RecordWriteResult::FIELD_DUPLICATE);

        $this->assertSame(409, $result->status);
        $this->assertSame(RecordWriteResult::FIELD_DUPLICATE, $result->field);
    }

    public static function messages(): array
    {
        return [
            ['A record with this hostname, type, and content already exists.', RecordWriteResult::FIELD_DUPLICATE],
            ['Invalid record name.', RecordWriteResult::FIELD_NAME],
            ['This is not a valid IPv4 address.', RecordWriteResult::FIELD_CONTENT],
            ['Invalid hostname in content.', RecordWriteResult::FIELD_CONTENT],
            ['TTL must be a positive number.', RecordWriteResult::FIELD_TTL],
            ['Priority for MX/SRV records must be a number between 0 and 65535.', RecordWriteResult::FIELD_PRIO],
            ['Something unexpected.', RecordWriteResult::FIELD_CONTENT],
        ];
    }

    #[DataProvider('messages')]
    public function testFieldIsGuessedFromTheMessage(string $message, string $expected): void
    {
        $this->assertSame($expected, RecordWriteResult::failure($message)->field);
    }
}
