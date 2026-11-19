<?php

declare(strict_types=1);

namespace Poweradmin\Tests\Unit\Application;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Bootstrap;
use ReflectionFunction;
use TestHelpers\FakeConfiguration;

/**
 * Unit tests for the startup helper functions shared by index.php and
 * dynamic_update.php. Error-response shaping lives in BootstrapErrorResponder.
 */
class BootstrapTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Load the helper functions
    }

    public function testInitializeTimezoneUsesConfiguredTimezone(): void
    {
        $originalTz = date_default_timezone_get();

        $configMock = new FakeConfiguration(['misc' => ['timezone' => 'Asia/Shanghai']]);

        Bootstrap::initializeTimezone($configMock);

        $this->assertEquals('Asia/Shanghai', date_default_timezone_get());

        date_default_timezone_set($originalTz);
    }

    public function testInitializeTimezoneDoesNotOverrideWhenNotConfigured(): void
    {
        $originalTz = date_default_timezone_get();
        date_default_timezone_set('Asia/Tokyo');

        $configMock = new FakeConfiguration();

        Bootstrap::initializeTimezone($configMock);

        // With no configured timezone and php.ini timezone set, existing timezone is preserved
        $this->assertEquals('Asia/Tokyo', date_default_timezone_get());

        date_default_timezone_set($originalTz);
    }

    #[DataProvider('sessionCookieSecureCases')]
    public function testSessionCookieSecureIsNeverLowerThanTheIni(array $server, string $ini, bool $expected): void
    {
        $originalServer = $_SERVER;
        $originalIni = ini_get('session.cookie_secure');
        unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
        $_SERVER = array_merge($_SERVER, $server);
        ini_set('session.cookie_secure', $ini);

        try {
            $this->assertSame($expected, Bootstrap::sessionCookieSecure());
        } finally {
            $_SERVER = $originalServer;
            ini_set('session.cookie_secure', (string) $originalIni);
        }
    }

    /** @return array<string, array{array<string, string>, string, bool}> */
    public static function sessionCookieSecureCases(): array
    {
        return [
            'plain http, ini off' => [[], '0', false],
            'plain http, ini on' => [[], '1', true],
            'plain http, ini On' => [[], 'On', true],
            'https, ini off' => [['HTTPS' => 'on'], '0', true],
            'HTTPS=off, ini off' => [['HTTPS' => 'off'], '0', false],
            'forwarded https, ini off' => [['HTTP_X_FORWARDED_PROTO' => 'https'], '0', true],
            'forwarded http, ini on' => [['HTTP_X_FORWARDED_PROTO' => 'http'], '1', true],
        ];
    }

    public function testNotFoundRendererIsBuiltLazily(): void
    {
        // Building the closure must not construct the controller (that needs a database);
        // only invoking it may.
        $renderer = Bootstrap::notFoundRenderer();

        $this->assertInstanceOf(Closure::class, $renderer);
        $this->assertSame('void', (string) (new ReflectionFunction($renderer))->getReturnType());
    }
}
