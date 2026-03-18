<?php

namespace Poweradmin\Tests\Unit\Dns;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\RecordType;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Service\DnsValidation\DNSViolationValidator;

/**
 * Class DNSViolationValidatorTest
 */
#[CoversClass(DNSViolationValidator::class)]
class DNSViolationValidatorTest extends TestCase
{
    private MockObject&RecordRepositoryInterface $recordRepoMock;
    private DNSViolationValidator $validator;

    protected function setUp(): void
    {
        $this->recordRepoMock = $this->createMock(RecordRepositoryInterface::class);
        $this->validator = new DNSViolationValidator($this->recordRepoMock);
    }

    public function testValidRecordWithNoViolations()
    {
        // No CNAME records exist, so A record should be valid
        $this->recordRepoMock->method('getRecordsByDomainId')->willReturn([]);

        $result = $this->validator->validate(0, 1, RecordType::A, 'example.com', '192.168.1.1');
        $this->assertTrue($result->isValid());
    }

    public function testValidCNAMERecordWithNoViolations()
    {
        // No conflicting records exist
        $this->recordRepoMock->method('getRecordsByDomainId')->willReturn([]);

        $result = $this->validator->validate(0, 1, RecordType::CNAME, 'alias.example.com', 'target.example.com');
        $this->assertTrue($result->isValid());
    }

    public function testDuplicateCNAMERecord()
    {
        // Existing CNAME with same name
        $this->recordRepoMock->method('getRecordsByDomainId')
            ->willReturnCallback(function (int $zoneId, ?string $type = null) {
                if ($type === 'CNAME') {
                    return [['id' => 5, 'name' => 'alias.example.com', 'type' => 'CNAME', 'content' => 'other.example.com']];
                }
                return [];
            });

        $result = $this->validator->validate(-1, 1, RecordType::CNAME, 'alias.example.com', 'target.example.com');
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Multiple CNAME records with the same name are not allowed', $result->getFirstError());
    }

    public function testCNAMEConflictWithOtherTypes()
    {
        // No duplicate CNAMEs, but an A record exists with same name
        $this->recordRepoMock->method('getRecordsByDomainId')
            ->willReturnCallback(function (int $zoneId, ?string $type = null) {
                if ($type === 'CNAME') {
                    return [];
                }
                // All records in zone
                return [['id' => 10, 'name' => 'conflict.example.com', 'type' => 'A', 'content' => '1.2.3.4']];
            });

        $result = $this->validator->validate(-1, 1, RecordType::CNAME, 'conflict.example.com', 'target.example.com');
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('A CNAME record cannot coexist with other record types', $result->getFirstError());
    }

    public function testRecordConflictsWithExistingCNAME()
    {
        // Existing CNAME with same name
        $this->recordRepoMock->method('getRecordsByDomainId')
            ->willReturnCallback(function (int $zoneId, ?string $type = null) {
                if ($type === 'CNAME') {
                    return [['id' => 123, 'name' => 'conflict.example.com', 'type' => 'CNAME', 'content' => 'other.example.com']];
                }
                return [];
            });

        $result = $this->validator->validate(-1, 1, RecordType::A, 'conflict.example.com', '192.168.1.1');
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('conflicts with an existing CNAME record', $result->getFirstError());
    }
}
