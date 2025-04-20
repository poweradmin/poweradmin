<?php

namespace integration;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\WhoisService;

class WhoisServerAvailabilityTest extends TestCase
{
    /**
     * @var WhoisService
     */
    private WhoisService $whoisService;

    /**
     * @var array
     */
    private array $serverResults = [];

    /**
     * Set up the test environment
     */
    protected function setUp(): void
    {
        $this->whoisService = new WhoisService();

        // Use a shorter timeout for tests to avoid long execution times
        $this->whoisService->setSocketTimeout(3);
    }

    /**
     * Test the availability of all whois servers
     *
     * This test checks a representative sample of whois servers to avoid
     * very long test execution times and possible rate limiting
     */
    public function testSampleWhoisServerAvailability(): void
    {
        $sampleTlds = [
            'com', 'net', 'org', 'io', 'uk', 'de', 'fr',
            'jp', 'au', 'ru', 'us', 'co.uk', 'eu'
        ];

        $results = $this->checkServersAvailability($sampleTlds);

        // Verify that we have results for all sample TLDs
        foreach ($sampleTlds as $tld) {
            $this->assertArrayHasKey($tld, $results, "No test results for TLD: $tld");
        }

        // Log the results for inspection (not actual assertions)
        $this->logTestResults($results, 'Sample WHOIS servers');

        // Assert that at least 70% of the servers are available
        // This allows for some servers to be temporarily down without failing the test
        $availableCount = count(array_filter($results, function ($result) {
            return $result['available'];
        }));

        $this->assertGreaterThanOrEqual(
            count($sampleTlds) * 0.7,
            $availableCount,
            'Less than 70% of the sample WHOIS servers are available'
        );
    }

    /**
     * Optional comprehensive test for all WHOIS servers
     *
     * This test is marked as skipped by default as it would take a long time
     * to execute and might encounter rate limiting issues with some servers.
     *
     * Run this test manually when needed with:
     * phpunit --filter testAllWhoisServersAvailability tests/integration/WhoisServerAvailabilityTest.php
     */
    public function testAllWhoisServersAvailability(): void
    {
        $this->markTestSkipped(
            'Skipping comprehensive WHOIS server test. ' .
            'Run manually when needed with: ' .
            'phpunit --filter testAllWhoisServersAvailability tests/integration/WhoisServerAvailabilityTest.php'
        );

        $allServers = $this->whoisService->getAllWhoisServers();
        $allTlds = array_keys($allServers);

        // Shuffle to avoid hitting the same servers/networks consecutively
        shuffle($allTlds);

        $results = $this->checkServersAvailability($allTlds);

        // Log the results for detailed inspection
        $this->logTestResults($results, 'All WHOIS servers');

        // Assert that at least 70% of the servers are available
        $availableCount = count(array_filter($results, function ($result) {
            return $result['available'];
        }));

        $this->assertGreaterThanOrEqual(
            count($allTlds) * 0.7,
            $availableCount,
            'Less than 70% of all WHOIS servers are available'
        );
    }

    /**
     * Check a list of WHOIS servers for availability
     *
     * @param array $tlds List of TLDs to check
     * @return array Results indexed by TLD
     */
    private function checkServersAvailability(array $tlds): array
    {
        $results = [];

        foreach ($tlds as $tld) {
            $server = $this->whoisService->getWhoisServer($tld);

            if ($server === null) {
                $results[$tld] = [
                    'server' => null,
                    'available' => false,
                    'error' => 'No WHOIS server defined for this TLD'
                ];
                continue;
            }

            // Skip HTTP/HTTPS servers as they don't use standard WHOIS protocol
            if (strpos($server, 'www.') === 0 || strpos($server, 'http') === 0) {
                $results[$tld] = [
                    'server' => $server,
                    'available' => true, // Assume available as we can't easily test HTTP
                    'error' => null,
                    'note' => 'Web-based WHOIS service (not tested)'
                ];
                continue;
            }

            // Attempt to connect to the WHOIS server
            $available = false;
            $error = null;

            try {
                // Standard WHOIS port
                $port = 43;

                // Create a socket connection to the WHOIS server
                $socket = @fsockopen($server, $port, $errno, $errstr, 3);

                if ($socket) {
                    $available = true;
                    fclose($socket);
                } else {
                    $error = "$errstr (error #$errno)";
                }
            } catch (\Exception $e) {
                $error = $e->getMessage();
            }

            $results[$tld] = [
                'server' => $server,
                'available' => $available,
                'error' => $error
            ];
        }

        return $results;
    }

    /**
     * Log the test results
     *
     * @param array $results The availability results
     * @param string $description Description of the test set
     */
    private function logTestResults(array $results, string $description): void
    {
        // Count available and unavailable servers
        $available = count(array_filter($results, function ($result) {
            return $result['available'];
        }));

        $unavailable = count($results) - $available;

        // Store results for the tearDown summary
        $this->serverResults[$description] = [
            'total' => count($results),
            'available' => $available,
            'unavailable' => $unavailable,
            'details' => $results
        ];

        // Immediate output for monitoring during test execution
        echo "\n{$description}: {$available} available, {$unavailable} unavailable\n";

        // Log unavailable servers for debugging
        $unavailableServers = array_filter($results, function ($result) {
            return !$result['available'];
        });

        if (count($unavailableServers) > 0) {
            echo "Unavailable servers:\n";
            foreach ($unavailableServers as $tld => $result) {
                $error = $result['error'] ?? 'Unknown error';
                echo "  - {$tld}: {$result['server']} - {$error}\n";
            }
        }
    }

    /**
     * Output a summary of all test results
     */
    protected function tearDown(): void
    {
        if (empty($this->serverResults)) {
            return;
        }

        echo "\n=== WHOIS Server Availability Summary ===\n";

        foreach ($this->serverResults as $description => $result) {
            $availablePercent = round(($result['available'] / $result['total']) * 100, 1);

            echo "\n{$description}:\n";
            echo "  - Total: {$result['total']}\n";
            echo "  - Available: {$result['available']} ({$availablePercent}%)\n";
            echo "  - Unavailable: {$result['unavailable']}\n";
        }

        echo "\n=========================================\n";
    }
}
