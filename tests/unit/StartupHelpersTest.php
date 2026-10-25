<?php

declare(strict_types=1);

namespace Poweradmin\Tests\Unit;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
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

    #[RunInSeparateProcess]
    public function testInitializeSessionRefusesSessionIdsTheServerNeverIssued(): void
    {
        ini_set('session.use_strict_mode', '0');
        ini_set('session.save_path', sys_get_temp_dir());

        $configMock = $this->createMock(ConfigurationManager::class);
        $configMock->method('get')->willReturnCallback(fn (string $group, string $key, mixed $default = null): mixed => $default);

        $_COOKIE[session_name()] = 'planted0session0id0never0issued';

        initializeSession($configMock);
        $issuedId = session_id();
        session_destroy();

        $this->assertSame('1', ini_get('session.use_strict_mode'));
        $this->assertNotSame('planted0session0id0never0issued', $issuedId);
    }
}
