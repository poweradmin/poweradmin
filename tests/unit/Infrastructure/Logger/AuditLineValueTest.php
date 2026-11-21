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

namespace Poweradmin\Tests\Unit\Infrastructure\Logger;

use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Logger\AuditLineValue;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use ReflectionClass;

/**
 * Record content and names are written into the name:value audit lines, so a value must not
 * pass for the client_ip, user or operation field that the log filters read.
 */
class AuditLineValueTest extends TestCase
{
    private const PLANTED = 'x client_ip:203.0.113.5 operation:login_failed';

    public function testAColonAfterALetterIsEncodedAndControlCharactersAreReplaced(): void
    {
        $this->assertSame('x client_ip%3A203.0.113.5 operation%3Alogin_failed', AuditLineValue::of(self::PLANTED));
        $this->assertSame('a?b', AuditLineValue::of("a\nb"));
        $this->assertSame('x useŕ%3Aadmin', AuditLineValue::of('x useŕ:admin'));
    }

    public function testColonsAfterDigitsAndNullStayReadable(): void
    {
        $this->assertSame('2001:db8::1', AuditLineValue::of('2001:db8::1'));
        $this->assertSame('', AuditLineValue::of(null));
    }

    public function testInvalidUtf8DoesNotEmptyTheValue(): void
    {
        $this->assertSame("ab\xFF c%3A", AuditLineValue::of("ab\xFF c:"));
    }

    public function testTheLoggerEncodesFieldNamesInAValueWhateverTheCallSite(): void
    {
        $line = 'client_ip:10.0.0.1 user:admin operation:add_zone zone:a.example note:' . self::PLANTED . "\nuser:root";

        $this->assertSame(
            'client_ip:10.0.0.1 user:admin operation:add_zone zone:a.example note:x client_ip%3A203.0.113.5 operation%3Alogin_failed?user%3Aroot',
            $this->protect($line)
        );
    }

    public function testTheLoggerEncodesFieldNamesInAnyCase(): void
    {
        $this->assertSame(
            'client_ip:10.0.0.1 user:admin operation:add_zone note:a User%3Aroot OPERATION%3Ax',
            $this->protect('client_ip:10.0.0.1 user:admin operation:add_zone note:a User:root OPERATION:x')
        );
    }

    public function testALineWithoutTheLeadingFieldsIsUntouched(): void
    {
        $line = 'operation:api_request method:GET path:/x status:200 key_id:1 user:bob client_ip:10.0.0.1';

        $this->assertSame($line, $this->protect($line));
    }

    public function testAPlainLineIsByteIdentical(): void
    {
        $line = 'client_ip:10.0.0.1 user:admin operation:add_record record_type:A record:a.example content:192.0.2.1 ttl:3600 priority:0';

        $this->assertSame($line, $this->protect($line));
    }

    private function protect(string $line): string
    {
        $class = new ReflectionClass(LegacyLogger::class);

        return $class->getMethod('protectFields')->invoke($class->newInstanceWithoutConstructor(), $line);
    }
}
