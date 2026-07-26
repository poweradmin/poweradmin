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
 */

namespace Poweradmin\Tests\Unit\Application\Http;

use Exception;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Http\BootstrapErrorResponder;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;
use RuntimeException;
use Throwable;

/**
 * The JSON envelopes are a public contract, so they are asserted whole rather
 * than field by field.
 *
 * Content-Type is deliberately not asserted: header() is a no-op under the CLI
 * SAPI, so headers_list() stays empty no matter what the responder emits.
 */
class BootstrapErrorResponderTest extends TestCase
{
    private array $originalServer;
    private string $originalLogDestination;
    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalServer = $_SERVER;
        $this->logFile = tempnam(sys_get_temp_dir(), 'pa-responder-');
        $this->originalLogDestination = (string) ini_get('error_log');
        ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->originalServer;
        ini_set('error_log', $this->originalLogDestination);

        if (file_exists($this->logFile)) {
            unlink($this->logFile);
        }

        // Stop a status set by one case from leaking into the next
        http_response_code(200);
        parent::tearDown();
    }

    private function config(bool $displayErrors): ConfigurationInterface
    {
        return new class ($displayErrors) implements ConfigurationInterface {
            public function __construct(private readonly bool $displayErrors)
            {
            }

            public function get(string $group, string $key, mixed $default = null): mixed
            {
                if ($group === 'misc' && $key === 'display_errors') {
                    return $this->displayErrors;
                }

                return $default;
            }

            public function getGroup(string $group): array
            {
                return [];
            }

            public function getAll(): array
            {
                return [];
            }
        };
    }

    private function throwingConfig(): ConfigurationInterface
    {
        return new class implements ConfigurationInterface {
            public function get(string $group, string $key, mixed $default = null): mixed
            {
                throw new RuntimeException('Configuration is unavailable');
            }

            public function getGroup(string $group): array
            {
                throw new RuntimeException('Configuration is unavailable');
            }

            public function getAll(): array
            {
                throw new RuntimeException('Configuration is unavailable');
            }
        };
    }

    private function capture(
        Throwable $e,
        ?ConfigurationInterface $config = null,
        ?\Closure $notFoundRenderer = null
    ): string {
        $responder = new BootstrapErrorResponder($config ?? $this->config(false), $notFoundRenderer);

        ob_start();
        $responder->handle($e);
        return (string) ob_get_clean();
    }

    private function logContents(): string
    {
        return (string) file_get_contents($this->logFile);
    }

    public function testV1NotFoundEnvelope(): void
    {
        $_SERVER['REQUEST_URI'] = '/api/v1/zones';

        $body = $this->capture(new Exception('boom', 404));

        $this->assertSame('{"error":true,"message":"Endpoint not found"}', $body);
        $this->assertSame(404, http_response_code());
    }

    public function testV2NotFoundEnvelope(): void
    {
        $_SERVER['REQUEST_URI'] = '/api/v2/zones';

        $body = $this->capture(new Exception('boom', 404));

        $this->assertSame('{"success":false,"data":null,"message":"Endpoint not found"}', $body);
        $this->assertSame(404, http_response_code());
    }

    public function testV1MethodNotAllowedEnvelope(): void
    {
        $_SERVER['REQUEST_URI'] = '/api/v1/zones';

        $body = $this->capture(new Exception('boom', 405));

        $this->assertSame('{"error":true,"message":"Method not allowed"}', $body);
        $this->assertSame(405, http_response_code());
    }

    public function testV2MethodNotAllowedEnvelope(): void
    {
        $_SERVER['REQUEST_URI'] = '/api/v2/zones';

        $body = $this->capture(new Exception('boom', 405));

        $this->assertSame('{"success":false,"data":null,"message":"Method not allowed"}', $body);
        $this->assertSame(405, http_response_code());
    }

    public function testV1ServerErrorEnvelopeHidesInternalsByDefault(): void
    {
        $_SERVER['REQUEST_URI'] = '/api/v1/zones';

        $body = $this->capture(new Exception('database exploded'));

        $this->assertSame(
            '{"error":true,"message":"Internal server error","file":null,"line":null,"trace":null}',
            $body
        );
        $this->assertSame(500, http_response_code());
    }

    public function testV2ServerErrorEnvelopeHidesInternalsByDefault(): void
    {
        $_SERVER['REQUEST_URI'] = '/api/v2/zones';

        $body = $this->capture(new Exception('database exploded'));

        $this->assertSame('{"success":false,"data":null,"message":"Internal server error"}', $body);
        $this->assertSame(500, http_response_code());
    }

    public function testV1ServerErrorEnvelopeWithDisplayErrors(): void
    {
        $_SERVER['REQUEST_URI'] = '/api/v1/zones';
        $exception = new Exception('database exploded');

        $decoded = json_decode($this->capture($exception, $this->config(true)), true);

        $this->assertSame(['error', 'message', 'file', 'line', 'trace'], array_keys($decoded));
        $this->assertTrue($decoded['error']);
        $this->assertSame('database exploded', $decoded['message']);
        $this->assertSame($exception->getFile(), $decoded['file']);
        $this->assertSame($exception->getLine(), $decoded['line']);
        $this->assertIsArray($decoded['trace']);
    }

    public function testV2ServerErrorEnvelopeWithDisplayErrors(): void
    {
        $_SERVER['REQUEST_URI'] = '/api/v2/zones';
        $exception = new Exception('database exploded');

        $decoded = json_decode($this->capture($exception, $this->config(true)), true);

        $this->assertSame(['success', 'data', 'message'], array_keys($decoded));
        $this->assertSame(['file', 'line', 'trace'], array_keys($decoded['data']));
        $this->assertFalse($decoded['success']);
        $this->assertSame('database exploded', $decoded['message']);
        $this->assertSame($exception->getLine(), $decoded['data']['line']);
    }

    public function testNonIntegerExceptionCodeFallsThroughToServerError(): void
    {
        $_SERVER['REQUEST_URI'] = '/api/v1/zones';

        // A PDOException carries a SQLSTATE string, which must not read as 404
        $body = $this->capture(new class ('table missing') extends Exception {
            public function __construct(string $message)
            {
                parent::__construct($message);
                $this->code = '42S02';
            }
        });

        $this->assertStringContainsString('Internal server error', $body);
        $this->assertSame(500, http_response_code());
    }

    public function testJsonEnvelopeEscapingIsUnchanged(): void
    {
        $_SERVER['REQUEST_URI'] = '/api/v1/zones';

        $message = "failed at /var/www: \u{00FC}bergro\u{00DF}";

        $body = $this->capture(new Exception($message), $this->config(true));

        // json_encode runs without flags, so slashes and non-ASCII stay escaped
        $this->assertStringContainsString('\/var\/www', $body);
        $this->assertStringContainsString(trim(json_encode($message), '"'), $body);
        $this->assertStringNotContainsString($message, $body);
    }

    public function testJsonIsChosenForMixedAcceptHeader(): void
    {
        $_SERVER['REQUEST_URI'] = '/zones';
        $_SERVER['HTTP_ACCEPT'] = 'text/html,application/json';

        $body = $this->capture(new Exception('boom'));

        $this->assertSame(
            '{"error":true,"message":"Internal server error","file":null,"line":null,"trace":null}',
            $body
        );
    }

    public function testJsonIsChosenForAjax(): void
    {
        $_SERVER['REQUEST_URI'] = '/zones';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';

        $this->assertStringContainsString('"error":true', $this->capture(new Exception('boom')));
    }

    public function testHtmlIsChosenForJsonContentTypeOnAWebPath(): void
    {
        $_SERVER['REQUEST_URI'] = '/zones';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';
        $_SERVER['CONTENT_TYPE'] = 'application/json';

        $this->assertSame(
            'An error occurred while processing the request.',
            $this->capture(new Exception('boom'))
        );
    }

    public function testHtmlIsChosenWhenTheQueryStringMentionsApi(): void
    {
        $_SERVER['REQUEST_URI'] = '/zones/1/edit?next=/api/v1/zones';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';

        $this->assertSame(
            'An error occurred while processing the request.',
            $this->capture(new Exception('boom'))
        );
    }

    public function testNotFoundDelegatesToTheRenderer(): void
    {
        $_SERVER['REQUEST_URI'] = '/does-not-exist';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';

        $body = $this->capture(
            new Exception('boom', 404),
            null,
            static function (): void {
                echo 'rendered-404';
            }
        );

        $this->assertSame('rendered-404', $body);
        $this->assertSame(404, http_response_code());
    }

    public function testNotFoundFallsBackWhenTheRendererThrows(): void
    {
        $_SERVER['REQUEST_URI'] = '/does-not-exist';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';

        $body = $this->capture(
            new Exception('boom', 404),
            null,
            static function (): void {
                throw new RuntimeException('the 404 page is broken too');
            }
        );

        $this->assertSame('Page not found.', $body);
        $this->assertSame(404, http_response_code());
    }

    public function testGenericHtmlErrorHidesInternalsByDefault(): void
    {
        $_SERVER['REQUEST_URI'] = '/zones';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';

        $this->assertSame(
            'An error occurred while processing the request.',
            $this->capture(new Exception('database credentials rejected'))
        );
    }

    public function testGenericHtmlErrorShowsDebugPageWhenEnabled(): void
    {
        $_SERVER['REQUEST_URI'] = '/zones';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';
        $exception = new Exception('something went wrong');

        $body = $this->capture($exception, $this->config(true));

        $expected = '<pre>'
            . 'Error: something went wrong' . "\n"
            . 'File: ' . htmlspecialchars($exception->getFile()) . "\n"
            . 'Line: ' . $exception->getLine() . "\n"
            . 'Trace: ' . "\n" . htmlspecialchars($exception->getTraceAsString())
            . '</pre>';

        $this->assertSame($expected, $body);
    }

    public function testDebugPageEscapesHtmlInTheMessage(): void
    {
        $_SERVER['REQUEST_URI'] = '/zones';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';

        $body = $this->capture(
            new Exception("<script>alert('XSS attack');</script><img src=x onerror=alert(1)>"),
            $this->config(true)
        );

        $this->assertStringContainsString('&lt;script&gt;', $body);
        $this->assertStringContainsString('&lt;img', $body);
        $this->assertStringNotContainsString('<script>', $body);
        $this->assertStringNotContainsString('<img', $body);
    }

    public function testDebugPagePreservesUnicode(): void
    {
        $_SERVER['REQUEST_URI'] = '/zones';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';

        $body = $this->capture(new Exception('测试.中国 🚨 ñáéíóú العربية'), $this->config(true));

        $this->assertStringContainsString('测试.中国', $body);
        $this->assertStringContainsString('🚨', $body);
        $this->assertStringContainsString('ñáéíóú', $body);
        $this->assertStringContainsString('العربية', $body);
    }

    public function testDebugPageIncludesTheFullStackTrace(): void
    {
        $_SERVER['REQUEST_URI'] = '/zones';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';

        $body = $this->capture($this->levelOne(), $this->config(true));

        $this->assertStringContainsString('levelOne', $body);
        $this->assertStringContainsString('levelTwo', $body);
        $this->assertStringContainsString('levelThree', $body);
    }

    public function testConfigurationFailureDoesNotEscapeTheResponder(): void
    {
        $_SERVER['REQUEST_URI'] = '/api/v2/zones';

        $body = $this->capture(new RuntimeException('config file is broken'), $this->throwingConfig());

        $this->assertSame('{"success":false,"data":null,"message":"Internal server error"}', $body);
        $this->assertSame(500, http_response_code());
    }

    public function testNotFoundIsLoggedWithoutAStackTrace(): void
    {
        $_SERVER['REQUEST_URI'] = '/api/v1/zones';

        $this->capture(new Exception('Controller class Foo not found', 404));

        $log = $this->logContents();
        $this->assertStringContainsString('Controller class Foo not found', $log);
        $this->assertStringNotContainsString('#0 ', $log);
    }

    public function testMethodNotAllowedIsLoggedWithoutAStackTrace(): void
    {
        $_SERVER['REQUEST_URI'] = '/api/v1/zones';

        $this->capture(new Exception('Method not allowed', 405));

        $log = $this->logContents();
        $this->assertStringContainsString('Method not allowed', $log);
        $this->assertStringNotContainsString('#0 ', $log);
    }

    public function testServerErrorIsLoggedWithItsStackTrace(): void
    {
        $_SERVER['REQUEST_URI'] = '/api/v1/zones';

        $this->capture(new Exception('database exploded'));

        $log = $this->logContents();
        $this->assertStringContainsString('database exploded', $log);
        $this->assertStringContainsString('#0 ', $log);
    }

    private function levelOne(): Throwable
    {
        return $this->levelTwo();
    }

    private function levelTwo(): Throwable
    {
        return $this->levelThree();
    }

    private function levelThree(): Throwable
    {
        return new Exception('deep failure');
    }
}
