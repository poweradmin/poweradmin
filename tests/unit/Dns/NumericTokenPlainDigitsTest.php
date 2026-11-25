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
use Poweradmin\Domain\Service\DnsValidation\HostnamePolicy;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Domain\Service\DnsValidation\IPSECKEYRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\KXRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\L32RecordValidator;
use Poweradmin\Domain\Service\DnsValidation\L64RecordValidator;
use Poweradmin\Domain\Service\DnsValidation\LPRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\NAPTRRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\NSEC3PARAMRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\NSEC3RecordValidator;
use Poweradmin\Domain\Service\DnsValidation\RKEYRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\RRSIGRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\SMIMEARecordValidator;
use Poweradmin\Domain\Service\DnsValidation\SSHFPRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\TKEYRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\TSIGRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\ZONEMDRecordValidator;

/**
 * Numeric tokens must be plain digits (PHP's is_numeric also takes 1e0, 1.0, +1, leading
 * blanks) and are stored canonical, so PowerDNS can parse what is saved.
 */
class NumericTokenPlainDigitsTest extends TestCase
{
    private const HEX40 = 'a0b9b16969687adf0323d15048fb4fa4c354c4e0';
    private const HEX64 = 'a0b9b16969687adf0323d15048fb4fa4c354c4e01594e8956522cfe3566cae74';
    private const HEX96 = 'a0b9b16969687adf0323d15048fb4fa4c354c4e01594e8956522cfe3566cae74a0b9b16969687adf0323d15048fb4fa4c354c4e0';
    private const KEY = 'AQNRU3mG7TVTO2BkR47usntb102uFJtugbo6BSGvgqt4AQ==';
    private const SIG = 'AQNRU3mG7TVTO2BkR47usntb102uFJtugbo6BSGvgqt4AQ==';

    private function validatorFor(string $type): object
    {
        $hostnames = new HostnameValidator(new HostnamePolicy(true, true));
        $class = 'Poweradmin\\Domain\\Service\\DnsValidation\\' . $type . 'RecordValidator';

        return new $class($hostnames);
    }

