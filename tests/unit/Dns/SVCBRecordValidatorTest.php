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
use Poweradmin\Domain\Service\DnsValidation\SVCBRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\HostnamePolicy;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;

/**
 * Tests for the SVCBRecordValidator
 *
 * Verifies compliance with RFC 9460 Section 2.4.1:
 * - Alias Mode (priority = 0): No SvcParams allowed, target cannot be "."
 * - Service Mode (priority > 0): SvcParams allowed, "." target requires params
 */
class SVCBRecordValidatorTest extends TestCase
{
    private SVCBRecordValidator $validator;

    protected function setUp(): void
    {
        $hostnameValidator = new HostnameValidator(new HostnamePolicy(true, true));

        $this->validator = new SVCBRecordValidator($hostnameValidator);
    }

    // ========================================================================
    // Alias Mode (priority = 0)
    // ========================================================================

    public function testAliasModeWithValidTarget(): void
    {
        $result = $this->validator->validate('0 svc.example.com', 'host.example.com', '', 3600, 86400);
        $this->assertTrue($result->isValid());
    }

    public function testAliasModeRejectsDotTarget(): void
    {
        $result = $this->validator->validate('0 .', 'host.example.com', '', 3600, 86400);
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Alias Mode', $result->getFirstError());
    }

    public function testAliasModeRejectsParams(): void
    {
        $result = $this->validator->validate('0 svc.example.com alpn=h2', 'host.example.com', '', 3600, 86400);
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Alias Mode', $result->getFirstError());
    }

    // ========================================================================
    // Service Mode (priority > 0)
    // ========================================================================

    public function testServiceModeWithTargetOnly(): void
    {
        $result = $this->validator->validate('1 svc.example.com', 'host.example.com', '', 3600, 86400);
        $this->assertTrue($result->isValid());
    }

    public function testServiceModeWithParams(): void
    {
        // RFC 9460 test vector: ServiceMode with port param
        $result = $this->validator->validate('16 foo.example.com port=53', 'host.example.com', '', 3600, 86400);
        $this->assertTrue($result->isValid());
    }

    public function testServiceModeWithMultipleParams(): void
    {
        $result = $this->validator->validate('1 . alpn=h2,h3 port=443 ipv4hint=192.0.2.1', 'host.example.com', '', 3600, 86400);
        $this->assertTrue($result->isValid());
    }

    public function testServiceModeDotTargetRequiresParams(): void
    {
        $result = $this->validator->validate('1 .', 'host.example.com', '', 3600, 86400);
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Service Mode', $result->getFirstError());
    }

    // ========================================================================
    // Priority validation
    // ========================================================================

    public function testRejectsInvalidPriority(): void
    {
        $result = $this->validator->validate('65536 example.com', 'host.example.com', '', 3600, 86400);
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('priority', $result->getFirstError());
    }

    // ========================================================================
    // Parameter validation
    // ========================================================================

    public function testRejectsInvalidParamFormat(): void
    {
        $result = $this->validator->validate('1 example.com alpn:h2', 'host.example.com', '', 3600, 86400);
        $this->assertFalse($result->isValid());
    }

    public function testRejectsDuplicateParams(): void
    {
        $result = $this->validator->validate('1 example.com alpn=h2 alpn=h3', 'host.example.com', '', 3600, 86400);
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Duplicate', $result->getFirstError());
    }

    public function testRejectsInvalidPort(): void
    {
        $result = $this->validator->validate('1 example.com port=70000', 'host.example.com', '', 3600, 86400);
        $this->assertFalse($result->isValid());
    }

    public function testRejectsInvalidIpv4Hint(): void
    {
        $result = $this->validator->validate('1 example.com ipv4hint=300.300.300.300', 'host.example.com', '', 3600, 86400);
        $this->assertFalse($result->isValid());
    }

