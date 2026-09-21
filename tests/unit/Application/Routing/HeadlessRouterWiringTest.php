<?php

namespace Poweradmin\Tests\Unit\Application\Routing;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Routing\SymfonyRouter;
use TestHelpers\FakeConfiguration;

/**
 * Wiring for interface.web_enabled, from the configuration the router is given
 * to the controller that answers.
 *
 * HeadlessRouteFilterTest covers the allowlist itself; this covers that the router reads
 * the setting at all and that a blocked path lands on the database-free responder.
 */
class HeadlessRouterWiringTest extends TestCase
{
    private array $serverBackup;

    protected function setUp(): void
    {
        parent::setUp();

        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;

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

    public function testBaseUrlPrefixComesFromTheGivenConfiguration(): void
    {
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['HTTP_HOST'] = 'localhost';

        $router = new SymfonyRouter(new FakeConfiguration(['interface' => ['base_url_prefix' => '/dns']]));

        $this->assertSame('/dns/login', $router->generateUrl('login'));
    }

    /**
     * Boot the router with only the flag under test set, and report which controller
     * the path resolves to.
     */
    private function controllerFor(string $path, bool $webEnabled): string
    {
        $_SERVER['REQUEST_URI'] = $path;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['HTTP_HOST'] = 'localhost';

        $config = new FakeConfiguration(['interface' => ['web_enabled' => $webEnabled]]);

        return (new SymfonyRouter($config))->match()['controller'];
    }
}
