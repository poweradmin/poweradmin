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
use Poweradmin\Domain\Service\DnsValidation\DnsRecordValidatorInterface;
use Poweradmin\Domain\Service\DnsValidation\L32RecordValidator;
use Poweradmin\Domain\Service\DnsValidation\L64RecordValidator;
use Poweradmin\Domain\Service\DnsValidation\LPRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\SVCBRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\URIRecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Validators that take the priority from the request refuse values outside 0..65535.
 */
class RecordPriorityRangeTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function validatorProvider(): array
    {
        return [
            'default' => [DefaultRecordValidator::class, 'some content'],
            'uri' => [URIRecordValidator::class, '10 1 "https://example.com/"'],
            'lp' => [LPRecordValidator::class, '10 host.example.com'],
            'svcb' => [SVCBRecordValidator::class, '1 svc.example.com'],
            'l32' => [L32RecordValidator::class, '10 192.0.2.1'],
            'l64' => [L64RecordValidator::class, '10 2001:0db8:1140:1000'],
        ];
    }

    private function make(string $class): DnsRecordValidatorInterface
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturn('example.com');

        return new $class($config);
    }

    #[DataProvider('validatorProvider')]
    public function testAcceptsBoundaryPriorities(string $class, string $content): void
    {
        foreach ([0, 65535, '0', '65535', '', null, ' 5 '] as $prio) {
            $result = $this->make($class)->validate($content, 'host.example.com', $prio, 3600, 86400);
            $this->assertTrue($result->isValid(), $class . ' rejected ' . var_export($prio, true));
        }
    }

    #[DataProvider('validatorProvider')]
    public function testRefusesPriorityOutsideRange(string $class, string $content): void
    {
        foreach ([65536, 99999999999, -1, '-1', '99999999999', '1e3', '5x', '   ', 10.0, 1.5] as $prio) {
            $result = $this->make($class)->validate($content, 'host.example.com', $prio, 3600, 86400);
            $this->assertFalse($result->isValid(), $class . ' accepted ' . var_export($prio, true));
        }
    }
}
