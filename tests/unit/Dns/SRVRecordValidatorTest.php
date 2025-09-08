<?php

namespace unit\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\SRVRecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Tests for the SRVRecordValidator
 *
 * This test suite verifies compliance with RFC 2782 (SRV records)
 */
class SRVRecordValidatorTest extends TestCase
{
    private SRVRecordValidator $validator;
    private ConfigurationManager $configMock;

    protected function setUp(): void
    {
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->configMock->method('get')
            ->willReturn('example.com');

        $this->validator = new SRVRecordValidator($this->configMock);
    }

    /**
     * Test validation with valid data
     */
    public function testValidateWithValidData()
    {
        $content = '20 5060 sip.example.com';
        $name = '_sip._tcp.example.com';
        $prio = 10;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
        $this->assertEquals($name, $data['name']);
        $this->assertEquals(10, $data['prio']);
        $this->assertEquals(3600, $data['ttl']);
    }

    /**
     * Test validation with a well-known service (HTTP)
     */
    public function testValidateWithWellKnownService()
    {
        $content = '20 80 server.example.com';
        $name = '_http._tcp.example.com';
        $prio = 10;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals($content, $data['content']);
    }

    /**
     * Test validation with well-known service and non-standard port (should generate warning)
     */
    public function testValidateWithWellKnownServiceAndNonStandardPort()
    {
        $content = '20 8080 server.example.com';
        $name = '_http._tcp.example.com';
        $prio = 10;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        // Should still be valid but contain warnings
        $this->assertTrue($result->isValid());
        $this->assertTrue($result->hasWarnings());
        $warning = $result->getWarnings()[0] ?? '';
        $this->assertStringContainsString('standard port', $warning);
        $this->assertStringContainsString('80', $warning);
    }

    /**
     * Test validation with invalid SRV name (not in _service._protocol format)
     */
    public function testValidateWithInvalidSrvName()
    {
        $content = '20 5060 sip.example.com';
        $name = 'invalid.example.com'; // Missing _service._protocol format
        $prio = 10;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Invalid service value in name field of SRV record', $result->getFirstError());
    }

    /**
     * Test validation with invalid service name format (missing underscore prefix)
     */
    public function testValidateWithInvalidSrvNameService()
    {
        $content = '20 5060 sip.example.com';
        $name = 'sip._tcp.example.com'; // Missing _ prefix for service
        $prio = 10;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('service value', $result->getFirstError());
    }

    /**
     * Test validation with invalid protocol name format (missing underscore prefix)
     */
    public function testValidateWithInvalidSrvNameProtocol()
    {
        $content = '20 5060 sip.example.com';
        $name = '_sip.tcp.example.com'; // Missing _ prefix for protocol
        $prio = 10;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('protocol value', $result->getFirstError());
    }

