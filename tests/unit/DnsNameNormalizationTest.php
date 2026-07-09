<?php

namespace Poweradmin\Tests\Unit;

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

    /**
     * A name that ends with the zone string but not on a dot boundary must be
     * treated as relative and get the zone suffix, otherwise it is stored as an
     * out-of-zone record PowerDNS never serves.
     */
    public function testNormalizeRecordNameRequiresDotBoundary()
    {
        // "testexample.com" is NOT inside "example.com"
        $this->assertEquals(
            "testexample.com.example.com",
            $this->validator->normalizeRecordName("testexample.com", "example.com")
        );

        // Case-insensitive variant of the same near-miss
        $this->assertEquals(
            "TESTEXAMPLE.COM.example.com",
            $this->validator->normalizeRecordName("TESTEXAMPLE.COM", "example.com")
        );

        // Genuine dot-boundary subdomain stays untouched
        $this->assertEquals(
            "test.example.com",
            $this->validator->normalizeRecordName("test.example.com", "example.com")
        );

        // Apex (name equals zone) stays untouched
        $this->assertEquals(
            "example.com",
            $this->validator->normalizeRecordName("example.com", "example.com")
        );
    }

    /**
     * A trailing dot marks an absolute name, but it must still be qualified
     * within the selected zone (not stored outside it once the dot is stripped).
     * In the DNS root zone (".") the qualified name keeps its single trailing dot.
     */
    public function testNormalizeRecordNameHandlesAbsoluteNames()
    {
        // Absolute relative-name gets the zone suffix (dot stripped), not left outside it
        $this->assertEquals(
            "host.example.com",
            $this->validator->normalizeRecordName("host.", "example.com")
        );

        // Absolute name already inside the zone keeps its (dotless) form
        $this->assertEquals(
            "mail.example.com",
            $this->validator->normalizeRecordName("mail.example.com.", "example.com")
        );

        // Root zone: a relative name is qualified with a single trailing dot
        $this->assertEquals(
            "com.",
            $this->validator->normalizeRecordName("com", ".")
        );

        // Root zone apex (empty name) is the root itself, not an empty string
        $this->assertEquals(
            ".",
            $this->validator->normalizeRecordName("", ".")
        );

        // Root zone: an already-qualified name keeps its single trailing dot
        $this->assertEquals(
            "com.",
            $this->validator->normalizeRecordName("com.", ".")
        );

        // Only one trailing dot is stripped: "host.." keeps an empty label so it
        // still fails hostname validation downstream instead of being sanitized.
        $this->assertEquals(
            "host..example.com",
            $this->validator->normalizeRecordName("host..", "example.com")
        );
    }
}
