<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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
use Poweradmin\Domain\Service\DnsValidation\CAARecordValidator;
use Poweradmin\Domain\Service\DnsValidation\DSRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\SOARecordValidator;
use Poweradmin\Domain\Service\DnsValidation\SRVRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\TLSARecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Numeric fields accept plain digits only and are stored as canonical integers.
 */
class NumericFieldCanonicalTest extends TestCase
{
    private ConfigurationManager $config;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigurationManager::class);
        $this->config->method('get')->willReturn('example.com');
    }

    private function soa(string $content)
    {
        $v = new SOARecordValidator($this->config);
        $v->setSOAParams('hostmaster@example.com', 'example.com');
        return $v->validate($content, 'example.com', 0, 3600, 86400);
    }

    public static function badSoa(): array
    {
        return [
            'serial exponent' => ['ns1.example.com hostmaster.example.com 1e3 7200 1800 1209600 86400'],
            'serial decimal' => ['ns1.example.com hostmaster.example.com 0.5 7200 1800 1209600 86400'],
            'serial plus' => ['ns1.example.com hostmaster.example.com +5 7200 1800 1209600 86400'],
            'refresh too big' => ['ns1.example.com hostmaster.example.com 1 99999999999 1800 1209600 86400'],
            'retry exponent' => ['ns1.example.com hostmaster.example.com 1 7200 1e3 1209600 86400'],
            'expire negative decimal' => ['ns1.example.com hostmaster.example.com 1 7200 1800 -0.5 86400'],
            'minimum inf' => ['ns1.example.com hostmaster.example.com 1 7200 1800 1209600 1e1000'],
        ];
    }

    #[DataProvider('badSoa')]
    public function testSoaRefusesNonDigitOrOversizedNumbers(string $content): void
    {
        $this->assertFalse($this->soa($content)->isValid());
    }

    public function testSoaStoresCanonicalIntegers(): void
    {
        $result = $this->soa('ns1.example.com hostmaster.example.com 0002023122801 007200 1800 1209600 86400');
        $this->assertTrue($result->isValid());
        $this->assertSame('ns1.example.com hostmaster.example.com 2023122801 7200 1800 1209600 86400', $result->getData()['content']);
    }

    public function testSoaKeepsValuesAboveTheSignedIntRangeAsGiven(): void
    {
        $result = $this->soa('ns1.example.com hostmaster.example.com 4000000000 7200 1800 1209600 0000');
        $this->assertTrue($result->isValid());
        $this->assertSame('ns1.example.com hostmaster.example.com 4000000000 7200 1800 1209600 0', $result->getData()['content']);
    }

    public function testSoaAcceptsTheUpperBound(): void
    {
        $this->assertTrue($this->soa('ns1.example.com hostmaster.example.com 4294967295 7200 1800 1209600 4294967295')->isValid());
        $this->assertFalse($this->soa('ns1.example.com hostmaster.example.com 4294967296 7200 1800 1209600 86400')->isValid());
    }

    public function testSrvRefusesNonDigitFieldsAndStoresCanonical(): void
    {
        $v = new SRVRecordValidator($this->config);
        $this->assertFalse($v->validate('1e1 5060 sip.example.com', '_sip._tcp.example.com', 10, 3600, 86400)->isValid());
        $this->assertFalse($v->validate('10 0.5 sip.example.com', '_sip._tcp.example.com', 10, 3600, 86400)->isValid());
        $this->assertFalse($v->validate('10 5060 sip.example.com', '_sip._tcp.example.com', '1e1', 3600, 86400)->isValid());
        $result = $v->validate('010 05060 sip.example.com', '_sip._tcp.example.com', '010', 3600, 86400);
        $this->assertTrue($result->isValid());
        $this->assertSame('10 5060 sip.example.com', $result->getData()['content']);
        $this->assertSame(10, $result->getData()['prio']);
    }

    public function testCaaRefusesNonDigitFlagsAndStoresCanonical(): void
    {
        $v = new CAARecordValidator($this->config);
        $this->assertFalse($v->validate('1e0 issue "ca.example.org"', 'example.com', 0, 3600, 86400)->isValid());
        $this->assertFalse($v->validate('0.5 issue "ca.example.org"', 'example.com', 0, 3600, 86400)->isValid());
        $result = $v->validate('0128 issue "ca.example.org"', 'example.com', 0, 3600, 86400);
        $this->assertTrue($result->isValid());
        $this->assertSame('128 issue "ca.example.org"', $result->getData()['content']);
    }

    public function testTlsaRefusesNonDigitFieldsAndStoresCanonical(): void
    {
        $v = new TLSARecordValidator($this->config);
        $hash = 'a0b9b16969687adf0323d15048fb4fa4c354c4e01594e8956522cfe3566cae74';
        $this->assertFalse($v->validate("3e0 1 1 $hash", '_443._tcp.example.com', 0, 3600, 86400)->isValid());
        $this->assertFalse($v->validate("3 1.0 1 $hash", '_443._tcp.example.com', 0, 3600, 86400)->isValid());
        $result = $v->validate("03 01 01 $hash", '_443._tcp.example.com', 0, 3600, 86400);
        $this->assertTrue($result->isValid());
        $this->assertSame("3 1 1 $hash", $result->getData()['content']);
    }

    public function testDsRefusesNonDigitFieldsAndStoresCanonical(): void
    {
        $v = new DSRecordValidator($this->config);
        $digest = '348dedbedc0cddcc4f2605ba42d428223672e5e913762c68f29d8547baa680c0';
        $result = $v->validate("045342 013 02 $digest", 'example.com', 0, 3600, 86400);
        $this->assertTrue($result->isValid());
        $this->assertSame("45342 13 2 $digest", $result->getData()['content']);
        $this->assertFalse($v->validate("45342 13 2e0 $digest", 'example.com', 0, 3600, 86400)->isValid());
    }
}
