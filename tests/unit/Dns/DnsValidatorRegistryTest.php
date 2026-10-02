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

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\RecordType;
use Poweradmin\Domain\Service\DnsValidation\ARecordValidator;
use Poweradmin\Domain\Service\DnsValidation\DefaultRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\DnsRecordValidatorInterface;
use Poweradmin\Domain\Service\DnsValidation\DnsValidatorRegistry;
use Poweradmin\Domain\Service\DnsValidation\KXRecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use PDO;

class DnsValidatorRegistryTest extends TestCase
{
    private DnsValidatorRegistry $registry;
    private ConfigurationManager $configMock;
    private PDO $dbMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->dbMock = $this->createMock(PDO::class);
        $this->registry = new DnsValidatorRegistry($this->configMock, $this->dbMock);
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
        // Offered by the forms although it has no dedicated validator on this branch
        $this->assertTrue($this->registry->isKnownType(RecordType::WALLET));

        $this->assertFalse($this->registry->isKnownType('FOO'));
        $this->assertFalse($this->registry->isKnownType('TYPE65280'));
        $this->assertFalse($this->registry->isKnownType('a'));
        $this->assertFalse($this->registry->isKnownType(''));
    }

    public function testIsKnownTypeAcceptsTypesTheAdminConfigured(): void
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(fn(string $group, string $key): ?array => match ($key) {
            'domain_record_types' => ['A', 'TYPE65280', 'TYPE65536'],
            'reverse_record_types' => ['PTR', 'TYPE65281'],
            default => null,
        });
        $registry = new DnsValidatorRegistry($config, $this->createMock(PDO::class));

        $this->assertTrue($registry->isKnownType('TYPE65280'));
        $this->assertTrue($registry->isKnownType('TYPE65281'));
        // A narrowed list does not refuse types that have a validator
        $this->assertTrue($registry->isKnownType(RecordType::MX));
        $this->assertFalse($registry->isKnownType('TYPE65282'));
        // DNS types are 16-bit, so a listed TYPE65536 still cannot be stored
        $this->assertFalse($registry->isKnownType('TYPE65536'));
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