    /**
     * @return array<string, array{string, string, string, string}> type, name, input, canonical
     */
    public static function canonicalProvider(): array
    {
        $rrsigTail = ' 20260101000000 20251201000000 ';
        return [
            'SSHFP plain' => ['SSHFP', 'host.example.com', '1 2 ' . self::HEX64, '1 2 ' . self::HEX64],
            'SSHFP padded' => ['SSHFP', 'host.example.com', '04 01 ' . self::HEX40, '4 1 ' . self::HEX40],
            'SMIMEA plain' => ['SMIMEA', 'host.example.com', '3 1 1 ' . self::HEX64, '3 1 1 ' . self::HEX64],
            'SMIMEA padded' => ['SMIMEA', 'host.example.com', '03 01 01 ' . self::HEX64, '3 1 1 ' . self::HEX64],
            'NAPTR plain' => ['NAPTR', 'host.example.com', '100 10 "u" "E2U+sip" "!^.*$!sip:info@example.com!" .', '100 10 "u" "E2U+sip" "!^.*$!sip:info@example.com!" .'],
            'NAPTR padded' => ['NAPTR', 'host.example.com', '0100 010 "u" "E2U+sip" "!^.*$!sip:info@example.com!" .', '100 10 "u" "E2U+sip" "!^.*$!sip:info@example.com!" .'],
            'ZONEMD plain' => ['ZONEMD', 'example.com', '4294967295 1 1 ' . self::HEX96, '4294967295 1 1 ' . self::HEX96],
            'ZONEMD padded' => ['ZONEMD', 'example.com', '002021121600 01 02 ' . self::HEX96, '2021121600 1 2 ' . self::HEX96],
            'ZONEMD padded private scheme' => ['ZONEMD', 'example.com', '2021121600 0241 001 ' . self::HEX96, '2021121600 241 1 ' . self::HEX96],
            'IPSECKEY padded all' => ['IPSECKEY', 'host.example.com', '010 01 02 192.0.2.1 ' . self::KEY, '10 1 2 192.0.2.1 ' . self::KEY],
            'L32 plain' => ['L32', 'host.example.com', '10 192.0.2.1', '10 192.0.2.1'],
            'L32 padded' => ['L32', 'host.example.com', '010 192.0.2.1', '10 192.0.2.1'],
            'L64 plain' => ['L64', 'host.example.com', '10 2001:0db8:1140:1000', '10 2001:0db8:1140:1000'],
            'L64 padded' => ['L64', 'host.example.com', '0010 2001:0db8:1140:1000', '10 2001:0db8:1140:1000'],
            'LP plain' => ['LP', 'host.example.com', '10 example.com', '10 example.com'],
            'LP padded' => ['LP', 'host.example.com', '010 example.com', '10 example.com'],
            'NSEC3PARAM plain' => ['NSEC3PARAM', 'example.com', '1 0 0 -', '1 0 0 -'],
            'NSEC3PARAM padded' => ['NSEC3PARAM', 'example.com', '01 00 0010 AABBCC', '1 0 10 AABBCC'],
            'IPSECKEY plain' => ['IPSECKEY', 'host.example.com', '10 1 2 192.0.2.1 ' . self::KEY, '10 1 2 192.0.2.1 ' . self::KEY],
            'IPSECKEY padded' => ['IPSECKEY', 'host.example.com', '010 1 2 192.0.2.1 ' . self::KEY, '10 1 2 192.0.2.1 ' . self::KEY],
            'RKEY plain' => ['RKEY', 'example.com', '256 3 8 ' . self::KEY, '256 3 8 ' . self::KEY],
            'RKEY padded' => ['RKEY', 'example.com', '0256 03 08 ' . self::KEY, '256 3 8 ' . self::KEY],
            'RRSIG plain' => ['RRSIG', 'example.com', 'A 8 2 3600' . $rrsigTail . '12345 example.com. ' . self::SIG, 'A 8 2 3600' . $rrsigTail . '12345 example.com. ' . self::SIG],
            'RRSIG padded' => ['RRSIG', 'example.com', 'A 08 02 03600' . $rrsigTail . '012345 example.com. ' . self::SIG, 'A 8 2 3600' . $rrsigTail . '12345 example.com. ' . self::SIG],
            'NSEC3 plain' => ['NSEC3', 'hash.example.com', '1 0 10 AB12CD 01234ABCDEF A NS SOA', '1 0 10 AB12CD 01234ABCDEF A NS SOA'],
            'NSEC3 padded' => ['NSEC3', 'hash.example.com', '01 00 010 AB12CD 01234ABCDEF A NS SOA', '1 0 10 AB12CD 01234ABCDEF A NS SOA'],
            'TSIG plain' => ['TSIG', 'key.example.com', 'hmac-sha256. 1609459200 300 MTIzNDU2Nzg5MGFiY2RlZg== 12345 0 0', 'hmac-sha256. 1609459200 300 MTIzNDU2Nzg5MGFiY2RlZg== 12345 0 0'],
            'TSIG padded' => ['TSIG', 'key.example.com', 'hmac-sha256. 01609459200 0300 MTIzNDU2Nzg5MGFiY2RlZg== 012345 00 00', 'hmac-sha256. 1609459200 300 MTIzNDU2Nzg5MGFiY2RlZg== 12345 0 0'],
            'TKEY plain' => ['TKEY', 'key.example.com', 'gss-tsig. 1700000000 1700003600 3 0 ' . self::KEY, 'gss-tsig. 1700000000 1700003600 3 0 ' . self::KEY],
            'TKEY padded' => ['TKEY', 'key.example.com', 'gss-tsig. 1700000000 1700003600 03 00 ' . self::KEY, 'gss-tsig. 1700000000 1700003600 3 0 ' . self::KEY],
        ];
    }

    #[DataProvider('canonicalProvider')]
    public function testValidContentIsAcceptedAndStoredCanonical(string $type, string $name, string $input, string $expected): void
    {
        $result = $this->validatorFor($type)->validate($input, $name, 0, 3600, 3600);

        $this->assertTrue($result->isValid(), $type . ': ' . implode('; ', $result->getErrors()));
        $this->assertSame($expected, $result->getData()['content']);
    }

