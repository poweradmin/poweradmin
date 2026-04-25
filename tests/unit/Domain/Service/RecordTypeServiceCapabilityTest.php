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

namespace Poweradmin\Tests\Unit\Domain\Service;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\PdnsCapabilities;
use Poweradmin\Domain\Service\RecordTypeService;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;

class RecordTypeServiceCapabilityTest extends TestCase
{
    private ConfigurationInterface $config;
    private RecordTypeService $service;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigurationInterface::class);
        // No custom record-type lists configured - service uses constants.
        $this->config->method('get')->willReturn(null);
        $this->service = new RecordTypeService($this->config);
    }

    public function testGetAllTypesUnfilteredWhenNoCapabilitiesPassed(): void
    {
        $types = $this->service->getAllTypes();
        $this->assertContains('A', $types);
        $this->assertContains('SVCB', $types);
        $this->assertContains('ZONEMD', $types);
    }

    public function testGetAllTypesFiltersBySupportedRecordTypes(): void
    {
        $caps = PdnsCapabilities::fromVersion('4.3.0');
        $types = $this->service->getAllTypes($caps);

        // Common types remain available on every server.
        $this->assertContains('A', $types);
        $this->assertContains('CNAME', $types);
        $this->assertContains('MX', $types);

        // 4.3 doesn't know SVCB/HTTPS (4.4+) or ZONEMD (4.8+) or WALLET (5.1+).
        $this->assertNotContains('SVCB', $types);
        $this->assertNotContains('HTTPS', $types);
        $this->assertNotContains('ZONEMD', $types);
        $this->assertNotContains('WALLET', $types);
        // CSYNC requires 4.5.
        $this->assertNotContains('CSYNC', $types);
    }

    public function testGetAllTypesAdmits44PlusRecordTypes(): void
    {
        $caps = PdnsCapabilities::fromVersion('4.4.0');
        $types = $this->service->getAllTypes($caps);
        $this->assertContains('SVCB', $types);
        $this->assertContains('HTTPS', $types);
        $this->assertNotContains('CSYNC', $types);
        $this->assertNotContains('ZONEMD', $types);
    }

    public function testGetAllTypesAdmits51WalletRecord(): void
    {
        $caps = PdnsCapabilities::fromVersion('5.1.0');
        $types = $this->service->getAllTypes($caps);
        $this->assertContains('WALLET', $types);
    }

    public function testGetAllTypesUnknownVersionDropsAllGatedTypes(): void
    {
        // Strict mode: when version detection fails, every version-gated type
        // disappears from the dropdown so users don't get options the server
        // would reject.
        $caps = PdnsCapabilities::fromVersion(null);
        $types = $this->service->getAllTypes($caps);

        $this->assertContains('A', $types);
        $this->assertContains('CNAME', $types);
        $this->assertNotContains('SVCB', $types);
        $this->assertNotContains('HTTPS', $types);
        $this->assertNotContains('CSYNC', $types);
        $this->assertNotContains('ZONEMD', $types);
        $this->assertNotContains('WALLET', $types);
    }

    public function testGetDomainZoneTypesFiltersBySupportedRecordTypes(): void
    {
        $caps = PdnsCapabilities::fromVersion('4.4.0');
        $types = $this->service->getDomainZoneTypes(false, $caps);

        $this->assertContains('A', $types);
        $this->assertContains('SVCB', $types);
        $this->assertNotContains('CSYNC', $types);
        $this->assertNotContains('ZONEMD', $types);
    }

    public function testGetReverseZoneTypesFiltersBySupportedRecordTypes(): void
    {
        // Reverse zones use REVERSE_ZONE_COMMON_RECORDS - only PTR/CNAME/etc.
        // None of those are version-gated, so the filter is effectively a no-op
        // here, but the call must still succeed and not strip common types.
        $caps = PdnsCapabilities::fromVersion(null);
        $types = $this->service->getReverseZoneTypes(false, $caps);
        $this->assertContains('PTR', $types);
        $this->assertContains('CNAME', $types);
    }
}
