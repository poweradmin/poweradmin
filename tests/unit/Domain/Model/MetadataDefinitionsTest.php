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

    public function testSoaEditApiChoicesAddOffSentinel(): void
    {
        $config = $this->configWithDnsSettings([]);

        $this->assertSame(
            ['DEFAULT', 'INCREASE', 'EPOCH', 'SOA-EDIT', 'SOA-EDIT-INCREASE', 'OFF'],
            MetadataDefinitions::getSoaEditApiChoices($config)
        );
    }

    public function testSoaEditApiChoicesKeepOffOnlyWhenConfigured(): void
    {
        $this->assertSame(
            ['EPOCH'],
            MetadataDefinitions::getSoaEditApiChoices($this->configWithDnsSettings([
                'soa_edit_api_options' => ['EPOCH'],
            ]))
        );
        $this->assertSame(
            ['EPOCH', 'OFF'],
            MetadataDefinitions::getSoaEditApiChoices($this->configWithDnsSettings([
                'soa_edit_api_options' => ['EPOCH', 'OFF'],
            ]))
        );
    }

    /**
     * PowerDNS keeps a 7-entry multi-value whitelist in pdnsutil; every other
     * kind stores a single row. Getting this wrong means the editor refuses a
     * legitimate second value.
     */
    public function testMultiValueKindsMatchPowerDnsWhitelist(): void
    {
        $multi = array_keys(array_filter(
            MetadataDefinitions::DEFINITIONS,
            fn(array $definition): bool => (bool) $definition['multi']
        ));
        sort($multi);

        $this->assertSame([
            'ALLOW-AXFR-FROM',
            'ALLOW-DNSUPDATE-FROM',
            'ALSO-NOTIFY',
            'GSS-ALLOW-AXFR-PRINCIPAL',
            'PUBLISH-CDS',
            'TSIG-ALLOW-AXFR',
            'TSIG-ALLOW-DNSUPDATE',
        ], $multi);
    }

    public function testCustomKindsDefaultToMultiValue(): void
    {
        $this->assertTrue(MetadataDefinitions::isMultiValue('X-CUSTOM-KIND'));
    }

    public function testZonePropertyKindsCoverEveryKindPowerDnsRefusesOnMetadata(): void
    {
        $kinds = array_keys(MetadataDefinitions::ZONE_PROPERTY_KINDS);
        sort($kinds);

        $this->assertSame(
            ['API-RECTIFY', 'NSEC3NARROW', 'NSEC3PARAM', 'SOA-EDIT', 'SOA-EDIT-API'],
            $kinds
        );

        // The serial policy map stays narrower; the backend providers filter
        // their writes against it.
        $this->assertSame(
            ['SOA-EDIT-API' => 'soa_edit_api', 'SOA-EDIT' => 'soa_edit'],
            MetadataDefinitions::SERIAL_POLICY_PROPERTY_KINDS
        );
    }

    public function testApiBackendAcceptsZonePropertyKindsAndRefusesRoutelessOnes(): void
    {
        foreach (['SOA-EDIT', 'SOA-EDIT-API', 'API-RECTIFY', 'NSEC3PARAM', 'NSEC3NARROW', 'ALLOW-AXFR-FROM'] as $kind) {
            $this->assertNull(MetadataDefinitions::writeRejection($kind, true), $kind);
        }

        foreach (['PRESIGNED', 'LUA-AXFR-SCRIPT', 'ENABLE-LUA-RECORDS', 'AXFR-MASTER-TSIG'] as $kind) {
            $this->assertSame(
                MetadataDefinitions::REJECT_NO_API_ROUTE,
                MetadataDefinitions::writeRejection($kind, true),
                $kind
            );
        }
    }

    public function testSqlBackendOnlyRefusesServerManagedKinds(): void
    {
        $this->assertNull(MetadataDefinitions::writeRejection('PRESIGNED', false));
        $this->assertNull(MetadataDefinitions::writeRejection('BILLING-REF', false));
        $this->assertSame(
            MetadataDefinitions::REJECT_SERVER_MANAGED,
            MetadataDefinitions::writeRejection('CATALOG-HASH', false)
        );
    }

    public function testServerManagedKindsAreNeverWritable(): void
    {
        $this->assertTrue(MetadataDefinitions::isServerManaged('CATALOG-HASH'));
        $this->assertFalse(MetadataDefinitions::isServerManaged('SOA-EDIT'));
        $this->assertSame(
            MetadataDefinitions::REJECT_SERVER_MANAGED,
            MetadataDefinitions::writeRejection('CATALOG-HASH', true)
        );
    }

    public function testCustomKindsNeedTheXPrefixForTheApi(): void
    {
        $this->assertNull(MetadataDefinitions::writeRejection('X-BILLING-REF', true));
        $this->assertSame(
            MetadataDefinitions::REJECT_CUSTOM_PREFIX,
            MetadataDefinitions::writeRejection('BILLING-REF', true)
        );
    }

    public function testBooleanZonePropertyKindsOfferOnlyZeroAndOne(): void
    {
        foreach (MetadataDefinitions::BOOLEAN_ZONE_PROPERTY_KINDS as $kind) {
            $this->assertSame(['0', '1'], MetadataDefinitions::getOptions($kind), $kind);
        }
    }

    public function testNsec3NarrowNeedsNsec3ParamOnlyWhenEnabled(): void
    {
        $this->assertSame('NSEC3PARAM', MetadataDefinitions::requiredCompanionKind('NSEC3NARROW', '1'));
        $this->assertNull(MetadataDefinitions::requiredCompanionKind('NSEC3NARROW', '0'));
        $this->assertNull(MetadataDefinitions::requiredCompanionKind('NSEC3PARAM', '1 0 0 -'));
    }

    public function testRowsFromApiPayloadPrefersTheZoneObject(): void
    {
        $rows = MetadataDefinitions::rowsFromApiPayload(
            [
                ['kind' => 'NSEC3PARAM', 'metadata' => ['1 0 0 -']],
                ['kind' => 'ALLOW-AXFR-FROM', 'metadata' => ['192.0.2.10']],
            ],
            ['nsec3param' => '1 0 5 ab', 'nsec3narrow' => true, 'api_rectify' => false]
        );

        $this->assertSame([
            ['kind' => 'ALLOW-AXFR-FROM', 'content' => '192.0.2.10'],
            ['kind' => 'NSEC3PARAM', 'content' => '1 0 5 ab'],
            ['kind' => 'NSEC3NARROW', 'content' => '1'],
        ], $rows);
    }

    public function testZonePropertyValuesRoundTripThroughTheirJsonType(): void
    {
        $this->assertTrue(MetadataDefinitions::toZonePropertyValue('API-RECTIFY', '1'));
        $this->assertFalse(MetadataDefinitions::toZonePropertyValue('API-RECTIFY', ''));
        $this->assertSame('EPOCH', MetadataDefinitions::toZonePropertyValue('SOA-EDIT', 'EPOCH'));

        $this->assertSame('1', MetadataDefinitions::fromZonePropertyValue('NSEC3NARROW', true));
        $this->assertSame('', MetadataDefinitions::fromZonePropertyValue('NSEC3NARROW', false));
        $this->assertSame('1 0 0 -', MetadataDefinitions::fromZonePropertyValue('NSEC3PARAM', '1 0 0 -'));
    }
}
