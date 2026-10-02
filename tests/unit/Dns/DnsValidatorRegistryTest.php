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

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\PdnsCapabilities;
use Poweradmin\Domain\Model\RecordType;
use Poweradmin\Domain\Service\DnsValidation\ARecordValidator;
use Poweradmin\Domain\Service\DnsValidation\DefaultRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\DnsRecordValidatorInterface;
use Poweradmin\Domain\Service\DnsValidation\DnsValidatorRegistry;
use Poweradmin\Domain\Service\DnsValidation\KXRecordValidator;
use Poweradmin\Domain\Port\DnsBackendProviderInterface;
use TestHelpers\FakeConfiguration;

class DnsValidatorRegistryTest extends TestCase
{
    private DnsValidatorRegistry $registry;
    private FakeConfiguration $configMock;

    protected function setUp(): void
    {
        $this->configMock = new FakeConfiguration();
        // Only the CNAME validator reads the backend, and no test here validates a record
        $this->registry = new DnsValidatorRegistry($this->configMock, $this->createMock(DnsBackendProviderInterface::class));
    }

    /**
     * Test getting a validator for standard record types
     */
    public function testGetValidatorForStandardType(): void
    {
        $validator = $this->registry->getValidator(RecordType::A);
        $this->assertInstanceOf(DnsRecordValidatorInterface::class, $validator);
        $this->assertInstanceOf(ARecordValidator::class, $validator);
    }

    /**
     * Test getting a validator for non-implemented record type
     */
    public function testGetValidatorForNonImplementedType(): void
    {
        // Using a non-standard record type that isn't explicitly implemented
        $validator = $this->registry->getValidator('NONEXISTENT');
        $this->assertInstanceOf(DnsRecordValidatorInterface::class, $validator);
        $this->assertInstanceOf(DefaultRecordValidator::class, $validator);
    }

    /**
     * Test that hasValidator always returns true
     */
    public function testHasValidatorAlwaysReturnsTrue(): void
    {
        // Standard record type
        $this->assertTrue($this->registry->hasValidator(RecordType::A));

        // Non-standard record type
        $this->assertTrue($this->registry->hasValidator('CAA'));

        // Random string
        $this->assertTrue($this->registry->hasValidator('NON_EXISTENT_TYPE'));
    }

    /**
     * Test validator implements the DnsRecordValidatorInterface
     */
    public function testValidatorImplementsInterface(): void
    {
        $validatorA = $this->registry->getValidator(RecordType::A);
        $this->assertInstanceOf(DnsRecordValidatorInterface::class, $validatorA);

        $validatorCustom = $this->registry->getValidator('CUSTOM_TYPE');
        $this->assertInstanceOf(DnsRecordValidatorInterface::class, $validatorCustom);
    }

    public function testIsKnownTypeAcceptsEveryTypeWithAValidatorAndRefusesTheRest(): void
    {
        $this->assertTrue($this->registry->isKnownType(RecordType::A));
        $this->assertTrue($this->registry->isKnownType(RecordType::DS));
        $this->assertTrue($this->registry->isKnownType(RecordType::PTR));

        $this->assertFalse($this->registry->isKnownType('FOO'));
        $this->assertFalse($this->registry->isKnownType('TYPE65280'));
        $this->assertFalse($this->registry->isKnownType('a'));
        $this->assertFalse($this->registry->isKnownType(''));
    }

    public function testIsKnownTypeAcceptsTypesTheAdminConfigured(): void
    {
        $registry = new DnsValidatorRegistry(
            new FakeConfiguration(['dns' => ['domain_record_types' => ['A', 'TYPE65280'], 'reverse_record_types' => ['PTR', 'TYPE65281']]]),
            $this->createMock(DnsBackendProviderInterface::class)
        );

        $this->assertTrue($registry->isKnownType('TYPE65280'));
        $this->assertTrue($registry->isKnownType('TYPE65281'));
        // A narrowed list does not refuse types that have a validator
        $this->assertTrue($registry->isKnownType(RecordType::MX));
        $this->assertFalse($registry->isKnownType('TYPE65282'));
    }

    public function testVersionDependentTypeIsRefusedOnlyWhenTheServerIsKnownToBeOlder(): void
    {
        $this->assertFalse($this->registryFor('4.9.0')->isTypeSupportedByServer('WALLET'));
        $this->assertTrue($this->registryFor('5.1.0')->isTypeSupportedByServer('WALLET'));
        // SQL-only setups never learn the version, so nothing is refused there
        $this->assertTrue($this->registryFor(null)->isTypeSupportedByServer('WALLET'));
        $this->assertTrue($this->registry->isTypeSupportedByServer('WALLET'));
    }

    public function testVersionIsLookedUpOnlyForVersionDependentInput(): void
    {
        $lookups = 0;
        $registry = new DnsValidatorRegistry(new FakeConfiguration(), $this->createMock(DnsBackendProviderInterface::class), function () use (&$lookups) {
            $lookups++;
            return PdnsCapabilities::fromVersion('4.9.0');
        });

        $this->assertTrue($registry->isTypeSupportedByServer(RecordType::A));
        $this->assertTrue($registry->getValidator(RecordType::SVCB)->validate('1 svc.example.com alpn=h2', 'x.example.com', '', 3600, 86400)->isValid());
        $this->assertSame(0, $lookups);

        $registry->isTypeSupportedByServer('WALLET');
        $registry->isTypeSupportedByServer('ZONEMD');
        $this->assertSame(1, $lookups);
    }

    public function testSvcParamNamesFromPowerDns51AreRefusedOnOlderServers(): void
    {
        foreach ([RecordType::SVCB, RecordType::HTTPS] as $type) {
            foreach (['dohpath=/dns-query{?dns}', 'ohttp', 'tls-supported-groups=29'] as $param) {
                $content = '1 svc.example.com alpn=h2 ' . $param;
                $this->assertFalse($this->registryFor('4.9.0')->getValidator($type)->validate($content, 'x.example.com', '', 3600, 86400)->isValid(), "$type $param on 4.9");
                $this->assertTrue($this->registryFor('5.1.0')->getValidator($type)->validate($content, 'x.example.com', '', 3600, 86400)->isValid(), "$type $param on 5.1");
                $this->assertTrue($this->registryFor(null)->getValidator($type)->validate($content, 'x.example.com', '', 3600, 86400)->isValid(), "$type $param unknown");
            }
        }
    }

    private function registryFor(?string $version): DnsValidatorRegistry
    {
        return new DnsValidatorRegistry(
            new FakeConfiguration(),
            $this->createMock(DnsBackendProviderInterface::class),
            fn() => PdnsCapabilities::fromVersion($version)
        );
    }

    /**
     * Test getting KX record validator
     */
    public function testGetKXValidator(): void
    {
        $validator = $this->registry->getValidator(RecordType::KX);
        $this->assertInstanceOf(DnsRecordValidatorInterface::class, $validator);
        $this->assertInstanceOf(KXRecordValidator::class, $validator);
    }
}
