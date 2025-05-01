<?php

namespace unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Tests for DNS name normalization
 */
class DnsNameNormalizationTest extends TestCase
{
    private HostnameValidator $validator;

    protected function setUp(): void
    {
        $configMock = $this->createMock(ConfigurationManager::class);
        $this->validator = new HostnameValidator($configMock);
    }

    /**
     * Test the basic functionality of name normalization
     */
    public function testNormalizeRecordName()
    {
        // Test case 1: Name without zone suffix
        $name = "www";
        $zone = "example.com";
        $expected = "www.example.com";
        $this->assertEquals($expected, $this->validator->normalizeRecordName($name, $zone));

        // Test case 2: Name already has zone suffix
        $name = "mail.example.com";
        $zone = "example.com";
        $expected = "mail.example.com";
        $this->assertEquals($expected, $this->validator->normalizeRecordName($name, $zone));

        // Test case 3: Empty name should return zone
        $name = "";
        $zone = "example.com";
        $expected = "example.com";
        $this->assertEquals($expected, $this->validator->normalizeRecordName($name, $zone));

        // Test case 4: Case-insensitive matching
        $name = "SUB.EXAMPLE.COM";
        $zone = "example.com";
        $expected = "SUB.EXAMPLE.COM";
        $this->assertEquals($expected, $this->validator->normalizeRecordName($name, $zone));

        // Test case 5: Name is @ sign
        $name = "@";
        $zone = "example.com";
        $expected = "@.example.com";
        $this->assertEquals($expected, $this->validator->normalizeRecordName($name, $zone));

        // Test case 6: Subdomain of zone
        $name = "test.sub";
        $zone = "example.com";
        $expected = "test.sub.example.com";
        $this->assertEquals($expected, $this->validator->normalizeRecordName($name, $zone));
    }

    /**
     * Test name normalization with edge cases
     */
    public function testNormalizeRecordNameEdgeCases()
    {
        // Test with name containing zone as substring but not at the end
        $name = "example.com.test";
        $zone = "example.com";
        $expected = "example.com.test.example.com";
        $this->assertEquals($expected, $this->validator->normalizeRecordName($name, $zone));

        // Test with zone being a subdomain itself
        $name = "www";
        $zone = "sub.example.com";
        $expected = "www.sub.example.com";
        $this->assertEquals($expected, $this->validator->normalizeRecordName($name, $zone));

        // Test with name already being a subdomain of the zone
        $name = "www.sub.example.com";
        $zone = "sub.example.com";
        $expected = "www.sub.example.com";
        $this->assertEquals($expected, $this->validator->normalizeRecordName($name, $zone));
    }
}
