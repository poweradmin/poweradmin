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

namespace Poweradmin\Tests\Unit\Domain\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\RecordType;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\DnsRecordValidationService;
use Poweradmin\Domain\Service\DnsValidation\CNAMERecordValidator;
use Poweradmin\Domain\Service\DnsValidation\DnsCommonValidator;
use Poweradmin\Domain\Service\DnsValidation\DnsRecordValidatorInterface;
use Poweradmin\Domain\Service\DnsValidation\DnsValidatorRegistry;
use Poweradmin\Domain\Service\DnsValidation\DNSViolationValidator;
use Poweradmin\Domain\Service\DnsValidation\SOARecordValidator;
use Poweradmin\Domain\Service\DnsValidation\TTLValidator;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Infrastructure\Service\MessageService;

#[CoversClass(DnsRecordValidationService::class)]
class DnsRecordValidationServiceTest extends TestCase
{
    private DnsRecordValidationService $service;
    private DnsValidatorRegistry&MockObject $validatorRegistry;
    private DnsCommonValidator&MockObject $dnsCommonValidator;
    private TTLValidator&MockObject $ttlValidator;
    private MessageService&MockObject $messageService;
    private ZoneRepositoryInterface&MockObject $zoneRepository;
    private DNSViolationValidator&MockObject $dnsViolationValidator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validatorRegistry = $this->createMock(DnsValidatorRegistry::class);
        $this->dnsCommonValidator = $this->createMock(DnsCommonValidator::class);
        $this->ttlValidator = $this->createMock(TTLValidator::class);
        $this->messageService = $this->createMock(MessageService::class);
        $this->zoneRepository = $this->createMock(ZoneRepositoryInterface::class);
        $this->dnsViolationValidator = $this->createMock(DNSViolationValidator::class);