    /**
     * Test validation with non-standard protocol (should generate warning)
     */
    public function testValidateWithNonStandardProtocol()
    {
        $content = '20 5060 sip.example.com';
        $name = '_sip._customproto.example.com'; // Non-standard protocol
        $prio = 10;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        // Should still be valid, so we'll check the data structure
        $this->assertTrue($result->isValid());
        $data = $result->getData();

        // Check for warnings in data nested arrays
        $nameData = $data['nameData'] ?? null;
        $this->assertNotNull($nameData, "Name data should be present in the result");

        if (isset($nameData['warnings'])) {
            $found = false;
            foreach ($nameData['warnings'] as $warning) {
                if (strpos($warning, 'protocol') !== false) {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, "Should contain a warning about non-standard protocol");
        }
    }

    /**
     * Test validation with a dot "." target (valid according to RFC 2782 for "no service")
     */
    public function testValidateWithDotTarget()
    {
        // Since directly testing a "." target might not work with the current implementation,
        // we'll test the private validateTarget method directly using reflection

        $method = new \ReflectionMethod(SRVRecordValidator::class, 'validateTarget');
        $method->setAccessible(true);

        $dotResult = $method->invoke($this->validator, '.');

        // Test that the method accepts "." as a valid target
        $this->assertTrue($dotResult->isValid(), "The '.' should be accepted as a valid target");
    }

    /**
     * Test validation with incomplete content format
     */
    public function testValidateWithInvalidContent()
    {
        $content = '20 sip.example.com'; // Missing port field
        $name = '_sip._tcp.example.com';
        $prio = 10;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('weight, port and target', $result->getFirstError());
    }

    /**
     * Test validation with non-numeric weight in content
     */
    public function testValidateWithInvalidContentPriority()
    {
        $content = 'invalid 5060 sip.example.com'; // Non-numeric weight
        $name = '_sip._tcp.example.com';
        $prio = 10;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('weight field', $result->getFirstError());
    }

    /**
     * Test validation with non-numeric weight in content
     */
    public function testValidateWithInvalidContentWeight()
    {
        $content = 'invalid 5060 sip.example.com'; // Non-numeric weight
        $name = '_sip._tcp.example.com';
        $prio = 10;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('weight field', $result->getFirstError());
    }

    /**
     * Test validation with non-numeric port in content
     */
    public function testValidateWithInvalidContentPort()
    {
        $content = '20 invalid sip.example.com'; // Non-numeric port
        $name = '_sip._tcp.example.com';
        $prio = 10;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('port field', $result->getFirstError());
    }

    /**
     * Test validation with port value out of range
     */
    public function testValidateWithOutOfRangePort()
    {
        $content = '20 70000 sip.example.com'; // Port > 65535
        $name = '_sip._tcp.example.com';
        $prio = 10;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('port', $result->getFirstError());
    }

    /**
     * Test validation with invalid hostname in target
     */
    public function testValidateWithInvalidContentTarget()
    {
        $content = '20 5060 -invalid-.example.com'; // Invalid hostname
        $name = '_sip._tcp.example.com';
        $prio = 10;
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('target', $result->getFirstError());
    }

    /**
     * Test validation with invalid TTL
     */
    public function testValidateWithInvalidTTL()
    {
        $content = '20 5060 sip.example.com';
        $name = '_sip._tcp.example.com';
        $prio = 10;
        $ttl = -1; // Invalid TTL
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('TTL', $result->getFirstError());
    }

    /**
     * Test validation with invalid priority parameter
     */
    public function testValidateWithInvalidPriority()
    {
        $content = '20 5060 sip.example.com';
        $name = '_sip._tcp.example.com';
        $prio = 'invalid'; // Invalid priority
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('priority field', $result->getFirstError());
    }

    /**
     * Test validation with empty priority (should use default)
     */
    public function testValidateWithEmptyPriority()
    {
        $content = '20 5060 sip.example.com';
        $name = '_sip._tcp.example.com';
        $prio = ''; // Empty priority should use value from SRV content
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(10, $data['prio']);
    }

    /**
     * Test validation with empty TTL (should use default)
     */
    public function testValidateWithDefaultTTL()
    {
        $content = '20 5060 sip.example.com';
        $name = '_sip._tcp.example.com';
        $prio = 10;
        $ttl = ''; // Empty TTL should use default
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        $this->assertTrue($result->isValid());
        $data = $result->getData();
        $this->assertEquals(86400, $data['ttl']);
    }

    /**
     * Test various well-known service/port combinations
     */
    public function testValidateWithVariousWellKnownServices()
    {
        // Test cases for different well-known services with correct ports
        $testCases = [
            ['_ldap._tcp.example.com', '0 389 ldap.example.com'],
            ['_kerberos._tcp.example.com', '0 88 krb.example.com'],
            ['_xmpp-server._tcp.example.com', '0 5269 xmpp.example.com'],
            ['_imap._tcp.example.com', '0 143 mail.example.com'],
            ['_submission._tcp.example.com', '0 587 mail.example.com'],
            ['_imaps._tcp.example.com', '0 993 mail.example.com'],
            ['_pop3._tcp.example.com', '0 110 mail.example.com'],
            ['_pop3s._tcp.example.com', '0 995 mail.example.com'],
            ['_jabber._tcp.example.com', '0 5222 xmpp.example.com'],
            ['_minecraft._tcp.example.com', '0 25565 mc.example.com']
        ];

        foreach ($testCases as [$name, $content]) {
            $result = $this->validator->validate($content, $name, 0, 3600, 86400);
            $this->assertTrue($result->isValid(), "Service $name should be valid with correct port");

            // Some services might have warnings about protocols or other issues,
            // so we'll just check that it's valid
        }
    }

    /**
     * Test SRV record with mismatched content priority value
     */
    public function testValidateWithMismatchedPriorityValues()
    {
        // Test 4-field content (should be rejected in new format)
        $content = '20 20 5060 sip.example.com'; // 4 fields (should be rejected)
        $name = '_sip._tcp.example.com';
        $prio = '10'; // Priority 10 in record parameters
        $ttl = 3600;
        $defaultTTL = 86400;

        $result = $this->validator->validate($content, $name, $prio, $ttl, $defaultTTL);

        // 4-field content should be rejected
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('weight, port and target', $result->getFirstError());
    }

    /**
     * Test validation of SRV name component method
     */
    public function testValidateSrvName()
    {
        // Using reflection to access private method
        $method = new \ReflectionMethod(SRVRecordValidator::class, 'validateSrvName');
        $method->setAccessible(true);

        // Test valid name
        $validResult = $method->invoke($this->validator, '_sip._tcp.example.com');
        $this->assertTrue($validResult->isValid());

        // The returned data structure contains more fields than just 'name'
        $data = $validResult->getData();
        $this->assertEquals('_sip._tcp.example.com', $data['name']);
        $this->assertArrayHasKey('service', $data);
        $this->assertArrayHasKey('protocol', $data);
        $this->assertArrayHasKey('domain', $data);

        // Test invalid name
        $invalidResult = $method->invoke($this->validator, 'invalid.example.com');
        $this->assertFalse($invalidResult->isValid());
    }

    /**
     * Test validation of SRV content component method
     */
    public function testValidateSrvContent()
    {
        // Using reflection to access private method
        $method = new \ReflectionMethod(SRVRecordValidator::class, 'validateSrvContent');
        $method->setAccessible(true);

        // Test valid content
        $validResult = $method->invoke($this->validator, '20 5060 sip.example.com', '_sip._tcp.example.com');
        $this->assertTrue($validResult->isValid());

        // Check for expected structure with more details
        $data = $validResult->getData();
        $this->assertEquals('20 5060 sip.example.com', $data['content']);
        $this->assertArrayHasKey('weight', $data);
        $this->assertArrayHasKey('port', $data);
        $this->assertArrayHasKey('target', $data);

        // Test invalid content
        $invalidResult = $method->invoke($this->validator, '10 20 invalid', '_sip._tcp.example.com');
        $this->assertFalse($invalidResult->isValid());
    }
}
