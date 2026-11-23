<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2026 Poweradmin Development Team
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace Poweradmin\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Error\ApiErrorException;
use Poweradmin\Infrastructure\Api\HttpClient;
use TestHelpers\StubHttpServer;

/**
 * Real HTTP calls through HttpClient against the API-mode PowerDNS devcontainer instance.
 *
 * Guards the response-header handling: it must work on every PHP version, including 8.5
 * where $http_response_header is deprecated. The PowerDNS-backed tests are skipped when the API
 * is unreachable; the stub-server and connection-refused tests need no PowerDNS.
 * Set POWERADMIN_TEST_PDNS_API_URL when the API is not on 127.0.0.1:8186 (for example
 * http://host.docker.internal:8186 from a container).
 */
class HttpClientIntegrationTest extends TestCase
{
    use StubHttpServer;

    private const DEFAULT_API_URL = 'http://127.0.0.1:8186';
    private const API_KEY = 'fxiBmBFx7MITw5ECRMOr10ghlxGMvWZA';
    private const ZONES = '/api/v1/servers/localhost/zones';

    private string $apiUrl;
    private HttpClient $client;
    private string $zone = '';

    protected function setUp(): void
    {
        $this->apiUrl = getenv('POWERADMIN_TEST_PDNS_API_URL') ?: self::DEFAULT_API_URL;
        $this->client = new HttpClient($this->apiUrl, self::API_KEY, null, 5, true);
    }

