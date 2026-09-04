<?php

declare(strict_types=1);

namespace Poweradmin\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Unit tests for the startup helper functions shared by index.php and
 * dynamic_update.php. Error-response shaping lives in BootstrapErrorResponder.
 */
class StartupHelpersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Load the helper functions
        require_once __DIR__ . '/../../lib/Application/Helpers/StartupHelpers.php';
    }

    public function testInitializeTimezoneUsesConfiguredTimezone(): void
    {
        $originalTz = date_default_timezone_get();

        $configMock = $this->createMock(ConfigurationManager::class);
        $configMock->method('get')
            ->with('misc', 'timezone')
            ->willReturn('Asia/Shanghai');

        initializeTimezone($configMock);

        $this->assertEquals('Asia/Shanghai', date_default_timezone_get());

        date_default_timezone_set($originalTz);
    }

    public function testInitializeTimezoneDoesNotOverrideWhenNotConfigured(): void
    {
        $originalTz = date_default_timezone_get();
        date_default_timezone_set('Asia/Tokyo');

        $configMock = $this->createMock(ConfigurationManager::class);
        $configMock->method('get')
            ->with('misc', 'timezone')
            ->willReturn(null);

        initializeTimezone($configMock);

        // With no configured timezone and php.ini timezone set, existing timezone is preserved
        $this->assertEquals('Asia/Tokyo', date_default_timezone_get());

        date_default_timezone_set($originalTz);
    }
}
