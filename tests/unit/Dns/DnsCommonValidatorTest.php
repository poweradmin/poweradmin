<?php

namespace unit\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\DnsCommonValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOLayer;
use Poweradmin\Domain\Service\Validation\ValidationResult;

/**
 * Tests for common DNS validation functions
 */
class DnsCommonValidatorTest extends TestCase
{
    private DnsCommonValidator $validator;
    private PDOLayer $dbMock;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->dbMock = $this->createMock(PDOLayer::class);
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->validator = new DnsCommonValidator($this->dbMock, $this->configMock);
    }

    /**
     * Test priority validation with ValidationResult pattern
     */
    public function testValidatePriorityWithMxRecords()
    {
        // Test default values for MX records
        $result1 = $this->validator->validatePriority(null, "MX");
        $this->assertTrue($result1->isValid());
        $this->assertEquals(10, $result1->getData());

        $result2 = $this->validator->validatePriority("", "MX");
        $this->assertTrue($result2->isValid());
        $this->assertEquals(10, $result2->getData());

        // Test valid MX priorities
        $result3 = $this->validator->validatePriority(0, "MX");
        $this->assertTrue($result3->isValid());
        $this->assertEquals(0, $result3->getData());

        $result4 = $this->validator->validatePriority(10, "MX");
        $this->assertTrue($result4->isValid());
        $this->assertEquals(10, $result4->getData());

        $result5 = $this->validator->validatePriority(65535, "MX");
        $this->assertTrue($result5->isValid());
        $this->assertEquals(65535, $result5->getData());

        // Test invalid priorities for MX records
        $result6 = $this->validator->validatePriority(-1, "MX");
        $this->assertFalse($result6->isValid());
        $this->assertNotEmpty($result6->getErrors());

        $result7 = $this->validator->validatePriority(65536, "MX");
        $this->assertFalse($result7->isValid());

        $result8 = $this->validator->validatePriority("invalid", "MX");
        $this->assertFalse($result8->isValid());
    }

    public function testValidatePriorityWithSrvRecords()
    {
        // Test default values and valid priorities for SRV records
        $result1 = $this->validator->validatePriority(null, "SRV");
        $this->assertTrue($result1->isValid());
        $this->assertEquals(10, $result1->getData());

        $result2 = $this->validator->validatePriority(0, "SRV");
        $this->assertTrue($result2->isValid());
        $this->assertEquals(0, $result2->getData());

        $result3 = $this->validator->validatePriority(65535, "SRV");
        $this->assertTrue($result3->isValid());
        $this->assertEquals(65535, $result3->getData());
    }

    public function testValidatePriorityWithNonPriorityRecords()
    {
        // Test non-MX/SRV records - should always return 0 regardless of input
        $result1 = $this->validator->validatePriority(null, "A");
        $this->assertTrue($result1->isValid());
        $this->assertEquals(0, $result1->getData());

        $result2 = $this->validator->validatePriority("", "A");
        $this->assertTrue($result2->isValid());
        $this->assertEquals(0, $result2->getData());

        $result3 = $this->validator->validatePriority(10, "A");
        $this->assertTrue($result3->isValid());
        $this->assertEquals(0, $result3->getData());

        $result4 = $this->validator->validatePriority(100, "AAAA");
        $this->assertTrue($result4->isValid());
        $this->assertEquals(0, $result4->getData());

        $result5 = $this->validator->validatePriority("invalid", "TXT");
        $this->assertTrue($result5->isValid());
        $this->assertEquals(0, $result5->getData());
    }

    /**
     * Test non-alias target validation with ValidationResult pattern
     */
    public function testValidateNonAliasTargetWithNoCname()
    {
        // Configure mock for database name
        $this->configMock->method('get')
            ->willReturn('pdns');

        // Configure mock for query results
        $this->dbMock->method('quote')
            ->willReturnCallback(function ($value, $type) {
                if ($type === 'text') {
                    return "'$value'";
                }
                return $value;
            });

        $this->dbMock->expects($this->once())
            ->method('queryOne')
            ->willReturn(false);

        $result = $this->validator->validateNonAliasTarget("example.com");
        $this->assertTrue($result->isValid());
        $this->assertTrue($result->getData());
    }

    public function testValidateNonAliasTargetWithCname()
    {
        // Configure mock for database name
        $this->configMock->method('get')
            ->willReturn('pdns');

        // Configure mock for query results
        $this->dbMock->method('quote')
            ->willReturnCallback(function ($value, $type) {
                if ($type === 'text') {
                    return "'$value'";
                }
                return $value;
            });

        $this->dbMock->expects($this->once())
            ->method('queryOne')
            ->willReturn(['id' => 1]);

        $result = $this->validator->validateNonAliasTarget("has.cname.example.com");
        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->getErrors());
        $this->assertStringContainsString('You can not point a NS or MX record to a CNAME record', $result->getFirstError());
    }
}
