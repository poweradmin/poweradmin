<?php

namespace unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\RdapService;
use ReflectionClass;
use ReflectionMethod;

class RdapServiceTest extends TestCase
{
    private $testDataFile;
    private $rdapService;

    protected function setUp(): void
    {
        // Create a temporary test data file with sample RDAP servers
        $this->testDataFile = sys_get_temp_dir() . '/rdap_servers_test.json';
        $testData = [
            'com' => 'https://rdap.verisign.com/com/v1/',
            'net' => 'https://rdap.verisign.com/net/v1/',
            'org' => 'https://rdap.identitydigital.services/rdap/',
            'example' => 'https://example.rdap.server/',
            'test' => 'http://test.rdap.server:8080/',
        ];
        file_put_contents($this->testDataFile, json_encode($testData));

        // Initialize the service with the test data
        $this->rdapService = new RdapService($this->testDataFile);
    }

    protected function tearDown(): void
    {
        // Clean up the test file
        if (file_exists($this->testDataFile)) {
            unlink($this->testDataFile);
        }
    }

    /**
     * Get access to private isValidRdapUrl method for testing
     */
    private function getValidationMethod(): ReflectionMethod
    {
        $reflection = new ReflectionClass($this->rdapService);
        $method = $reflection->getMethod('isValidRdapUrl');
        $method->setAccessible(true);
        return $method;
    }

    public function testValidRdapUrls()
    {
        $method = $this->getValidationMethod();

        // Test valid URLs that should pass validation
        $validUrls = [
            'https://rdap.verisign.com/com/v1/domain/example.com',
            'https://rdap.verisign.com/net/v1/domain/test.net',
            'https://rdap.identitydigital.services/rdap/domain/example.org',
            'https://example.rdap.server/domain/test.example',
            'http://test.rdap.server:8080/domain/example.test',
        ];

        foreach ($validUrls as $url) {
            $this->assertTrue(
                $method->invoke($this->rdapService, $url),
                "URL should be valid: $url"
            );
        }
    }

    public function testInvalidRdapUrls()
    {
        $method = $this->getValidationMethod();

        // Test invalid URLs that should fail validation
        $invalidUrls = [
            // Path traversal attempts
            'https://rdap.verisign.com/com/v1/../../../etc/passwd',
            'https://rdap.verisign.com/com/v1/domain/../../secrets',
            'https://rdap.identitydigital.services/rdap/../admin/config',

            // Invalid schemes
            'ftp://rdap.verisign.com/com/v1/domain/example.com',
            'file:///etc/passwd',
            'javascript:alert(1)',

            // Malformed URLs
            'not-a-url',
            'http://',
            'https://',
            '',

            // Path traversal with backslashes
            'https://rdap.verisign.com/com/v1\\..\\..\\etc\\passwd',
        ];

        foreach ($invalidUrls as $url) {
            $this->assertFalse(
                $method->invoke($this->rdapService, $url),
                "URL should be invalid: $url"
            );
        }
    }

    public function testValidUrlsWithDifferentServers()
    {
        $method = $this->getValidationMethod();

        // Test that validation now accepts URLs from any server (not just known ones)
        $validUrls = [
            'https://unknown-server.com/domain/example.com',
            'https://malicious.server/domain/test.com',  // Would pass basic validation but fail on purpose
            'http://any-server.org:8080/rdap/v1/query?type=domain',
        ];

        foreach ($validUrls as $url) {
            $this->assertTrue(
                $method->invoke($this->rdapService, $url),
                "URL should be valid (no server restriction): $url"
            );
        }
    }

    public function testRdapServerRetrieval()
    {
        // Test basic server retrieval
        $this->assertEquals('https://rdap.verisign.com/com/v1/', $this->rdapService->getRdapServer('com'));
        $this->assertEquals('https://rdap.identitydigital.services/rdap/', $this->rdapService->getRdapServer('org'));
        $this->assertNull($this->rdapService->getRdapServer('nonexistent'));
    }

    public function testRdapServerForDomain()
    {
        // Test domain to server mapping
        $this->assertEquals('https://rdap.verisign.com/com/v1/', $this->rdapService->getRdapServerForDomain('example.com'));
        $this->assertEquals('https://rdap.identitydigital.services/rdap/', $this->rdapService->getRdapServerForDomain('test.org'));
        $this->assertNull($this->rdapService->getRdapServerForDomain('invalid'));
    }

    public function testSecurityAgainstPathTraversal()
    {
        $method = $this->getValidationMethod();

        // These should all be rejected as they could be used for path traversal
        $pathTraversalAttempts = [
            'https://rdap.verisign.com/com/v1/domain/../../../etc/passwd',
            'https://rdap.verisign.com/com/v1/domain/..%2F..%2F..%2Fetc%2Fpasswd',
            'https://rdap.verisign.com/com/v1/domain/....//....//etc/passwd',
            'https://rdap.verisign.com/com/v1/domain/\..\..\windows\system32\config\sam',
        ];

        foreach ($pathTraversalAttempts as $maliciousUrl) {
            $this->assertFalse(
                $method->invoke($this->rdapService, $maliciousUrl),
                "Path traversal attempt should be blocked: $maliciousUrl"
            );
        }
    }

