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
use Poweradmin\Domain\Service\DnsValidation\HostnamePolicy;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Domain\Service\DnsValidation\L32RecordValidator;
use Poweradmin\Domain\Service\DnsValidation\L64RecordValidator;
use Poweradmin\Domain\Service\DnsValidation\LPRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\SVCBRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\URIRecordValidator;

/**
 * The priority column is 16 bits wide; validators that do not use it for
 * their own semantics must still refuse values outside 0..65535.
 */
class RecordPriorityBoundsTest extends TestCase
{
    /** @return array<string, array{0: DnsRecordValidatorInterface, 1: string}> */
    public static function validatorProvider(): array
    {
        $hostnames = new HostnameValidator(new HostnamePolicy(true, true));

        return [
            'default' => [new DefaultRecordValidator(), 'abcd'],
            'uri' => [new URIRecordValidator($hostnames), '10 1 "https://example.com/"'],
            'lp' => [new LPRecordValidator($hostnames), '10 target.example.com.'],
            'svcb' => [new SVCBRecordValidator($hostnames), '1 svc.example.com. alpn=h2'],
            'l32' => [new L32RecordValidator($hostnames), '10 192.0.2.1'],
            'l64' => [new L64RecordValidator($hostnames), '10 2001:0db8:1140:1000'],
        ];
    }

    #[DataProvider('validatorProvider')]
    public function testRefusesOutOfRangePriority(DnsRecordValidatorInterface $validator, string $content): void
    {
        foreach (['99999999999', 65536, -1, '-5', 'abc', '1e3', '1.5'] as $prio) {
            $result = $validator->validate($content, 'host.example.com', $prio, 3600, 86400);
            $this->assertFalse($result->isValid(), 'priority ' . var_export($prio, true) . ' must be refused');
        }
    }

    #[DataProvider('validatorProvider')]
    public function testAcceptsPriorityBounds(DnsRecordValidatorInterface $validator, string $content): void
    {
        foreach ([0 => '0', 65535 => '65535', 20 => 20] as $expected => $prio) {
            $result = $validator->validate($content, 'host.example.com', $prio, 3600, 86400);
            $this->assertTrue($result->isValid(), 'priority ' . $prio . ' must be accepted');
            $this->assertSame($expected, $result->getData()['prio']);
        }
    }
}
