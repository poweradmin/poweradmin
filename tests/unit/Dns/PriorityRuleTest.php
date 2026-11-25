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

namespace Poweradmin\Tests\Unit\Dns;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\DefaultRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\DnsCommonValidator;
use Poweradmin\Domain\Service\DnsValidation\KXRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\MXRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\SRVRecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use ReflectionMethod;

/**
 * Every 0-65535 priority check applies one rule: trimmed, 1 to 5 digits, no sign, no decimals.
 */
class PriorityRuleTest extends TestCase
{
    public static function priorities(): array
    {
        return [
            'zero' => ['0', 0],
            'max' => ['65535', 65535],
            'trimmed' => [" 25\n", 25],
            'leading zeros within five digits' => ['00025', 25],
            'int' => [300, 300],
            'over' => ['65536', null],
            'six digits' => ['000025', null],
            'negative' => ['-1', null],
            'negative int' => [-1, null],
            'float string' => ['10.0', null],
            'float' => [10.0, null],
            'exponent' => ['1e3', null],
            'hex' => ['0x10', null],
            'text' => ['ten', null],
            'bool' => [true, null],
        ];
    }

    #[DataProvider('priorities')]
    public function testSharedRule(mixed $input, ?int $expected): void
    {
        $this->assertSame($expected, DnsCommonValidator::parsePriority($input));
        $this->assertSame($expected !== null, (new ReflectionMethod(DnsCommonValidator::class, 'isValidPriority'))->invoke($this->common(), $input));
    }

    #[DataProvider('priorities')]
    public function testEveryValidatorAppliesTheSameRule(mixed $input, ?int $expected): void
    {
        $config = $this->createMock(ConfigurationManager::class);
        $validators = [
            new MXRecordValidator($config),
            new KXRecordValidator($config),
            new SRVRecordValidator($config),
        ];
        foreach ($validators as $validator) {
            $method = new ReflectionMethod($validator, 'validatePriority');
            $this->assertSame($expected !== null, $method->invoke($validator, $input)->isValid(), $validator::class);
        }

        $this->assertSame($expected !== null, $this->common()->validatePriority($input, 'MX')->isValid());
        $this->assertSame(
            $expected !== null,
            (new DefaultRecordValidator($config, 'CUSTOM'))->validate('abcd', 'record.example.com', $input, 3600, 86400)->isValid()
        );
    }

    private function common(): DnsCommonValidator
    {
        return (new \ReflectionClass(DnsCommonValidator::class))->newInstanceWithoutConstructor();
    }
}
