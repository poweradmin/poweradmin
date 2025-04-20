<?php

namespace unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\WhoisService;

class WhoisServiceTest extends TestCase
{
    private $testDataFile;
    private $whoisService;

    protected function setUp(): void
    {
        // Create a temporary test data file
        $this->testDataFile = sys_get_temp_dir() . '/whois_servers_test.json';
        $testData = [
            'com' => 'whois.verisign-grs.com',
            'net' => 'whois.verisign-grs.com',
            'org' => 'whois.pir.org',
            'co.uk' => 'whois.nic.uk',
            'uk.com' => 'whois.centralnic.com'
        ];
        file_put_contents($this->testDataFile, json_encode($testData));

        // Initialize the service with the test data
        $this->whoisService = new WhoisService($this->testDataFile);
    }

    protected function tearDown(): void
    {
        // Clean up the test file
        if (file_exists($this->testDataFile)) {
            unlink($this->testDataFile);
        }
    }

    public function testGetWhoisServer()
    {
        // Test simple TLDs
        $this->assertEquals('whois.verisign-grs.com', $this->whoisService->getWhoisServer('com'));
        $this->assertEquals('whois.verisign-grs.com', $this->whoisService->getWhoisServer('net'));
        $this->assertEquals('whois.pir.org', $this->whoisService->getWhoisServer('org'));

        // Test compound TLDs
        $this->assertEquals('whois.nic.uk', $this->whoisService->getWhoisServer('co.uk'));
        $this->assertEquals('whois.centralnic.com', $this->whoisService->getWhoisServer('uk.com'));

        // Test case insensitivity
        $this->assertEquals('whois.verisign-grs.com', $this->whoisService->getWhoisServer('COM'));
        $this->assertEquals('whois.nic.uk', $this->whoisService->getWhoisServer('Co.Uk'));

        // Test whitespace handling
        $this->assertEquals('whois.verisign-grs.com', $this->whoisService->getWhoisServer(' com '));

        // Test non-existent TLD
        $this->assertNull($this->whoisService->getWhoisServer('nonexistent'));
    }

    public function testGetWhoisServerForDomain()
    {
        // Test simple domains
        $this->assertEquals('whois.verisign-grs.com', $this->whoisService->getWhoisServerForDomain('example.com'));
        $this->assertEquals('whois.verisign-grs.com', $this->whoisService->getWhoisServerForDomain('test.net'));
        $this->assertEquals('whois.pir.org', $this->whoisService->getWhoisServerForDomain('nonprofit.org'));

        // Test subdomains
        $this->assertEquals('whois.verisign-grs.com', $this->whoisService->getWhoisServerForDomain('sub.example.com'));

        // Test multi-level TLDs
        $this->assertEquals('whois.nic.uk', $this->whoisService->getWhoisServerForDomain('example.co.uk'));
        $this->assertEquals('whois.centralnic.com', $this->whoisService->getWhoisServerForDomain('example.uk.com'));

        // Test case insensitivity
        $this->assertEquals('whois.verisign-grs.com', $this->whoisService->getWhoisServerForDomain('EXAMPLE.COM'));

        // Test whitespace handling
        $this->assertEquals('whois.verisign-grs.com', $this->whoisService->getWhoisServerForDomain(' example.com '));

        // Test invalid domains
        $this->assertNull($this->whoisService->getWhoisServerForDomain('invalid'));
        $this->assertNull($this->whoisService->getWhoisServerForDomain('example.nonexistent'));
    }

    public function testHasTld()
    {
        // Test existing TLDs
        $this->assertTrue($this->whoisService->hasTld('com'));
        $this->assertTrue($this->whoisService->hasTld('co.uk'));

        // Test non-existent TLD
        $this->assertFalse($this->whoisService->hasTld('nonexistent'));

        // Test case insensitivity
        $this->assertTrue($this->whoisService->hasTld('COM'));
        $this->assertTrue($this->whoisService->hasTld('Co.Uk'));

        // Test whitespace handling
        $this->assertTrue($this->whoisService->hasTld(' com '));
    }

    public function testGetAllWhoisServers()
    {
        $servers = $this->whoisService->getAllWhoisServers();

        // Check that we get all servers
        $this->assertCount(5, $servers);

        // Check specific entries
        $this->assertArrayHasKey('com', $servers);
        $this->assertArrayHasKey('net', $servers);
        $this->assertArrayHasKey('org', $servers);
        $this->assertArrayHasKey('co.uk', $servers);
        $this->assertArrayHasKey('uk.com', $servers);

        // Check actual values
        $this->assertEquals('whois.verisign-grs.com', $servers['com']);
        $this->assertEquals('whois.pir.org', $servers['org']);
    }

    public function testSetSocketTimeout()
    {
        // Use reflection to test private property
        $reflectionClass = new \ReflectionClass(WhoisService::class);
        $property = $reflectionClass->getProperty('socketTimeout');
        $property->setAccessible(true);

        // Default value should be 10
        $this->assertEquals(10, $property->getValue($this->whoisService));

        // Set to a new value
        $this->whoisService->setSocketTimeout(5);
        $this->assertEquals(5, $property->getValue($this->whoisService));

        // Test minimum value enforcement
        $this->whoisService->setSocketTimeout(0);
        $this->assertEquals(1, $property->getValue($this->whoisService));

        $this->whoisService->setSocketTimeout(-10);
        $this->assertEquals(1, $property->getValue($this->whoisService));
    }

    public function testMissingDataFile()
    {
        // Test with non-existent file
        $whoisService = new WhoisService('/path/to/nonexistent/file.json');

        // Methods should handle missing data gracefully
        $this->assertNull($whoisService->getWhoisServer('com'));
        $this->assertFalse($whoisService->hasTld('com'));
        $this->assertEmpty($whoisService->getAllWhoisServers());
    }