    public function testRejectsInvalidIpv6Hint(): void
    {
        $result = $this->validator->validate('1 example.com ipv6hint=zzzz::1', 'host.example.com', '', 3600, 86400);
        $this->assertFalse($result->isValid());
    }

    /**
     * Each of these makes PowerDNS answer SERVFAIL for the name and abort AXFR of the zone.
     */
    #[DataProvider('contentPowerDnsCannotLoadProvider')]
    public function testRejectsContentPowerDnsCannotLoad(string $content): void
    {
        $this->assertFalse($this->validator->validate($content, 'host.example.com', '', 3600, 86400)->isValid());
    }

    public static function contentPowerDnsCannotLoadProvider(): array
    {
        return [
            'alias mode with params' => ['0 svc.example.com port=443'],
            'unknown key name' => ['1 svc.example.com foo=bar'],
            'key number above 16 bits' => ['1 svc.example.com key65536=x'],
            'odohconfig is not a key' => ['1 svc.example.com odohconfig=AAAA'],
            'ech not base64' => ['1 svc.example.com ech=notbase64%%'],
            'ech empty' => ['1 svc.example.com ech='],
            'ech opening quote only' => ['1 svc.example.com ech="AAAA'],
            'ech closing quote only' => ['1 svc.example.com ech=AAAA"'],
            'ech empty quotes' => ['1 svc.example.com ech=""'],
            'generic form of a named key' => ['1 svc.example.com alpn=h2 key1=h3'],
            'generic form of port' => ['1 svc.example.com key3=443'],
            'mandatory lists itself' => ['1 svc.example.com mandatory=mandatory,alpn alpn=h2'],
            'no-default-alpn with equals' => ['1 svc.example.com alpn=h2 no-default-alpn='],
            'ohttp with equals' => ['1 svc.example.com ohttp='],
            'ohttp with value' => ['1 svc.example.com ohttp=""'],
            'value key without equals' => ['1 svc.example.com alpn'],
            'mandatory names an unknown key' => ['1 svc.example.com mandatory=foo'],
            'mandatory names an absent key' => ['1 svc.example.com mandatory=port alpn=h2'],
            'mandatory empty' => ['1 svc.example.com mandatory= alpn=h2'],
            'tls-supported-groups not numeric' => ['1 svc.example.com tls-supported-groups=abc'],
            'tls-supported-groups empty' => ['1 svc.example.com tls-supported-groups='],
            'tls-supported-groups above 16 bits' => ['1 svc.example.com tls-supported-groups=29,70000'],
        ];
    }

    #[DataProvider('contentPowerDnsLoadsProvider')]
    public function testAcceptsContentPowerDnsLoads(string $content): void
    {
        $this->assertTrue($this->validator->validate($content, 'host.example.com', '', 3600, 86400)->isValid());
    }

    public static function contentPowerDnsLoadsProvider(): array
    {
        return [
            'highest generic key' => ['1 svc.example.com key65535=x'],
            'generic key PowerDNS before 5.1 has no name for' => ['1 svc.example.com key9=29'],
            'mandatory' => ['1 svc.example.com mandatory=alpn alpn=h2'],
            'bare no-default-alpn' => ['1 svc.example.com alpn=h2 no-default-alpn'],
            'bare ohttp' => ['1 svc.example.com ohttp'],
            'tls-supported-groups' => ['1 svc.example.com tls-supported-groups=29,23'],
            'ech base64' => ['1 svc.example.com ech=AEj+DQBEAQAgACAdd+scUi0IYFsXnUIU7ko2Nd9+F8M26pAGZVpz/KrWPgAEAAEAAWQVZWNoLXNpdGVzLmV4YW1wbGUubmV0AAA='],
            'ech quoted' => ['1 svc.example.com ech="AEj+DQBEAQAgACAdd+scUi0IYFsXnUIU7ko2Nd9+F8M26pAGZVpz/KrWPgAEAAEAAWQVZWNoLXNpdGVzLmV4YW1wbGUubmV0AAA="'],
        ];
    }
}