        $this->service = new DnsRecordValidationService(
            $this->validatorRegistry,
            $this->dnsCommonValidator,
            $this->ttlValidator,
            $this->messageService,
            $this->zoneRepository,
            $this->dnsViolationValidator
        );
    }

    // ========== Zone validation tests ==========

    #[Test]
    public function testValidateRecordReturnsFailureWhenZoneNotFound(): void
    {
        $this->zoneRepository->method('getDomainNameById')
            ->with(999)
            ->willReturn(null);

        $result = $this->service->validateRecord(
            1,      // rid
            999,    // zid (non-existent)
            'A',
            '192.168.1.1',
            'test.example.com',
            null,
            3600,
            'hostmaster@example.com',
            86400
        );

        $this->assertFalse($result->isValid());
    }

    // ========== A record validation tests ==========

    #[Test]
    public function testValidateRecordSucceedsForValidARecord(): void
    {
        $this->zoneRepository->method('getDomainNameById')
            ->with(1)
            ->willReturn('example.com');

        $aValidator = $this->createMock(DnsRecordValidatorInterface::class);
        $aValidator->method('validate')
            ->willReturn(ValidationResult::success([
                'content' => '192.168.1.1',
                'name' => 'test.example.com',
                'prio' => 0,
                'ttl' => 3600
            ]));

        $cnameValidator = $this->createMock(CNAMERecordValidator::class);
        $cnameValidator->method('validateCnameExistence')
            ->willReturn(ValidationResult::success([]));

        $this->validatorRegistry->method('getValidator')
            ->willReturnMap([
                ['A', $aValidator],
                [RecordType::CNAME, $cnameValidator]
            ]);

        $this->dnsViolationValidator->method('validate')
            ->willReturn(ValidationResult::success([]));

        $result = $this->service->validateRecord(
            1,
            1,
            'A',
            '192.168.1.1',
            'test.example.com',
            null,
            3600,
            'hostmaster@example.com',
            86400
        );

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals('192.168.1.1', $data['content']);
        $this->assertEquals('test.example.com', $data['name']);
    }

    // ========== CNAME existence check tests ==========

    #[Test]
    public function testValidateRecordFailsWhenCnameExistsForName(): void
    {
        $this->zoneRepository->method('getDomainNameById')
            ->with(1)
            ->willReturn('example.com');

        $aValidator = $this->createMock(DnsRecordValidatorInterface::class);

        $cnameValidator = $this->createMock(CNAMERecordValidator::class);
        $cnameValidator->method('validateCnameExistence')
            ->willReturn(ValidationResult::failure('A CNAME record already exists for this name.'));

        $this->validatorRegistry->method('getValidator')
            ->willReturnMap([
                ['A', $aValidator],
                [RecordType::CNAME, $cnameValidator]
            ]);

        $result = $this->service->validateRecord(
            1,
            1,
            'A',
            '192.168.1.1',
            'test.example.com',
            null,
            3600,
            'hostmaster@example.com',
            86400
        );

        $this->assertFalse($result->isValid());
    }

    // ========== DNS violation tests ==========

    #[Test]
    public function testValidateRecordFailsOnDnsViolation(): void
    {
        $this->zoneRepository->method('getDomainNameById')
            ->with(1)
            ->willReturn('example.com');

        $aValidator = $this->createMock(DnsRecordValidatorInterface::class);

        $cnameValidator = $this->createMock(CNAMERecordValidator::class);
        $cnameValidator->method('validateCnameExistence')
            ->willReturn(ValidationResult::success([]));

        $this->validatorRegistry->method('getValidator')
            ->willReturnMap([
                ['A', $aValidator],
                [RecordType::CNAME, $cnameValidator]
            ]);

        $this->dnsViolationValidator->method('validate')
            ->willReturn(ValidationResult::failure('DNS violation detected.'));

        $result = $this->service->validateRecord(
            1,
            1,
            'A',
            '192.168.1.1',
            'test.example.com',
            null,
            3600,
            'hostmaster@example.com',
            86400
        );

        $this->assertFalse($result->isValid());
    }

    // ========== Record type specific validation tests ==========

    #[Test]
    public function testValidateRecordFailsWhenValidatorReturnsError(): void
    {
        $this->zoneRepository->method('getDomainNameById')
            ->with(1)
            ->willReturn('example.com');

        $aValidator = $this->createMock(DnsRecordValidatorInterface::class);
        $aValidator->method('validate')
            ->willReturn(ValidationResult::failure('Invalid IP address'));

        $cnameValidator = $this->createMock(CNAMERecordValidator::class);
        $cnameValidator->method('validateCnameExistence')
            ->willReturn(ValidationResult::success([]));

        $this->validatorRegistry->method('getValidator')
            ->willReturnMap([
                ['A', $aValidator],
                [RecordType::CNAME, $cnameValidator]
            ]);

        $this->dnsViolationValidator->method('validate')
            ->willReturn(ValidationResult::success([]));

        $result = $this->service->validateRecord(
            1,
            1,
            'A',
            'invalid-ip',
            'test.example.com',
            null,
            3600,
            'hostmaster@example.com',
            86400
        );

        $this->assertFalse($result->isValid());
    }

    // ========== CNAME record tests ==========

    #[Test]
    public function testValidateRecordSkipsCnameExistenceCheckForCnameType(): void
    {
        $this->zoneRepository->method('getDomainNameById')
            ->with(1)
            ->willReturn('example.com');

        $cnameValidator = $this->createMock(CNAMERecordValidator::class);
        // validateCnameExistence should NOT be called for CNAME records
        $cnameValidator->expects($this->never())
            ->method('validateCnameExistence');

        $cnameValidator->method('validate')
            ->willReturn(ValidationResult::success([
                'content' => 'target.example.com',
                'name' => 'alias.example.com',
                'prio' => 0,
                'ttl' => 3600
            ]));

        $this->validatorRegistry->method('getValidator')
            ->willReturn($cnameValidator);

        $this->dnsViolationValidator->method('validate')
            ->willReturn(ValidationResult::success([]));

        $result = $this->service->validateRecord(
            1,
            1,
            RecordType::CNAME,
            'target.example.com',
            'alias.example.com',
            null,
            3600,
            'hostmaster@example.com',
            86400
        );

        $this->assertTrue($result->isValid());
    }

    // ========== SOA record tests ==========

    #[Test]
    public function testValidateRecordSetsSoaParamsForSoaType(): void
    {
        $this->zoneRepository->method('getDomainNameById')
            ->with(1)
            ->willReturn('example.com');

        $soaValidator = $this->createMock(SOARecordValidator::class);
        $soaValidator->expects($this->once())
            ->method('setSOAParams')
            ->with('hostmaster@example.com', 'example.com');

        $soaValidator->method('validate')
            ->willReturn(ValidationResult::success([
                'content' => 'ns1.example.com. hostmaster.example.com. 2024010101 3600 600 86400 3600',
                'name' => 'example.com',
                'prio' => 0,
                'ttl' => 86400
            ]));

        $cnameValidator = $this->createMock(CNAMERecordValidator::class);
        $cnameValidator->method('validateCnameExistence')
            ->willReturn(ValidationResult::success([]));

        $this->validatorRegistry->method('getValidator')
            ->willReturnMap([
                [RecordType::SOA, $soaValidator],
                [RecordType::CNAME, $cnameValidator]
            ]);

        $this->dnsViolationValidator->method('validate')
            ->willReturn(ValidationResult::success([]));

        $result = $this->service->validateRecord(
            1,
            1,
            RecordType::SOA,
            'ns1.example.com. hostmaster.example.com. 2024010101 3600 600 86400 3600',
            'example.com',
            null,
            86400,
            'hostmaster@example.com',
            86400
        );

        $this->assertTrue($result->isValid());
    }

    // ========== NS/MX non-alias target validation tests ==========

    #[Test]
    public function testValidateRecordChecksNonAliasTargetForNsRecord(): void
    {
        $this->zoneRepository->method('getDomainNameById')
            ->with(1)
            ->willReturn('example.com');

        $nsValidator = $this->createMock(DnsRecordValidatorInterface::class);
        $nsValidator->method('validate')
            ->willReturn(ValidationResult::success([
                'content' => 'ns1.example.com',
                'name' => 'example.com',
                'prio' => 0,
                'ttl' => 3600
            ]));

        $cnameValidator = $this->createMock(CNAMERecordValidator::class);
        $cnameValidator->method('validateCnameExistence')
            ->willReturn(ValidationResult::success([]));

        $this->validatorRegistry->method('getValidator')
            ->willReturnMap([
                [RecordType::NS, $nsValidator],
                [RecordType::CNAME, $cnameValidator]
            ]);

        $this->dnsViolationValidator->method('validate')
            ->willReturn(ValidationResult::success([]));

        $this->dnsCommonValidator->expects($this->once())
            ->method('validateNonAliasTarget')
            ->with('ns1.example.com')
            ->willReturn(ValidationResult::success([]));

        $result = $this->service->validateRecord(
            1,
            1,
            RecordType::NS,
            'ns1.example.com',
            'example.com',
            null,
            3600,
            'hostmaster@example.com',
            86400
        );

        $this->assertTrue($result->isValid());
    }

    #[Test]
    public function testValidateRecordChecksNonAliasTargetForMxRecord(): void
    {
        $this->zoneRepository->method('getDomainNameById')
            ->with(1)
            ->willReturn('example.com');

        $mxValidator = $this->createMock(DnsRecordValidatorInterface::class);
        $mxValidator->method('validate')
            ->willReturn(ValidationResult::success([
                'content' => 'mail.example.com',
                'name' => 'example.com',
                'prio' => 10,
                'ttl' => 3600
            ]));

        $cnameValidator = $this->createMock(CNAMERecordValidator::class);
        $cnameValidator->method('validateCnameExistence')
            ->willReturn(ValidationResult::success([]));

        $this->validatorRegistry->method('getValidator')
            ->willReturnMap([
                [RecordType::MX, $mxValidator],
                [RecordType::CNAME, $cnameValidator]
            ]);

        $this->dnsViolationValidator->method('validate')
            ->willReturn(ValidationResult::success([]));

        $this->dnsCommonValidator->expects($this->once())
            ->method('validateNonAliasTarget')
            ->with('mail.example.com')
            ->willReturn(ValidationResult::success([]));

        $result = $this->service->validateRecord(
            1,
            1,
            RecordType::MX,
            'mail.example.com',
            'example.com',
            10,
            3600,
            'hostmaster@example.com',
            86400
        );

        $this->assertTrue($result->isValid());
    }

    #[Test]
    public function testValidateRecordFailsWhenNsTargetIsAlias(): void
    {
        $this->zoneRepository->method('getDomainNameById')
            ->with(1)
            ->willReturn('example.com');

        $nsValidator = $this->createMock(DnsRecordValidatorInterface::class);
        $nsValidator->method('validate')
            ->willReturn(ValidationResult::success([
                'content' => 'ns1.example.com',
                'name' => 'example.com',
                'prio' => 0,
                'ttl' => 3600
            ]));

        $cnameValidator = $this->createMock(CNAMERecordValidator::class);
        $cnameValidator->method('validateCnameExistence')
            ->willReturn(ValidationResult::success([]));

        $this->validatorRegistry->method('getValidator')
            ->willReturnMap([
                [RecordType::NS, $nsValidator],
                [RecordType::CNAME, $cnameValidator]
            ]);

        $this->dnsViolationValidator->method('validate')
            ->willReturn(ValidationResult::success([]));

        $this->dnsCommonValidator->method('validateNonAliasTarget')
            ->willReturn(ValidationResult::failure('NS record target cannot be a CNAME'));

        $result = $this->service->validateRecord(
            1,
            1,
            RecordType::NS,
            'ns1.example.com',
            'example.com',
            null,
            3600,
            'hostmaster@example.com',
            86400
        );

        $this->assertFalse($result->isValid());
    }

    // ========== Other record types tests ==========

    #[Test]
    public function testValidateRecordDoesNotCheckNonAliasTargetForARecord(): void
    {
        $this->zoneRepository->method('getDomainNameById')
            ->with(1)
            ->willReturn('example.com');

        $aValidator = $this->createMock(DnsRecordValidatorInterface::class);
        $aValidator->method('validate')
            ->willReturn(ValidationResult::success([
                'content' => '192.168.1.1',
                'name' => 'test.example.com',
                'prio' => 0,
                'ttl' => 3600
            ]));

        $cnameValidator = $this->createMock(CNAMERecordValidator::class);
        $cnameValidator->method('validateCnameExistence')
            ->willReturn(ValidationResult::success([]));

        $this->validatorRegistry->method('getValidator')
            ->willReturnMap([
                ['A', $aValidator],
                [RecordType::CNAME, $cnameValidator]
            ]);

        $this->dnsViolationValidator->method('validate')
            ->willReturn(ValidationResult::success([]));

        // validateNonAliasTarget should NOT be called for A records
        $this->dnsCommonValidator->expects($this->never())
            ->method('validateNonAliasTarget');

        $result = $this->service->validateRecord(
            1,
            1,
            'A',
            '192.168.1.1',
            'test.example.com',
            null,
            3600,
            'hostmaster@example.com',
            86400
        );

        $this->assertTrue($result->isValid());
    }

    #[Test]
    public function testValidateRecordReturnsCorrectDataStructure(): void
    {
        $this->zoneRepository->method('getDomainNameById')
            ->with(1)
            ->willReturn('example.com');

        $aValidator = $this->createMock(DnsRecordValidatorInterface::class);
        $aValidator->method('validate')
            ->willReturn(ValidationResult::success([
                'content' => '10.0.0.1',
                'name' => 'web.example.com',
                'prio' => 0,
                'ttl' => 7200
            ]));

        $cnameValidator = $this->createMock(CNAMERecordValidator::class);
        $cnameValidator->method('validateCnameExistence')
            ->willReturn(ValidationResult::success([]));

        $this->validatorRegistry->method('getValidator')
            ->willReturnMap([
                ['A', $aValidator],
                [RecordType::CNAME, $cnameValidator]
            ]);

        $this->dnsViolationValidator->method('validate')
            ->willReturn(ValidationResult::success([]));

        $result = $this->service->validateRecord(
            5,
            1,
            'A',
            '10.0.0.1',
            'web.example.com',
            null,
            7200,
            'hostmaster@example.com',
            86400
        );

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertArrayHasKey('content', $data);
        $this->assertArrayHasKey('name', $data);
        $this->assertArrayHasKey('prio', $data);
        $this->assertArrayHasKey('ttl', $data);
        $this->assertEquals('10.0.0.1', $data['content']);
        $this->assertEquals('web.example.com', $data['name']);
        $this->assertEquals(0, $data['prio']);
        $this->assertEquals(7200, $data['ttl']);
    }
}
