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
use Poweradmin\Domain\Model\RecordLog;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use ReflectionClass;

/** A record value in an audit line must not pass for another log field. */
class AuditFieldValueEncodingTest extends TestCase
{
    private const PLANTED = 'x client_ip:203.0.113.5 operation:login_failed';

    public function testValueEncodesColonAfterLetterAndControlCharacters(): void
    {
        $this->assertSame('x client_ip%3A203.0.113.5 operation%3Alogin_failed', LegacyLogger::value(self::PLANTED));
        $this->assertSame('a?b', LegacyLogger::value("a\nb"));
        $this->assertSame('2001:db8::1 at 12:30', LegacyLogger::value('2001:db8::1 at 12:30'));
        $this->assertSame('', LegacyLogger::value(null));
    }

    public function testValueEncodesColonAfterNonAsciiLetter(): void
    {
        $this->assertSame('x use' . "\u{0155}" . '%3Aadmin y', LegacyLogger::value('x use' . "\u{0155}" . ':admin y'));
    }

    public function testValueSurvivesInvalidUtf8(): void
    {
        $this->assertSame("\xff" . 'user%3Aadmin', LegacyLogger::value("\xff" . 'user:admin'));
    }

    public function testGuardLineEncodesReservedFieldsAfterTheOperationToken(): void
    {
        $line = 'client_ip:10.0.0.1 user:ann operation:add_record record_type:TXT content:' . self::PLANTED . "\nclient_ip:1.1.1.1";
        $this->assertSame(
            'client_ip:10.0.0.1 user:ann operation:add_record record_type:TXT content:x client_ip%3A203.0.113.5 operation%3Alogin_failed?client_ip%3A1.1.1.1',
            LegacyLogger::guardLine($line)
        );
    }

    public function testGuardLineEncodesReservedNamesInAnyCase(): void
    {
        $this->assertSame(
            'client_ip:1.1.1.1 user:ann operation:x_y key_name:my User%3Aadmin OPERATION%3Ax',
            LegacyLogger::guardLine('client_ip:1.1.1.1 user:ann operation:x_y key_name:my User:admin OPERATION:x')
        );
    }

    public function testGuardLineLeavesOtherMessagesAlone(): void
    {
        $this->assertSame("plain error\nmessage user:x", LegacyLogger::guardLine("plain error\nmessage user:x"));
    }

    public function testEditRecordLineCannotCarryAPlantedField(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        $_SESSION['userlogin'] = 'ann';
        $record = ['type' => 'TXT', 'name' => 'a.example.com', 'content' => self::PLANTED, 'ttl' => 60, 'prio' => 0];

        $reflection = new ReflectionClass(RecordLog::class);
        $log = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('record_prior')->setValue($log, $record);
        $reflection->getProperty('record_after')->setValue($log, $record);

        $message = (string)$reflection->getMethod('buildLogMessage')->invoke($log);

        $this->assertStringNotContainsString(' client_ip:203.0.113.5', $message);
        $this->assertStringNotContainsString(' operation:login_failed', $message);
        $this->assertStringContainsString('client_ip%3A203.0.113.5 operation%3Alogin_failed', $message);
    }
}