    /**
     * @return array<string, array{string, string, string}> type, name, content
     */
    public static function refusedProvider(): array
    {
        $rrsigTail = ' 20260101000000 20251201000000 ';
        $cases = [
            'SSHFP exponent' => ['SSHFP', '1e0 1 ' . self::HEX40],
            'SSHFP decimal' => ['SSHFP', '1 1.0 ' . self::HEX40],
            'SSHFP sign' => ['SSHFP', '+1 1 ' . self::HEX40],
            'SMIMEA exponent' => ['SMIMEA', '3 1e0 1 ' . self::HEX64],
            'SMIMEA decimal' => ['SMIMEA', '3.0 1 1 ' . self::HEX64],
            'SMIMEA sign' => ['SMIMEA', '3 1 +1 ' . self::HEX64],
            'NAPTR exponent' => ['NAPTR', '1e1 10 "u" "E2U+sip" "" example.com.'],
            'NAPTR decimal' => ['NAPTR', '10 1.0 "u" "E2U+sip" "" example.com.'],
            'NAPTR sign' => ['NAPTR', '+10 10 "u" "E2U+sip" "" example.com.'],
            'ZONEMD exponent' => ['ZONEMD', '1e1 1 1 ' . self::HEX96],
            'ZONEMD decimal' => ['ZONEMD', '2021121600 1.0 1 ' . self::HEX96],
            'ZONEMD sign' => ['ZONEMD', '2021121600 1 +1 ' . self::HEX96],
            'ZONEMD serial over 32 bit' => ['ZONEMD', '4294967296 1 1 ' . self::HEX96],
            'ZONEMD serial huge' => ['ZONEMD', '99999999999999999999 1 1 ' . self::HEX96],
            'L32 exponent' => ['L32', '1e1 192.0.2.1'],
            'L32 decimal' => ['L32', '10.0 192.0.2.1'],
            'L32 sign' => ['L32', '+10 192.0.2.1'],
            'L64 exponent' => ['L64', '1e1 2001:0db8:1140:1000'],
            'L64 decimal' => ['L64', '10.0 2001:0db8:1140:1000'],
            'LP exponent' => ['LP', '1e1 example.com.'],
            'LP decimal' => ['LP', '10.0 example.com.'],
            'NSEC3PARAM algorithm exponent' => ['NSEC3PARAM', '1e0 0 0 -'],
            'NSEC3PARAM flags decimal' => ['NSEC3PARAM', '1 0.0 0 -'],
            'NSEC3PARAM flags text' => ['NSEC3PARAM', '1 abc 0 -'],
            'NSEC3PARAM iterations exponent' => ['NSEC3PARAM', '1 0 1e1 -'],
            'NSEC3PARAM iterations sign' => ['NSEC3PARAM', '1 0 +1 -'],
            'IPSECKEY exponent' => ['IPSECKEY', '1e1 1 2 192.0.2.1 ' . self::KEY],
            'IPSECKEY decimal' => ['IPSECKEY', '10.0 1 2 192.0.2.1 ' . self::KEY],
            'IPSECKEY sign' => ['IPSECKEY', '+10 1 2 192.0.2.1 ' . self::KEY],
            'ZONEMD padded reserved scheme' => ['ZONEMD', '2021121600 00 1 ' . self::HEX96],
            'ZONEMD padded reserved hash' => ['ZONEMD', '2021121600 1 00 ' . self::HEX96],
            'ZONEMD reserved scheme' => ['ZONEMD', '2021121600 0 1 ' . self::HEX96],
            'IPSECKEY gateway type exponent' => ['IPSECKEY', '10 1e0 2 192.0.2.1 ' . self::KEY],
            'IPSECKEY gateway type decimal' => ['IPSECKEY', '10 2.0 2 192.0.2.1 ' . self::KEY],
            'IPSECKEY gateway type sign' => ['IPSECKEY', '10 +1 2 192.0.2.1 ' . self::KEY],
            'IPSECKEY algorithm exponent' => ['IPSECKEY', '10 1 1e0 192.0.2.1 ' . self::KEY],
            'IPSECKEY algorithm decimal' => ['IPSECKEY', '10 1 2.0 192.0.2.1 ' . self::KEY],
            'RKEY flags exponent' => ['RKEY', '2.56e2 3 8 ' . self::KEY],
            'RKEY protocol decimal' => ['RKEY', '256 3.0 8 ' . self::KEY],
            'RKEY algorithm exponent' => ['RKEY', '256 3 1e1 ' . self::KEY],
            'RKEY sign' => ['RKEY', '+256 3 8 ' . self::KEY],
            'RRSIG algorithm exponent' => ['RRSIG', 'A 1e1 2 3600' . $rrsigTail . '12345 example.com. ' . self::SIG],
            'RRSIG labels decimal' => ['RRSIG', 'A 8 2.0 3600' . $rrsigTail . '12345 example.com. ' . self::SIG],
            'RRSIG ttl exponent' => ['RRSIG', 'A 8 2 3.6e3' . $rrsigTail . '12345 example.com. ' . self::SIG],
            'RRSIG key tag exponent' => ['RRSIG', 'A 8 2 3600' . $rrsigTail . '1e4 example.com. ' . self::SIG],
            'NSEC3 algorithm exponent' => ['NSEC3', '1e0 0 10 AB12CD 01234ABCDEF A NS'],
            'NSEC3 flags text' => ['NSEC3', '1 abc 10 AB12CD 01234ABCDEF A NS'],
            'NSEC3 iterations exponent' => ['NSEC3', '1 0 1e1 AB12CD 01234ABCDEF A NS'],
            'NSEC3 iterations sign' => ['NSEC3', '1 0 +1 AB12CD 01234ABCDEF A NS'],
            'TSIG timestamp exponent' => ['TSIG', 'hmac-sha256. 1.6e9 300 MTIzNDU2Nzg5MGFiY2RlZg== 12345 0 0'],
            'TSIG fudge exponent' => ['TSIG', 'hmac-sha256. 1609459200 3e2 MTIzNDU2Nzg5MGFiY2RlZg== 12345 0 0'],
            'TSIG original id decimal' => ['TSIG', 'hmac-sha256. 1609459200 300 MTIzNDU2Nzg5MGFiY2RlZg== 123.0 0 0'],
            'TSIG error exponent' => ['TSIG', 'hmac-sha256. 1609459200 300 MTIzNDU2Nzg5MGFiY2RlZg== 12345 0e0 0'],
            'TSIG other-len exponent' => ['TSIG', 'hmac-sha256. 1609459200 300 MTIzNDU2Nzg5MGFiY2RlZg== 12345 0 0e0'],
            'TKEY mode decimal' => ['TKEY', 'gss-tsig. 1700000000 1700003600 3.0 0 ' . self::KEY],
            'TKEY mode exponent' => ['TKEY', 'gss-tsig. 1700000000 1700003600 1e0 0 ' . self::KEY],
            'TKEY error exponent' => ['TKEY', 'gss-tsig. 1700000000 1700003600 3 1e0 ' . self::KEY],
            'TKEY time exponent' => ['TKEY', 'gss-tsig. 1.7e9 1700003600 3 0 ' . self::KEY],
        ];

        $out = [];
        foreach ($cases as $label => [$type, $content]) {
            $out[$label] = [$type, $type === 'SMIMEA' ? 'abc123._smimecert.example.com' : 'host.example.com', $content];
        }

        return $out;
    }

