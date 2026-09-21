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
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\Dns\RecordWriteResult;
use Poweradmin\Domain\Service\Validation\RecordField;

#[CoversClass(RecordWriteResult::class)]
class RecordWriteResultTest extends TestCase
{
    public function testOkCarriesTheRecordId(): void
    {
        $result = RecordWriteResult::ok(42);

        $this->assertTrue($result->success);
        $this->assertSame(42, $result->recordId);
        $this->assertNull($result->message);
        $this->assertSame(200, $result->status);
        $this->assertNull($result->field);
    }

    public function testRefusalsCarryAStatusAndNoField(): void
    {
        $forbidden = RecordWriteResult::forbidden('You do not have the permission to add a record to this zone.');
        $missing = RecordWriteResult::notFound('Record not found.');
        $backend = RecordWriteResult::backendFailure('Failed to add record to DNS backend.');

        foreach ([[$forbidden, 403], [$missing, 404], [$backend, 500]] as [$result, $status]) {
            $this->assertFalse($result->success);
            $this->assertSame($status, $result->status);
            $this->assertNull($result->field);
        }
    }

    public function testFailureCarriesTheFieldTheValidatorNamed(): void
    {
        $result = RecordWriteResult::failure('A record with this hostname, type, and content already exists.', 409, RecordField::DUPLICATE);

        $this->assertSame(409, $result->status);
        $this->assertSame(RecordField::DUPLICATE, $result->field);
    }

    public function testFailureWithoutANamedFieldDoesNotGuessOne(): void
    {
        $this->assertNull(RecordWriteResult::failure('TTL must be a positive number.')->field);
    }
}
