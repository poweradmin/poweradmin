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

namespace Poweradmin\Tests\Unit\Domain\Model;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\MetadataDefinitions;

#[CoversClass(MetadataDefinitions::class)]
class MetadataDefinitionsTest extends TestCase
{
    public function testGetOptionsForSoaEditApi(): void
    {
        $this->assertSame(
            ['DEFAULT', 'INCREASE', 'EPOCH', 'SOA-EDIT', 'SOA-EDIT-INCREASE'],
            MetadataDefinitions::getOptions('SOA-EDIT-API')
        );
    }

    public function testGetOptionsForSoaEdit(): void
    {
        $this->assertSame(
            ['INCEPTION-INCREMENT', 'INCREMENT-WEEKS', 'EPOCH', 'INCEPTION-EPOCH', 'NONE'],
            MetadataDefinitions::getOptions('SOA-EDIT')
        );
    }

    public function testGetOptionsForSoaEditDnsupdateMatchesSoaEditApi(): void
    {
        $this->assertSame(
            MetadataDefinitions::getOptions('SOA-EDIT-API'),
            MetadataDefinitions::getOptions('SOA-EDIT-DNSUPDATE')
        );
    }

    public function testGetOptionsIsNullForFreeFormKind(): void
    {
        $this->assertNull(MetadataDefinitions::getOptions('ALLOW-AXFR-FROM'));
    }

    public function testGetOptionsIsNullForUnknownKind(): void
    {
        $this->assertNull(MetadataDefinitions::getOptions('X-CUSTOM-KIND'));
    }

    private function configWithDnsSettings(array $settings): \Poweradmin\Infrastructure\Configuration\ConfigurationInterface
    {
        $config = $this->createMock(\Poweradmin\Infrastructure\Configuration\ConfigurationInterface::class);
        $config->method('get')->willReturnCallback(
            fn(string $group, string $key, mixed $default = null) => $settings[$key] ?? $default
        );
        return $config;
    }

    public function testOfferedOptionsMatchStaticOptionsWithoutConfig(): void
    {
        $config = $this->configWithDnsSettings([]);

        $this->assertSame(
            MetadataDefinitions::getOptions('SOA-EDIT-API'),
            MetadataDefinitions::getOfferedOptions('SOA-EDIT-API', $config)
        );
        $this->assertNull(MetadataDefinitions::getOfferedOptions('ALLOW-AXFR-FROM', $config));
    }

    public function testOfferedOptionsNarrowedByConfigList(): void
    {
        $config = $this->configWithDnsSettings(['soa_edit_api_options' => ['EPOCH', 'INCREASE', 'BOGUS']]);

        $this->assertSame(
            ['INCREASE', 'EPOCH'],
            MetadataDefinitions::getOfferedOptions('SOA-EDIT-API', $config)
        );
        // SOA-EDIT-DNSUPDATE shares the same config key
        $this->assertSame(
            ['INCREASE', 'EPOCH'],
            MetadataDefinitions::getOfferedOptions('SOA-EDIT-DNSUPDATE', $config)
        );
    }

    public function testOfferedOptionsEmptyListDisablesKind(): void
    {
        $config = $this->configWithDnsSettings(['soa_edit_options' => []]);

        $this->assertSame([], MetadataDefinitions::getOfferedOptions('SOA-EDIT', $config));
    }
}
