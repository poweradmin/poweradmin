<?php

declare(strict_types=1);

namespace Poweradmin\Tests\Unit\Application;

use Closure;
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

    public function testNotFoundRendererIsBuiltLazily(): void
    {
        // Building the closure must not construct the controller (that needs a database);
        // only invoking it may.
        $renderer = Bootstrap::notFoundRenderer();

        $this->assertInstanceOf(Closure::class, $renderer);
        $this->assertSame('void', (string) (new ReflectionFunction($renderer))->getReturnType());
    }
}