    #[DataProvider('refusedProvider')]
    public function testNonDigitNumeralsAreRefused(string $type, string $name, string $content): void
    {
        $result = $this->validatorFor($type)->validate($content, $name, 0, 3600, 3600);

        $this->assertFalse($result->isValid(), $type . ': ' . $content);
    }

    #[DataProvider('kxPreferenceProvider')]
    public function testKxPreferenceAcceptsOnlyPlainDigits(mixed $prio, bool $valid, ?int $stored): void
    {
        $result = $this->validatorFor('KX')->validate('kx.example.com', 'example.com', $prio, 3600, 3600);

        $this->assertSame($valid, $result->isValid(), var_export($prio, true));
        if ($valid) {
            $this->assertSame($stored, $result->getData()['prio']);
        }
    }

    /**
     * @return array<string, array{mixed, bool, int|null}>
     */
    public static function kxPreferenceProvider(): array
    {
        return [
            'int' => [10, true, 10],
            'digits' => ['10', true, 10],
            'padded' => ['010', true, 10],
            'max' => ['65535', true, 65535],
            'over' => ['65536', false, null],
            'exponent' => ['1e1', false, null],
            'decimal' => ['10.0', false, null],
            'sign' => ['+10', false, null],
            'padded blanks' => [' 10 ', true, 10],
        ];
    }
}
