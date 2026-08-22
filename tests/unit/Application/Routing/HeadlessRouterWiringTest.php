<?php

namespace Poweradmin\Tests\Unit\Application\Routing;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Routing\SymfonyRouter;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use ReflectionClass;

/**
 * End-to-end wiring for interface.web_enabled, from the config file through the router
 * to the controller that answers.
 *
 * HeadlessRouteFilterTest covers the allowlist itself; this covers that the router reads
 * the setting at all and that a blocked path lands on the database-free responder.
 */
class HeadlessRouterWiringTest extends TestCase
{
    private string $configFile;
    private array $serverBackup;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configFile = sys_get_temp_dir() . '/pa-headless-settings.php';
        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void
    {
        @unlink($this->configFile);
        putenv('PA_CONFIG_PATH');
        $_SERVER = $this->serverBackup;
        $this->resetConfigurationManager();

        parent::tearDown();
    }

    public function testHeadlessSendsWebPathsToTheDatabaseFreeResponder(): void
    {
        $this->assertSame(
            '\Poweradmin\Application\Controller\Api\HeadlessNotFoundController',
            $this->controllerFor('/login', false)
        );
    }

    public function testHeadlessKeepsTheApiReachable(): void
    {
        $this->assertStringContainsString('Api\V2\ZonesController', $this->controllerFor('/api/v2/zones', false));
    }

    public function testWebInterfaceIsUnaffectedByDefault(): void
    {
        $this->assertStringContainsString('LoginController', $this->controllerFor('/login', true));
    }

    public function testWebInterfaceKeepsTheHtmlNotFoundPage(): void
    {
        $this->assertSame(
            '\Poweradmin\Application\Controller\NotFoundController',
            $this->controllerFor('/no-such-page', true)
        );
    }

    /**
     * Boot the router against a config file holding only the flag under test, and report
     * which controller the path resolves to.
     */
    private function controllerFor(string $path, bool $webEnabled): string
    {
        file_put_contents(
            $this->configFile,
            sprintf("<?php return ['interface' => ['web_enabled' => %s]];", $webEnabled ? 'true' : 'false')
        );
        putenv('PA_CONFIG_PATH=' . $this->configFile);

        $this->resetConfigurationManager();
        ConfigurationManager::getInstance()->initialize();

        $_SERVER['REQUEST_URI'] = $path;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['HTTP_HOST'] = 'localhost';

        return (new SymfonyRouter())->match()['controller'];
    }

    /**
     * The manager is a singleton, so each case has to start from a clean one.
     */
    private function resetConfigurationManager(): void
    {
        $reflection = new ReflectionClass(ConfigurationManager::class);

        $instance = $reflection->getProperty('instance');
        $instance->setAccessible(true);
        $instance->setValue(null, null);

        $initialized = $reflection->getProperty('initialized');
        $initialized->setAccessible(true);
        $initialized->setValue(ConfigurationManager::getInstance(), false);
    }
}