    public function testRefresh()
    {
        // Initial state
        $this->assertEquals('whois.verisign-grs.com', $this->whoisService->getWhoisServer('com'));

        // Modify the test data file
        $modifiedData = [
            'com' => 'modified.whois.server.com',
            'net' => 'whois.verisign-grs.com'
        ];
        file_put_contents($this->testDataFile, json_encode($modifiedData));

        // Refresh and verify changes
        $this->assertTrue($this->whoisService->refresh());
        $this->assertEquals('modified.whois.server.com', $this->whoisService->getWhoisServer('com'));
        $this->assertNull($this->whoisService->getWhoisServer('org')); // Should be gone after refresh
    }

    public function testFormatWhoisResponse()
    {
        // Use reflection to access private method
        $reflectionClass = new \ReflectionClass(WhoisService::class);
        $method = $reflectionClass->getMethod('formatWhoisResponse');
        $method->setAccessible(true);

        // Test with various input formats
        $rawResponse = "Domain Name: EXAMPLE.COM\r\n\r\n\r\nRegistrar: Example Registrar, LLC\r\nCreation Date: 1995-08-14T04:00:00Z\r\n\r\n\r\n\r\nRegistry Expiry Date: 2021-08-13T04:00:00Z";
        $expectedOutput = "Domain Name: EXAMPLE.COM\n\nRegistrar: Example Registrar, LLC\nCreation Date: 1995-08-14T04:00:00Z\n\nRegistry Expiry Date: 2021-08-13T04:00:00Z";

        $this->assertEquals($expectedOutput, $method->invoke($this->whoisService, $rawResponse));

        // Test with different line endings
        $rawResponse = "Line1\rLine2\r\nLine3\n\n\nLine4";
        $expectedOutput = "Line1\nLine2\nLine3\n\nLine4";

        $this->assertEquals($expectedOutput, $method->invoke($this->whoisService, $rawResponse));
    }

    public function testQueryWithMockSocket()
    {
        // This test mocks the socket functions to avoid actual network calls
        // It only tests that our implementation would call fsockopen correctly

        // Skip test if socket extension not available
        if (!extension_loaded('sockets')) {
            $this->markTestSkipped('Socket extension not available');
            return;
        }

        // Create a WhoisService with a mock method for query
        $mockWhoisService = $this->getMockBuilder(WhoisService::class)
            ->setConstructorArgs([$this->testDataFile])
            ->onlyMethods(['query'])
            ->getMock();

        // Set expectations
        $mockWhoisService->expects($this->once())
            ->method('query')
            ->with('example.com', 'whois.verisign-grs.com')
            ->willReturn("Domain Name: EXAMPLE.COM\nRegistrar: Example Registrar, LLC");

        // Test the getWhoisInfo method which uses the query method internally
        $result = $mockWhoisService->getWhoisInfo('example.com');

        $this->assertTrue($result['success']);
        $this->assertEquals("Domain Name: EXAMPLE.COM\nRegistrar: Example Registrar, LLC", $result['data']);
        $this->assertNull($result['error']);
    }

    public function testGetWhoisInfoWithNoServer()
    {
        // Mock the getWhoisServerForDomain method to return null
        $mockWhoisService = $this->getMockBuilder(WhoisService::class)
            ->setConstructorArgs([$this->testDataFile])
            ->onlyMethods(['getWhoisServerForDomain'])
            ->getMock();

        $mockWhoisService->expects($this->once())
            ->method('getWhoisServerForDomain')
            ->with('example.nonexistent')
            ->willReturn(null);

        // Test getWhoisInfo with no server available
        $result = $mockWhoisService->getWhoisInfo('example.nonexistent');

        $this->assertFalse($result['success']);
        $this->assertNull($result['data']);
        $this->assertEquals('No WHOIS server found for this domain', $result['error']);
    }

    public function testGetWhoisInfoWithQueryFailure()
    {
        // Mock the necessary methods
        $mockWhoisService = $this->getMockBuilder(WhoisService::class)
            ->setConstructorArgs([$this->testDataFile])
            ->onlyMethods(['getWhoisServerForDomain', 'query'])
            ->getMock();

        $mockWhoisService->expects($this->once())
            ->method('getWhoisServerForDomain')
            ->with('example.com')
            ->willReturn('whois.verisign-grs.com');

        $mockWhoisService->expects($this->once())
            ->method('query')
            ->with('example.com', 'whois.verisign-grs.com')
            ->willReturn(null);

        // Test getWhoisInfo with query failure
        $result = $mockWhoisService->getWhoisInfo('example.com');

        $this->assertFalse($result['success']);
        $this->assertNull($result['data']);
        $this->assertEquals('Failed to retrieve WHOIS information', $result['error']);
    }

    public function testGetWhoisInfoWithException()
    {
        // Mock the getWhoisServerForDomain method to throw an exception
        $mockWhoisService = $this->getMockBuilder(WhoisService::class)
            ->setConstructorArgs([$this->testDataFile])
            ->onlyMethods(['getWhoisServerForDomain'])
            ->getMock();

        $mockWhoisService->expects($this->once())
            ->method('getWhoisServerForDomain')
            ->with('example.com')
            ->will($this->throwException(new \Exception('Test exception')));

        // Test getWhoisInfo with an exception
        $result = $mockWhoisService->getWhoisInfo('example.com');

        $this->assertFalse($result['success']);
        $this->assertNull($result['data']);
        $this->assertEquals('Error: Test exception', $result['error']);
    }
}
