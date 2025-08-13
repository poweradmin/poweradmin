<?php

declare(strict_types=1);

namespace Poweradmin\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for startup helper functions
 *
 * Tests the helper functions extracted during index.php refactoring:
 * - sendJsonError()
 * - displayHtmlError()
 *
 * These functions are critical for error handling in both web and API contexts.
 */
class StartupHelpersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Load the helper functions
        require_once __DIR__ . '/../../lib/Application/Helpers/StartupHelpers.php';
    }

    /**
     * Test sendJsonError with complete parameters
     */
    public function testSendJsonErrorWithAllParameters(): void
    {
        ob_start();
        sendJsonError(
            "Database connection failed",
            "/var/www/poweradmin/lib/Database.php",
            42,
            ["Database->connect()", "ConfigManager->initialize()"]
        );
        $output = ob_get_clean();

        $decoded = json_decode($output, true);

        $this->assertIsArray($decoded);
        $this->assertEquals("Database connection failed", $decoded['message']);
        $this->assertEquals("/var/www/poweradmin/lib/Database.php", $decoded['file']);
        $this->assertEquals(42, $decoded['line']);
        $this->assertEquals(["Database->connect()", "ConfigManager->initialize()"], $decoded['trace']);
        $this->assertTrue($decoded['error']);

        // Verify JSON is valid
        $this->assertJson($output);
    }

    /**
     * Test sendJsonError with only required message parameter
     */
    public function testSendJsonErrorMinimalUsage(): void
    {
        ob_start();
        sendJsonError("Invalid API request");
        $output = ob_get_clean();

        $decoded = json_decode($output, true);

        $this->assertIsArray($decoded);
        $this->assertEquals("Invalid API request", $decoded['message']);
        $this->assertNull($decoded['file']);
        $this->assertNull($decoded['line']);
        $this->assertNull($decoded['trace']);
        $this->assertTrue($decoded['error']);
    }

    /**
     * Test displayHtmlError with basic exception
     */
    public function testDisplayHtmlErrorBasicException(): void
    {
        $exception = new \Exception("Configuration file not found");

        ob_start();
        displayHtmlError($exception);
        $output = ob_get_clean();

        $this->assertStringContainsString('<pre>', $output);
        $this->assertStringContainsString('Error: Configuration file not found', $output);
        $this->assertStringContainsString('File:', $output);
        $this->assertStringContainsString('Line:', $output);
        $this->assertStringContainsString('Trace:', $output);
        $this->assertStringContainsString('</pre>', $output);
    }

    /**
     * Test XSS protection in HTML error display
     * This is critical for security - ensures malicious code in exceptions is escaped
     */
    public function testDisplayHtmlErrorXssProtection(): void
    {
        $maliciousException = new \Exception("<script>alert('XSS attack');</script><img src=x onerror=alert(1)>");

        ob_start();
        displayHtmlError($maliciousException);
        $output = ob_get_clean();

        // Verify HTML tags are escaped
        $this->assertStringNotContainsString('<script>', $output);
        $this->assertStringNotContainsString('<img', $output);
        $this->assertStringContainsString('&lt;script&gt;', $output);
        $this->assertStringContainsString('&lt;img', $output);

        // Verify quotes are escaped
        $this->assertStringNotContainsString("alert('XSS attack');", $output);
        $this->assertStringContainsString("alert(&#039;XSS attack&#039;);", $output);
    }

    /**
     * Test Unicode character handling in HTML error display
     * Important for international users and DNS with IDN
     */
    public function testDisplayHtmlErrorWithUnicodeCharacters(): void
    {
        $unicodeException = new \Exception("DNS zone 测试.中国 validation failed: émojis 🚨 ñáéíóú العربية");

        ob_start();
        displayHtmlError($unicodeException);
        $output = ob_get_clean();

        $this->assertStringContainsString('测试.中国', $output);
        $this->assertStringContainsString('🚨', $output);
        $this->assertStringContainsString('ñáéíóú', $output);
        $this->assertStringContainsString('العربية', $output);

        // Ensure HTML structure is maintained
        $this->assertStringContainsString('<pre>', $output);
        $this->assertStringContainsString('</pre>', $output);
    }

    /**
     * Test JSON error with special characters that could break JSON
     */
    public function testSendJsonErrorWithSpecialCharacters(): void
    {
        ob_start();
        sendJsonError(
            'Error with "quotes", \backslashes, and newlines\nand tabs\t',
            "/path/with spaces/file.php",
            123,
            ["Line with \"quotes\"", "Line with \backslash"]
        );
        $output = ob_get_clean();

        // Verify it's still valid JSON
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertEquals(JSON_ERROR_NONE, json_last_error());

        // Verify content is preserved correctly
        $this->assertStringContainsString('"quotes"', $decoded['message']);
        $this->assertStringContainsString('\\backslashes', $decoded['message']);
        $this->assertStringContainsString('newlines', $decoded['message']);
    }

    /**
     * Test performance with large error messages
     * Ensures helpers don't cause memory issues with large stack traces
     */
    public function testPerformanceWithLargeErrorData(): void
    {
        $largeMessage = str_repeat("DNS zone validation failed for large zone file. ", 200);
        $largeTrace = array_fill(0, 100, "RecordValidator->validate() in /very/long/path/to/file.php:123");

        $startTime = microtime(true);
        $memoryBefore = memory_get_usage();

        ob_start();
        sendJsonError($largeMessage, "/long/path.php", 999, $largeTrace);
        $output = ob_get_clean();

        $endTime = microtime(true);
        $memoryAfter = memory_get_usage();

        // Performance assertions
        $this->assertLessThan(0.1, $endTime - $startTime, "JSON error generation too slow");
        $this->assertLessThan(10 * 1024 * 1024, $memoryAfter - $memoryBefore, "Too much memory used");

        // Functionality verification
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertEquals(100, count($decoded['trace']));
    }

    /**
     * Test error handling edge cases
     */
    public function testErrorHandlingEdgeCases(): void
    {
        // Empty message
        ob_start();
        sendJsonError("");
        $output = ob_get_clean();
        $decoded = json_decode($output, true);
        $this->assertEquals("", $decoded['message']);

        // Zero line number
        ob_start();
        sendJsonError("Test", "/file.php", 0);
        $output = ob_get_clean();
        $decoded = json_decode($output, true);
        $this->assertEquals(0, $decoded['line']);

        // Large line number (edge case for file line numbers)
        ob_start();
        sendJsonError("Test", "/file.php", 999999);
        $output = ob_get_clean();
        $decoded = json_decode($output, true);
        $this->assertEquals(999999, $decoded['line']);
    }

    /**
     * Test that HTML error display handles deep stack traces
     * Important for complex DNS validation chains
     */
    public function testDisplayHtmlErrorWithDeepStackTrace(): void
    {
        // Create exception with deep call stack
        try {
            $this->level1();
        } catch (\Exception $e) {
            ob_start();
            displayHtmlError($e);
            $output = ob_get_clean();

            $this->assertStringContainsString('level1', $output);
            $this->assertStringContainsString('level2', $output);
            $this->assertStringContainsString('level3', $output);
            $this->assertStringContainsString('<pre>', $output);
            $this->assertStringContainsString('Trace:', $output);
        }
    }

    /**
     * Helper method to create deep stack trace for testing
     */
    private function level1(): void
    {
        $this->level2();
    }

    private function level2(): void
    {
        $this->level3();
    }

    private function level3(): void
    {
        throw new \Exception("Deep stack trace test for DNS validation");
    }

    /**
     * Test memory efficiency with multiple error calls
     * Simulates high-traffic scenario with many API errors
     */
    public function testMemoryEfficiencyWithMultipleErrors(): void
    {
        $memoryBefore = memory_get_usage();

        // Simulate 50 API errors
        for ($i = 0; $i < 50; $i++) {
            ob_start();
            sendJsonError("API error #$i", "/api/v1/endpoint.php", $i, ["trace$i"]);
            ob_end_clean(); // Discard output but measure memory
        }

        $memoryAfter = memory_get_usage();
        $memoryUsed = $memoryAfter - $memoryBefore;

        // Should not use excessive memory (less than 5MB for 50 errors)
        $this->assertLessThan(
            5 * 1024 * 1024,
            $memoryUsed,
            "Memory usage too high: {$memoryUsed} bytes for 50 errors"
        );
    }
}
