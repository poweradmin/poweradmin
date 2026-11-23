<?php

namespace unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Error\ApiErrorException;
use Poweradmin\Infrastructure\Api\HttpClient;

/**
 * The API key sent to PowerDNS must not follow a redirect to another host.
 */
class HttpClientRedirectTest extends TestCase
{
    /**
     * @return array{0: resource, 1: int}
     */
    private function startStubServer(string $response): array
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($probe === false) {
            $this->markTestSkipped('Cannot open a local socket: ' . $errstr);
        }
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        $code = '$s = stream_socket_server("tcp://127.0.0.1:" . $argv[1]);'
            . 'echo "ready\n";'
            . '$c = stream_socket_accept($s, 10);'
            . 'fread($c, 8192);'
            . 'fwrite($c, base64_decode($argv[2]));';
        $process = proc_open(
            [PHP_BINARY, '-r', $code, (string) $port, base64_encode($response)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            $this->markTestSkipped('Cannot start the stub server');
        }
        if (fgets($pipes[1]) !== "ready\n") {
            proc_terminate($process);
            $this->fail('Stub server did not start');
        }

        return [$process, $port];
    }

    /**
     * @dataProvider redirectProvider
     */
    public function testRedirectIsRefusedAndNotFollowed(int $status, string $method): void
    {
        $target = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($target === false) {
            $this->markTestSkipped('Cannot open a local socket: ' . $errstr);
        }
        $targetPort = (int) substr((string) strrchr((string) stream_socket_get_name($target, false), ':'), 1);
        [$process, $port] = $this->startStubServer("HTTP/1.1 {$status} Redirect\r\nLocation: http://127.0.0.1:{$targetPort}/x\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");

        try {
            (new HttpClient('http://127.0.0.1:' . $port, 'secret-key'))->makeRequest($method, '/x', ['a' => 1]);
            $this->fail('Expected ApiErrorException');
        } catch (ApiErrorException $e) {
            $this->assertStringContainsString('Redirects are refused', $e->getMessage());
            $this->assertFalse(@stream_socket_accept($target, 0), 'the redirect target must never be contacted');
        } finally {
            fclose($target);
            proc_terminate($process);
            proc_close($process);
        }
    }

    public static function redirectProvider(): array
    {
        return [
            '301 GET' => [301, 'GET'],
            '307 PATCH' => [307, 'PATCH'],
            '308 POST' => [308, 'POST'],
        ];
    }
}
