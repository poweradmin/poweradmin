<?php

namespace integration;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\WhoisService;

class WhoisServiceIntegrationTest extends TestCase
{
    /**
     * @var WhoisService
     */
    private WhoisService $whoisService;

    /**
     * Set up the test environment
     */
    protected function setUp(): void
    {
        $this->whoisService = new WhoisService();

        // Use a shorter timeout for tests
        $this->whoisService->setSocketTimeout(5);
    }

    /**
     * Test that the WhoisService can correctly load the servers list
     */
    public function testWhoisServiceLoadsServersList(): void
    {
        // Get all whois servers
        $servers = $this->whoisService->getAllWhoisServers();

        // Verify that the service has loaded the servers
        $this->assertNotEmpty($servers, 'The whois servers list is empty');

        // Check for some common TLDs that should definitely be in the list
        $this->assertArrayHasKey('com', $servers, 'COM TLD is missing from whois servers list');
        $this->assertArrayHasKey('net', $servers, 'NET TLD is missing from whois servers list');
        $this->assertArrayHasKey('org', $servers, 'ORG TLD is missing from whois servers list');

        // Check specific server values
        $this->assertEquals('whois.verisign-grs.com', $servers['com'], 'Incorrect whois server for COM TLD');
        $this->assertEquals('whois.nic.uk', $servers['uk'], 'Incorrect whois server for UK TLD');
    }

    /**
     * Test that the WhoisService can perform a basic query
     *
     * This test will perform an actual WHOIS query against a reliable server.
     * It might fail if there are network issues or rate limiting.
     */
    public function testWhoisServicePerformsBasicQuery(): void
    {
        // Use IANA's whois server which is generally reliable
        $response = $this->whoisService->query('example.com', 'whois.verisign-grs.com');

        // Verify that we got a response
        $this->assertNotNull($response, 'Failed to get a response from whois.verisign-grs.com');

        // Verify the response contains expected information
        $this->assertStringContainsString('example.com', strtolower($response), 'Whois response does not contain the queried domain');

        // Check for common whois response fields
        $this->assertMatchesRegularExpression('/domain name:/i', $response, 'Whois response missing domain name field');
        $this->assertMatchesRegularExpression('/registrar:/i', $response, 'Whois response missing registrar field');
    }

    /**
     * Test that the WhoisService can lookup the correct server for a domain
     */
    public function testWhoisServerLookupForDomain(): void
    {
        // Test various domain types
        $testCases = [
            'example.com' => 'whois.verisign-grs.com',
            'example.net' => 'whois.verisign-grs.com',
            'example.org' => 'whois.pir.org',
            'example.co.uk' => 'whois.nic.uk',
            'example.io' => 'whois.nic.io',
        ];

        foreach ($testCases as $domain => $expectedServer) {
            $server = $this->whoisService->getWhoisServerForDomain($domain);
            $this->assertEquals(
                $expectedServer,
                $server,
                "Incorrect whois server for domain: $domain"
            );
        }

        // Test invalid or unknown domains
        $this->assertNull(
            $this->whoisService->getWhoisServerForDomain('example.invalidtld'),
            'Should return null for unknown TLD'
        );
    }

    /**
     * Test the full getWhoisInfo workflow
     *
     * This test performs a complete whois lookup for a domain,
     * similar to what would happen in the application.
     */
    public function testGetWhoisInfoWorkflow(): void
    {
        // This test makes a real network request, mark as skipped if in CI environment
        if (getenv('CI') === 'true') {
            $this->markTestSkipped('Skipping network test in CI environment');
        }

        // Use IANA's example.com which is stable and well-known
        $result = $this->whoisService->getWhoisInfo('example.com');

        // Check the result structure
        $this->assertIsArray($result, 'getWhoisInfo should return an array');
        $this->assertArrayHasKey('success', $result, 'Result should have a success key');
        $this->assertArrayHasKey('data', $result, 'Result should have a data key');
        $this->assertArrayHasKey('error', $result, 'Result should have an error key');

        // Check that the request was successful
        $this->assertTrue($result['success'], 'Whois lookup should succeed for example.com');
        $this->assertNotNull($result['data'], 'Whois data should not be null');
        $this->assertNull($result['error'], 'Error should be null on success');

        // Check the content of the data
        $this->assertStringContainsString('example.com', strtolower($result['data']), 'Whois data should contain the domain name');
    }

    /**
     * Test handling of invalid domains
     */
    public function testInvalidDomainHandling(): void
    {
        // Test with an invalid domain format
        $result = $this->whoisService->getWhoisInfo('invalid');

        $this->assertFalse($result['success'], 'Should fail for invalid domain format');
        $this->assertNull($result['data'], 'Data should be null for invalid domain');
        $this->assertNotNull($result['error'], 'Error should not be null for invalid domain');
        $this->assertStringContainsString('No WHOIS server found', $result['error'], 'Error message should mention no server found');
    }

    /**
     * Test error handling for non-existent whois server
     */
    public function testNonExistentServerHandling(): void
    {
        // Try to query a non-existent server directly
        $response = $this->whoisService->query('example.com', 'non.existent.server');

        // Should return null for connection failure
        $this->assertNull($response, 'Should return null for non-existent server');
    }
}
