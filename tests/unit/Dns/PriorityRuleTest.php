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
namespace unit\Dns;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\DnsCommonValidator;
use Poweradmin\Domain\Service\DnsValidation\HostnamePolicy;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Domain\Service\DnsValidation\KXRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\MXRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\NIDRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\SRVRecordValidator;
use Poweradmin\Domain\Port\RecordReadBackendInterface;

/**
 * Every priority check applies one rule: trim, then 1 to 5 digits, at most 65535.
 */
class PriorityRuleTest extends TestCase
{
    /**
     * @return array<string, array{mixed, int|null}>
     */
    public static function priorityProvider(): array
    {
        return [
            'int' => [10, 10],
            'zero' => [0, 0],
            'digits' => ['10', 10],
            'padded zeros' => ['00010', 10],
            'blanks' => [' 10 ', 10],
            'max' => ['65535', 65535],
            'over' => ['65536', null],
            'int over' => [70000, null],
            'negative int' => [-1, null],
            'negative' => ['-1', null],
            'float' => [10.0, null],
            'decimal' => ['10.0', null],
            'exponent' => ['1e1', null],
            'sign' => ['+10', null],
            'six digits' => ['000010', null],
            'bool' => [true, null],
        ];
    }

    #[DataProvider('priorityProvider')]
    public function testSharedRule(mixed $prio, ?int $expected): void
    {
        $this->assertSame($expected, DnsCommonValidator::parsePriority($prio));
        $this->assertSame($expected !== null, (new DnsCommonValidator($this->createMock(RecordReadBackendInterface::class)))->isValidPriority($prio));
    }

    public function testAnEmptyPriorityIsNoValueForTheSharedRule(): void
    {
        // Validators treat an empty priority as "use the default" before the shared rule runs
        $this->assertNull(DnsCommonValidator::parsePriority(''));
    }

    #[DataProvider('priorityProvider')]
    public function testEveryValidatorAgrees(mixed $prio, ?int $expected): void
    {
        $hostnames = new HostnameValidator(new HostnamePolicy(true, true));
        $cases = [
            'MX' => [new MXRecordValidator($hostnames), 'mail.example.com'],
            'SRV' => [new SRVRecordValidator($hostnames), '5 5060 sip.example.com'],
            'KX' => [new KXRecordValidator($hostnames), 'kx.example.com'],
        ];
        foreach ($cases as $type => [$validator, $content]) {
            $name = $type === 'SRV' ? '_sip._tcp.example.com' : 'example.com';
            $result = $validator->validate($content, $name, $prio, 3600, 3600);

            $this->assertSame($expected !== null, $result->isValid(), $type . ' ' . var_export($prio, true));
            if ($expected !== null) {
                $this->assertSame($expected, $result->getData()['prio'], $type);
            }
        }

        // NID reads its preference from the content, never from the prio argument
        if (is_string($prio) || is_int($prio)) {
            $result = (new NIDRecordValidator())->validate($prio . ' 0014:4fff:ff20:ee64', 'example.com', 0, 3600, 3600);
            $this->assertSame($expected !== null, $result->isValid(), 'NID ' . var_export($prio, true));
        }
    }
}
