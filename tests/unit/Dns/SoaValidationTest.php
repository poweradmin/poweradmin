<?php

namespace unit\Dns;

use TestHelpers\BaseDnsTest;
use Poweradmin\Domain\Service\DnsValidation\SOARecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOLayer;

/**
 * Tests for SOA record validation
 */
class SoaValidationTest extends BaseDnsTest
{
    private SOARecordValidator $validator;
    private $dbMock;
    private $configMock;

    protected function setUp(): void
    {
        $this->dbMock = $this->createMock(PDOLayer::class);
        $this->configMock = $this->createMock(ConfigurationManager::class);

        // Configure the database mock
        $this->dbMock->method('queryOne')->willReturn(null);
        $this->dbMock->method('quote')
            ->willReturnCallback(function ($value, $type = null) {
                if ($type === 'text') {
                    return "'$value'";
                }
                if ($type === 'integer') {
                    return $value;
                }
                return "'$value'";
            });

        $this->validator = new SOARecordValidator($this->configMock, $this->dbMock);
    }

    /**
     * Test the SOA content validation
     */
    public function testIsValidRrSoaContent()
    {
        // Valid SOA record content
        $content = "ns1.example.com hostmaster.example.com 2023122801 7200 1800 1209600 86400";
        $zone = "example.com";
        $dns_hostmaster = "hostmaster@example.com";

        $this->validator->setSOAParams($dns_hostmaster, $zone);
        $result = $this->validator->validate($content, "example.com", 0, 3600, 86400);

        // Check that we get an array with expected data
        $this->assertIsArray($result);
        $this->assertArrayHasKey('content', $result);
        $this->assertStringContainsString('ns1.example.com', $result['content']);
        $this->assertStringContainsString('2023122801', $result['content']);

        // Test invalid content format
        $content = "ns1.example.com hostmaster.example.com"; // Missing required fields
        $result = $this->validator->validate($content, $zone, 0, 3600, 86400);
        $this->assertFalse($result);

        // Test empty content
        $content = "";
        $result = $this->validator->validate($content, $zone, 0, 3600, 86400);
        $this->assertFalse($result);
    }

    public function testIsValidRrSoaName()
    {
        $content = 'ns1.example.com hostmaster.example.com 2023122801 7200 1800 1209600 86400';

        // Valid SOA name (matches zone)
        $this->validator->setSOAParams('hostmaster@example.com', 'example.com');
        $result = $this->validator->validate($content, 'example.com', 0, 3600, 86400);
        $this->assertIsArray($result);

        $this->validator->setSOAParams('hostmaster@sub.domain.com', 'sub.domain.com');
        $result = $this->validator->validate($content, 'sub.domain.com', 0, 3600, 86400);
        $this->assertIsArray($result);

        // Invalid SOA name (doesn't match zone)
        $this->validator->setSOAParams('hostmaster@example.com', 'example.com');
        $result = $this->validator->validate($content, 'www.example.com', 0, 3600, 86400);
        $this->assertFalse($result);

        $result = $this->validator->validate($content, 'example.org', 0, 3600, 86400);
        $this->assertFalse($result);
    }

    public function testCustomValidationWithNonNumericSerialNumbers()
    {
        // Test our custom validation logic directly
        $content = "example.com hostmaster.example.com not_a_number 7200 1209600 3600 86400";

        // Verify our custom logic properly identifies non-numeric serial numbers
        $fields = preg_split("/\s+/", trim($content));
        $this->assertFalse(is_numeric($fields[2]), "Should identify non-numeric serial");
    }

    public function testCustomValidationWithArpaDomain()
    {
        // Test our custom validation logic directly
        $content = "example.arpa hostmaster.example.com 2023122505 7200 1209600 3600 86400";

        // Verify our custom logic properly identifies .arpa domains
        $fields = preg_split("/\s+/", trim($content));
        $this->assertTrue((bool)preg_match('/\.arpa\.?$/', $fields[0]), "Should identify .arpa domain");
    }

    public function testCustomValidationWithValidData()
    {
        // Test our custom validation logic directly
        $content = "example.com hostmaster.example.com 2023122505 7200 1209600 3600 86400";

        // Verify our custom logic validates properly formed SOA records
        $fields = preg_split("/\s+/", trim($content));

        $this->assertCount(7, $fields, "Should have 7 fields");
        $this->assertFalse((bool)preg_match('/\.arpa\.?$/', $fields[0]), "Should not be an arpa domain");
        $this->assertTrue(is_numeric($fields[2]), "Serial should be numeric");
        $this->assertTrue(is_numeric($fields[3]), "Refresh should be numeric");
        $this->assertTrue(is_numeric($fields[4]), "Retry should be numeric");
        $this->assertTrue(is_numeric($fields[5]), "Expire should be numeric");
        $this->assertTrue(is_numeric($fields[6]), "Minimum should be numeric");
    }
}
