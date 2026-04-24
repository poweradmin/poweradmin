<?php

namespace Poweradmin\Tests\Unit\Infrastructure\Api;

use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Api\HttpClient;
use ReflectionClass;
use ReflectionMethod;

/**
 * Tests for HttpClient error handling improvements
 *
 * These tests verify that:
 * 1. HTTP error responses (4xx, 5xx) are handled before JSON parsing
 * 2. Plain text error responses (e.g., "Not Found") don't cause JSON parse errors
 * 3. 404 errors are not logged (they're expected in normal operation)
 * 4. User-friendly error messages are generated for common HTTP errors
 */
class HttpClientTest extends TestCase
{
    private HttpClient $httpClient;

    protected function setUp(): void
    {
        // Create HttpClient with dummy values (we won't make real HTTP calls)
        $this->httpClient = new HttpClient('http://localhost:8081', 'test-api-key');
    }

    /**
     * Test that getHttpErrorMessage returns user-friendly messages for common HTTP errors
     */
    public function testGetHttpErrorMessageReturnsUserFriendlyMessages(): void
    {
        $method = $this->getPrivateMethod('getHttpErrorMessage');

        // Test 404 with JSON error response
        $result = $method->invoke($this->httpClient, 404, ['error' => 'Zone not found'], true);
        $this->assertStringContainsString('404', $result);
        $this->assertStringContainsString('Zone not found', $result);

        // Test 404 with raw text response (like PowerDNS "Not Found")
        $result = $method->invoke($this->httpClient, 404, ['raw_response' => 'Not Found'], true);
        $this->assertStringContainsString('404', $result);
        $this->assertStringContainsString('Not Found', $result);

        // Test 401 unauthorized
        $result = $method->invoke($this->httpClient, 401, [], true);
        $this->assertStringContainsString('401', $result);
        $this->assertStringContainsString('Unauthorized', $result);

        // Test 403 forbidden
        $result = $method->invoke($this->httpClient, 403, [], true);
        $this->assertStringContainsString('403', $result);
        $this->assertStringContainsString('Forbidden', $result);

        // Test 500 internal server error
        $result = $method->invoke($this->httpClient, 500, ['error' => 'Database error'], true);
        $this->assertStringContainsString('500', $result);
        $this->assertStringContainsString('Database error', $result);
    }

    /**
     * Test that getHttpErrorMessage hides details when displayErrors is false
     */
    public function testGetHttpErrorMessageHidesDetailsWhenDisplayErrorsFalse(): void
    {
        $method = $this->getPrivateMethod('getHttpErrorMessage');

        // With displayErrors = false, should return generic message
        $result = $method->invoke($this->httpClient, 404, ['error' => 'Zone not found'], false);
        $this->assertEquals('An API request failed', $result);
        $this->assertStringNotContainsString('Zone not found', $result);

        // Same for 500 errors
        $result = $method->invoke($this->httpClient, 500, ['error' => 'Sensitive error details'], false);
        $this->assertEquals('An API request failed', $result);
        $this->assertStringNotContainsString('Sensitive', $result);
    }

    /**
     * Test that getHttpErrorMessage handles raw responses correctly
     */
    public function testGetHttpErrorMessageHandlesRawResponses(): void
    {
        $method = $this->getPrivateMethod('getHttpErrorMessage');

        // PowerDNS returns plain "Not Found" for 404
        $result = $method->invoke($this->httpClient, 404, ['raw_response' => 'Not Found'], true);
        $this->assertStringContainsString('HTTP Error 404', $result);
        $this->assertStringContainsString('Not Found', $result);

        // PowerDNS returns plain "Internal Server Error" for 500
        $result = $method->invoke($this->httpClient, 500, ['raw_response' => 'Internal Server Error'], true);
        $this->assertStringContainsString('HTTP Error 500', $result);
        $this->assertStringContainsString('Internal Server Error', $result);
    }

    /**
     * Test that getHttpErrorMessage prioritizes JSON error over raw response
     */
    public function testGetHttpErrorMessagePrioritizesJsonError(): void
    {
        $method = $this->getPrivateMethod('getHttpErrorMessage');

        // When both error and raw_response are present, error takes priority
        $result = $method->invoke($this->httpClient, 404, [
            'error' => 'Zone example.com not found',
            'raw_response' => 'Not Found'
        ], true);
        $this->assertStringContainsString('Zone example.com not found', $result);
    }

    /**
     * Test that getHttpErrorMessage provides fallback messages for unknown error codes
     */
    public function testGetHttpErrorMessageHandlesUnknownErrorCodes(): void
    {
        $method = $this->getPrivateMethod('getHttpErrorMessage');

        // Test an uncommon error code (418 I'm a teapot)
        $result = $method->invoke($this->httpClient, 418, [], true);
        $this->assertStringContainsString('418', $result);
        $this->assertStringContainsString('Unknown error', $result);

        // Test 502 Bad Gateway (known code)
        $result = $method->invoke($this->httpClient, 502, [], true);
        $this->assertStringContainsString('502', $result);
        $this->assertStringContainsString('Bad Gateway', $result);
    }

    /**
     * 5xx responses should be classified as transient so a GET retry happens.
     */
    public function testIsTransientClassifies5xxAsRetryable(): void
    {
        $method = $this->getPrivateMethod('isTransient');

        foreach ([500, 502, 503, 504] as $code) {
            $ex = new \Poweradmin\Domain\Error\ApiErrorException('boom', $code);
            $this->assertTrue(
                $method->invoke($this->httpClient, $ex),
                "HTTP $code should be classified transient"
            );
        }
    }

    /**
     * 4xx responses should NOT be classified as transient - they are client
     * errors and retrying would not change the outcome.
     */
    public function testIsTransientClassifies4xxAsNonRetryable(): void
    {
        $method = $this->getPrivateMethod('isTransient');

        foreach ([400, 401, 403, 404, 405, 409] as $code) {
            $ex = new \Poweradmin\Domain\Error\ApiErrorException('boom', $code);
            $this->assertFalse(
                $method->invoke($this->httpClient, $ex),
                "HTTP $code should NOT be classified transient"
            );
        }
    }

    /**
     * Code-0 exceptions with connection/timeout markers in details should be
     * transient (covers stream_context failures before a response is received).
     */
    public function testIsTransientClassifiesNetworkFailuresAsRetryable(): void
    {
        $method = $this->getPrivateMethod('isTransient');

        $markers = [
            'operation timed out',
            'Connection refused',
            'could not resolve host',
            'Network is unreachable',
        ];
        foreach ($markers as $err) {
            $ex = new \Poweradmin\Domain\Error\ApiErrorException('wrapped', 0, null, ['error' => $err]);
            $this->assertTrue(
                $method->invoke($this->httpClient, $ex),
                "Error message \"$err\" should be classified transient"
            );
        }

        // Unknown code-0 errors should NOT retry - could be anything.
        $ex = new \Poweradmin\Domain\Error\ApiErrorException('wrapped', 0, null, ['error' => 'some unrelated error']);
        $this->assertFalse($method->invoke($this->httpClient, $ex));
    }

    /**
     * Helper method to access private methods for testing
     */
    private function getPrivateMethod(string $methodName): ReflectionMethod
    {
        $reflection = new ReflectionClass(HttpClient::class);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method;
    }
}
