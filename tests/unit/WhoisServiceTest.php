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
}
