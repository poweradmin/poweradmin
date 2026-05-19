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

namespace Unit\Domain\Service\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\Dns\DomainManager;
use ReflectionMethod;

/**
 * Covers the template-record filter used by DomainManager when applying a
 * template to a newly created or re-synced zone.
 *
 * Issue #1248: LUA template records were silently dropped for IPv4 reverse
 * zones (in-addr.arpa) while being applied for IPv6 reverse zones (ip6.arpa).
 */
class DomainManagerTemplateRecordFilterTest extends TestCase
{
    private function shouldApply(string $domain, string $type): bool
    {
        $method = new ReflectionMethod(DomainManager::class, 'shouldApplyTemplateRecord');
        $method->setAccessible(true);
        return $method->invoke(null, $domain, $type);
    }

    public function testForwardZoneAllowsAllTypes(): void
    {
        $this->assertTrue($this->shouldApply('example.com', 'A'));
        $this->assertTrue($this->shouldApply('example.com', 'MX'));
        $this->assertTrue($this->shouldApply('example.com', 'TXT'));
        $this->assertTrue($this->shouldApply('example.com', 'LUA'));
    }

    public function testIpv6ReverseZoneAllowsAllTypes(): void
    {
        $domain = '0.8.d.9.1.0.a.2.ip6.arpa';
        $this->assertTrue($this->shouldApply($domain, 'NS'));
        $this->assertTrue($this->shouldApply($domain, 'SOA'));
        $this->assertTrue($this->shouldApply($domain, 'LUA'));
        $this->assertTrue($this->shouldApply($domain, 'PTR'));
        $this->assertTrue($this->shouldApply($domain, 'TXT'));
    }

    public function testIpv4ReverseZoneAllowsReverseRecordTypes(): void
    {
        $domain = '1.168.192.in-addr.arpa';
        $this->assertTrue($this->shouldApply($domain, 'NS'));
        $this->assertTrue($this->shouldApply($domain, 'SOA'));
        $this->assertTrue($this->shouldApply($domain, 'PTR'));
        $this->assertTrue($this->shouldApply($domain, 'LUA'));
        $this->assertTrue($this->shouldApply($domain, 'CNAME'));
        $this->assertTrue($this->shouldApply($domain, 'TXT'));
    }

    public function testIpv4ReverseZoneRejectsForwardOnlyRecordTypes(): void
    {
        $domain = '1.168.192.in-addr.arpa';
        $this->assertFalse($this->shouldApply($domain, 'A'));
        $this->assertFalse($this->shouldApply($domain, 'AAAA'));
        $this->assertFalse($this->shouldApply($domain, 'MX'));
        $this->assertFalse($this->shouldApply($domain, 'SRV'));
    }

    public function testIpv4ReverseZoneDetectionIsCaseInsensitive(): void
    {
        $this->assertTrue($this->shouldApply('1.168.192.IN-ADDR.ARPA', 'LUA'));
        $this->assertFalse($this->shouldApply('1.168.192.IN-ADDR.ARPA', 'A'));
    }

    public function testForwardZoneAlsoAllowsForwardOnlyTypes(): void
    {
        $this->assertTrue($this->shouldApply('example.com', 'AAAA'));
        $this->assertTrue($this->shouldApply('example.com', 'SRV'));
    }
}
