<?php

namespace unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Api\HttpClient;
use ReflectionMethod;

class HttpClientResponseCodeTest extends TestCase
{
    #[DataProvider('responseHeaderProvider')]
    public function testGetResponseCodeReadsStatusLine(array $headers, ?int $expected): void
    {
        $method = new ReflectionMethod(HttpClient::class, 'getResponseCode');

        $this->assertSame($expected, $method->invoke(new HttpClient('http://127.0.0.1', 'key'), $headers));
    }

    public static function responseHeaderProvider(): array
    {
        return [
            'ok' => [['HTTP/1.1 200 OK', 'Content-Type: application/json'], 200],
            'no content' => [['HTTP/1.1 204 No Content'], 204],
            'not found' => [['HTTP/1.1 404 Not Found'], 404],
            'no reason phrase' => [['HTTP/1.1 422'], 422],
            'redirect then final' => [['HTTP/1.1 301 Moved Permanently', 'Location: /x', 'HTTP/1.1 200 OK', 'Content-Length: 2'], 200],
            'empty' => [[], null],
            'garbage' => [['nonsense'], null],
        ];
    }
}