    public function testCaseSensitivityHandling()
    {
        // Test case insensitive TLD handling
        $this->assertEquals('https://rdap.verisign.com/com/v1/', $this->rdapService->getRdapServer('COM'));
        $this->assertEquals('https://rdap.verisign.com/com/v1/', $this->rdapService->getRdapServer('Com'));
        $this->assertEquals('https://rdap.verisign.com/com/v1/', $this->rdapService->getRdapServerForDomain('Example.COM'));
    }

    public function testUrlConstructionInQuery()
    {
        // Test that query method constructs URLs that pass validation
        $method = $this->getValidationMethod();

        // Simulate URL construction as done in query method
        $serverUrl = 'https://any-server.com/rdap/';
        $domain = 'example.com';
        $constructedUrl = rtrim($serverUrl, '/') . '/' . 'domain/' . urlencode($domain);

        // This constructed URL should pass validation
        $this->assertTrue(
            $method->invoke($this->rdapService, $constructedUrl),
            "Query-constructed URL should be valid: $constructedUrl"
        );
    }

    public function testSetRequestTimeout()
    {
        // Use reflection to test private property
        $reflectionClass = new \ReflectionClass($this->rdapService);
        $property = $reflectionClass->getProperty('requestTimeout');
        $property->setAccessible(true);

        // Default value should be 10
        $this->assertEquals(10, $property->getValue($this->rdapService));

        // Set to a new value
        $this->rdapService->setRequestTimeout(15);
        $this->assertEquals(15, $property->getValue($this->rdapService));

        // Test minimum value enforcement
        $this->rdapService->setRequestTimeout(0);
        $this->assertEquals(1, $property->getValue($this->rdapService));

        $this->rdapService->setRequestTimeout(-5);
        $this->assertEquals(1, $property->getValue($this->rdapService));
    }

    public function testMissingDataFile()
    {
        // Test with non-existent file
        $rdapService = new RdapService('/path/to/nonexistent/file.json');

        // Methods should handle missing data gracefully
        $this->assertNull($rdapService->getRdapServer('com'));
        $this->assertFalse($rdapService->hasTld('com'));
        $this->assertEmpty($rdapService->getAllRdapServers());
    }

    public function testRefresh()
    {
        // Initial state
        $this->assertEquals('https://rdap.verisign.com/com/v1/', $this->rdapService->getRdapServer('com'));

        // Modify the test data file
        $modifiedData = [
            'com' => 'https://modified.rdap.server.com/',
            'net' => 'https://rdap.verisign.com/net/v1/'
        ];
        file_put_contents($this->testDataFile, json_encode($modifiedData));

        // Refresh and verify changes
        $this->assertTrue($this->rdapService->refresh());
        $this->assertEquals('https://modified.rdap.server.com/', $this->rdapService->getRdapServer('com'));
        $this->assertNull($this->rdapService->getRdapServer('org')); // Should be gone after refresh
    }

    public function testGetRdapInfoWithNoServer()
    {
        // Create service with empty data
        $emptyFile = sys_get_temp_dir() . '/empty_rdap_test.json';
        file_put_contents($emptyFile, json_encode([]));
        $emptyService = new RdapService($emptyFile);

        // Test getRdapInfo with no server available
        $result = $emptyService->getRdapInfo('example.nonexistent');

        $this->assertFalse($result['success']);
        $this->assertNull($result['data']);
        $this->assertEquals('No RDAP server found for this domain', $result['error']);

        unlink($emptyFile);
    }

    public function testFormatRdapResponse()
    {
        $testResponse = [
            'objectClassName' => 'domain',
            'handle' => 'EXAMPLE-COM',
            'ldhName' => 'example.com',
            'unicodeName' => 'example.com',
            'status' => ['client transfer prohibited', 'client update prohibited'],
            'events' => [
                ['eventAction' => 'registration', 'eventDate' => '1995-08-14T04:00:00Z'],
                ['eventAction' => 'expiration', 'eventDate' => '2023-08-13T04:00:00Z']
            ]
        ];

        $formattedResponse = $this->rdapService->formatRdapResponse($testResponse);

        // Should be properly formatted JSON
        $decodedResponse = json_decode($formattedResponse, true);
        $this->assertEquals($testResponse, $decodedResponse);

        // Check formatting options are applied
        $this->assertStringContainsString('    ', $formattedResponse); // Indentation
        $this->assertStringNotContainsString('\/', $formattedResponse); // Unescaped slashes
    }

    public function testWhitespaceHandling()
    {
        // Test whitespace handling in domain lookups
        $this->assertEquals('https://rdap.verisign.com/com/v1/', $this->rdapService->getRdapServerForDomain(' example.com '));
        $this->assertEquals('https://rdap.verisign.com/com/v1/', $this->rdapService->getRdapServer(' COM '));
        $this->assertTrue($this->rdapService->hasTld(' com '));
    }

    public function testSubdomainHandling()
    {
        // Test that subdomains resolve to correct TLD servers
        $this->assertEquals('https://rdap.verisign.com/com/v1/', $this->rdapService->getRdapServerForDomain('sub.example.com'));
        $this->assertEquals('https://rdap.identitydigital.services/rdap/', $this->rdapService->getRdapServerForDomain('deep.sub.example.org'));
    }

    public function testInvalidDomainInput()
    {
        // Test invalid domain formats
        $this->assertNull($this->rdapService->getRdapServerForDomain('invalid'));
        $this->assertNull($this->rdapService->getRdapServerForDomain(''));
        $this->assertNull($this->rdapService->getRdapServerForDomain('example.nonexistent'));
    }
}