    private function requirePowerDns(): void
    {
        try {
            $this->client->makeRequest('GET', '/api/v1/servers/localhost');
        } catch (ApiErrorException $e) {
            $this->markTestSkipped('PowerDNS API not available at ' . $this->apiUrl . ': ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if ($this->zone !== '') {
            try {
                $this->client->makeRequest('DELETE', self::ZONES . '/' . $this->zone);
            } catch (ApiErrorException) {
                // already gone
            }
        }
    }

    private function createZone(): void
    {
        $this->zone = 'httpclient-' . bin2hex(random_bytes(4)) . '.example.';
        $result = $this->client->makeRequest('POST', self::ZONES, [
            'name' => $this->zone,
            'kind' => 'Native',
            'nameservers' => ['ns1.example.com.'],
        ]);
        $this->assertSame(201, $result['responseCode']);
    }

    private function patchA(string $content): array
    {
        return $this->client->makeRequest('PATCH', self::ZONES . '/' . $this->zone, [
            'rrsets' => [[
                'name' => 'www.' . $this->zone,
                'type' => 'A',
                'ttl' => 300,
                'changetype' => 'REPLACE',
                'records' => [['content' => $content, 'disabled' => false]],
            ]],
        ]);
    }

    public function testGetReturnsStatus200AndDecodedJson(): void
    {
        $this->requirePowerDns();
        $result = $this->client->makeRequest('GET', '/api/v1/servers/localhost');

        $this->assertSame(200, $result['responseCode']);
        $this->assertSame('localhost', $result['data']['id']);
    }

    public function testPatchReturnsStatus204WithEmptyData(): void
    {
        $this->requirePowerDns();
        $this->createZone();

        $result = $this->patchA('192.0.2.1');

        $this->assertSame(204, $result['responseCode']);
        $this->assertSame([], $result['data']);
    }

    public function testNotFoundBodyIsPassedThroughInException(): void
    {
        $this->requirePowerDns();
        try {
            $this->client->makeRequest('GET', self::ZONES . '/does-not-exist-' . bin2hex(random_bytes(4)) . '.example.');
            $this->fail('Expected ApiErrorException');
        } catch (ApiErrorException $e) {
            $this->assertSame(404, $e->getCode());
            $this->assertSame(404, $e->getDetail('http_code'));
            $this->assertNotEmpty($e->getDetail('response'));
        }
    }

    public function testRejectedRecordBodyIsPassedThroughInException(): void
    {
        $this->requirePowerDns();
        $this->createZone();

        try {
            $this->patchA('not-an-ip');
            $this->fail('Expected ApiErrorException');
        } catch (ApiErrorException $e) {
            $this->assertContains($e->getCode(), [400, 422]);
            $response = $e->getDetail('response');
            $this->assertIsArray($response);
            $this->assertArrayHasKey('error', $response);
        }
    }

    public function testAnOversizedResponseIsRefusedAndOneThatFitsIsParsed(): void
    {
        $body = '{"a":"' . str_repeat('x', 200) . '"}';
        [$process, $port] = $this->startStubServer("HTTP/1.1 200 OK\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
        try {
            (new HttpClient('http://127.0.0.1:' . $port, self::API_KEY, null, 3, true, 100))->makeRequest('GET', '/x');
            $this->fail('Expected ApiErrorException');
        } catch (ApiErrorException $e) {
            $this->assertStringContainsString('larger than the supported size', $e->getMessage());
        } finally {
            proc_terminate($process);
            proc_close($process);
        }

        [$process, $port] = $this->startStubServer("HTTP/1.1 200 OK\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
        try {
            $result = (new HttpClient('http://127.0.0.1:' . $port, self::API_KEY, null, 3, true, strlen($body)))->makeRequest('GET', '/x');
            $this->assertSame(200, $result['responseCode']);
            $this->assertSame(200, strlen($result['data']['a']));
        } finally {
            proc_terminate($process);
            proc_close($process);
        }
    }

    public function testTruncatedBodyRaisesApiErrorWithoutEmittingWarnings(): void
    {
        [$process, $port] = $this->startStubServer("HTTP/1.1 200 OK\r\nContent-Length: 100\r\nConnection: close\r\n\r\npartial");

        $warnings = [];
        set_error_handler(static function (int $no, string $msg) use (&$warnings): bool {
            $warnings[] = $msg;
            return true;
        });
        try {
            (new HttpClient('http://127.0.0.1:' . $port, self::API_KEY, null, 3, true))->makeRequest('POST', '/x');
            $this->fail('Expected ApiErrorException');
        } catch (ApiErrorException $e) {
            $this->assertStringContainsString('Invalid JSON', $e->getMessage());
        } finally {
            restore_error_handler();
            proc_terminate($process);
            proc_close($process);
        }

        $this->assertSame([], $warnings);
    }

    /**
     * @return array{0: resource, 1: int}
     */
    private function startRedirectingServer(int $status): array
    {
        return $this->startStubServer("HTTP/1.1 {$status} Redirect\r\nLocation: http://127.0.0.1:9/x?secret=1\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('redirectStatusProvider')]
    public function testRedirectIsRefusedForEveryMethodAndNotFollowed(int $status, string $method): void
    {
        [$target, $targetPort] = $this->listenOnFreePort();
        [$process, $port] = $this->startStubServer("HTTP/1.1 {$status} Redirect\r\nLocation: http://127.0.0.1:" . $targetPort . "/x\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");

        try {
            (new HttpClient('http://127.0.0.1:' . $port, self::API_KEY, null, 3, false))->makeRequest($method, '/x', ['a' => 1]);
            $this->fail('Expected ApiErrorException');
        } catch (ApiErrorException $e) {
            $this->assertSame(0, $e->getCode());
            $this->assertStringContainsString('Redirects are refused', $e->getMessage());
            $this->assertStringContainsString((string) $status, $e->getMessage());
            $this->assertStringNotContainsString((string) $targetPort, $e->getMessage());
            $this->assertFalse(@stream_socket_accept($target, 0), 'the redirect target must never be contacted');
        } finally {
            fclose($target);
            foreach ([$process] as $p) {
                proc_terminate($p);
                proc_close($p);
            }
        }
    }

    public static function redirectStatusProvider(): array
    {
        return [
            '301 GET' => [301, 'GET'],
            '302 PUT' => [302, 'PUT'],
            '307 PATCH' => [307, 'PATCH'],
            '308 POST' => [308, 'POST'],
        ];
    }

    public function testRefusedRedirectLogsStatusWithoutTheLocation(): void
    {
        [$process, $port] = $this->startRedirectingServer(301);
        $logger = new class extends \Psr\Log\AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->lines[] = $level . ' ' . $message . ' ' . json_encode($context);
            }
        };

        try {
            (new HttpClient('http://127.0.0.1:' . $port, self::API_KEY, $logger, 3, false))->makeRequest('GET', '/x');
            $this->fail('Expected ApiErrorException');
        } catch (ApiErrorException) {
            $log = implode("\n", $logger->lines);
            $this->assertStringContainsString('301', $log);
            $this->assertStringContainsString('Redirects are refused', $log);
            $this->assertStringNotContainsString('secret=1', $log);
            $this->assertStringNotContainsString(self::API_KEY, $log);
        } finally {
            proc_terminate($process);
            proc_close($process);
        }
    }

    public function testStalledBodyReadRaisesTimeoutError(): void
    {
        [$process, $port] = $this->startStubServer("HTTP/1.1 200 OK\r\nContent-Length: 100\r\nConnection: close\r\n\r\n{\"a\":", 4);

        try {
            (new HttpClient('http://127.0.0.1:' . $port, self::API_KEY, null, 1, true))->makeRequest('POST', '/x');
            $this->fail('Expected ApiErrorException');
        } catch (ApiErrorException $e) {
            $this->assertSame(0, $e->getCode());
            $this->assertStringContainsString('timed out', strtolower($e->getMessage()));
            $this->assertStringContainsString('timed out', strtolower((string) $e->getDetail('error')));
        } finally {
            proc_terminate($process);
            proc_close($process);
        }
    }

    public function testConnectionRefusedRaisesApiErrorException(): void
    {
        $client = new HttpClient('http://127.0.0.1:1', self::API_KEY, null, 2, true);

        try {
            $client->makeRequest('POST', '/api/v1/servers/localhost');
            $this->fail('Expected ApiErrorException');
        } catch (ApiErrorException $e) {
            $this->assertSame(0, $e->getCode());
            $this->assertStringContainsString('connection refused', strtolower($e->getMessage()));
            $this->assertNotSame('', (string) $e->getDetail('error'));
        }
    }
}
