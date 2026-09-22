<?php

declare(strict_types=1);

namespace Poweradmin\Tests\Functional;

use Poweradmin\Application\Bootstrap;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Http\RequestContext;
use Poweradmin\Application\Routing\SymfonyRouter;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use ReflectionMethod;

/**
 * Functional tests for index.php entry point
 *
 * These tests verify the main application entry point handles various
 * scenarios correctly after refactoring. Tests focus on:
 * - Session initialization
 * - Configuration loading
 * - Router setup
 * - Bootstrap failures reaching the error responder
 * - Halted requests (showError) sending a complete response and nothing more
 *
 * Error-response shaping itself is covered by BootstrapErrorResponderTest.
 */
class IndexEntryPointTest extends TestCase
{
    private array $originalServer;
    private array $originalRequest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalServer = $_SERVER;
        $this->originalRequest = $_REQUEST;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->originalServer;
        $_REQUEST = $this->originalRequest;
        parent::tearDown();
    }

    public function testBootstrapEntryPointsExist(): void
    {
        $this->assertTrue(method_exists(Bootstrap::class, 'initializeSession'));
        $this->assertTrue(method_exists(Bootstrap::class, 'initializeTimezone'));
    }

    /**
     * A configuration failure happens before the router exists, so it can only be
     * shaped if the whole bootstrap sits inside the front controller's try block.
     */
    public function testBootstrapFailureIsShapedInsteadOfEscapingAsAFatal(): void
    {
        [$exitCode, $stdout, $stderr] = $this->runFrontController('throwing-settings.php', '/api/v2/zones', 'application/json');

        $this->assertSame(0, $exitCode, 'A configuration failure must not exit as an uncaught fatal');
        $this->assertSame('{"success":false,"data":null,"message":"Internal server error"}', $stdout);
        $this->assertStringContainsString('Simulated configuration failure', $stderr);
    }

    /**
     * showError() writes the whole error page and then ends the request; what
     * follows in the controller must never reach the client, and neither must
     * the error responder's own output.
     */
    public function testAHaltedRequestSendsTheErrorPageAndNothingElse(): void
    {
        [$exitCode, $stdout, $stderr] = $this->runFrontController('halting-settings.php', '/password/forgot', 'text/html');

        $this->assertSame(0, $exitCode);
        $this->assertSame('', $stderr);
        $this->assertStringStartsWith('<!doctype html>', $stdout);
        $this->assertStringEndsWith("</html>\n", $stdout);
        $this->assertSame(1, substr_count($stdout, 'Password reset functionality is disabled.'));
    }

    public function testAHaltedJsonRequestSendsTheErrorBodyAndNothingElse(): void
    {
        [$exitCode, $stdout, $stderr] = $this->runFrontController('halting-settings.php', '/password/forgot', 'application/json');

        $this->assertSame(0, $exitCode);
        $this->assertSame('', $stderr);
        $this->assertSame('{"error":true,"message":"Password reset functionality is disabled."}', $stdout);
    }

    /**
     * Drives index.php in a subprocess with the given settings fixture.
     *
     * @return array{0: int, 1: string, 2: string} Exit code, stdout, stderr
     */
    private function runFrontController(string $settingsFixture, string $uri, string $accept): array
    {
        $repositoryRoot = dirname(__DIR__, 2);

        $process = proc_open(
            [PHP_BINARY, $repositoryRoot . '/index.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repositoryRoot,
            [
                'PATH' => getenv('PATH'),
                'PA_CONFIG_PATH' => __DIR__ . '/fixtures/' . $settingsFixture,
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => $uri,
                'HTTP_ACCEPT' => $accept,
                'SERVER_NAME' => 'localhost',
                'SERVER_PORT' => '80',
            ]
        );

        $this->assertIsResource($process, 'Failed to start the front controller subprocess');

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    /**
     * Test session initialization function
     */
    public function testSessionInitialization(): void
    {
        // Test that session functions are available
        $this->assertTrue(
            function_exists('session_start'),
            'PHP session extension should be available'
        );

        // Test session configuration (without actually starting session to avoid conflicts)
        $originalParams = session_get_cookie_params();

        // The actual session initialization is tested in integration context
        // Here we just verify the function exists and can be called
        $this->assertIsCallable([Bootstrap::class, 'initializeSession']);
    }

    /**
     * Test configuration manager integration
     */
    public function testConfigurationManagerIntegration(): void
    {
        // Test that ConfigurationManager class exists and is loadable
        $this->assertTrue(
            class_exists('Poweradmin\Infrastructure\Configuration\ConfigurationManager'),
            'ConfigurationManager should be available'
        );

        // Test that the singleton pattern works
        $config1 = ConfigurationManager::getInstance();
        $config2 = ConfigurationManager::getInstance();
        $this->assertSame($config1, $config2, 'ConfigurationManager should be singleton');
    }

    /**
     * Test router integration
     */
    public function testRouterIntegration(): void
    {
        // Test that SymfonyRouter class exists and is loadable
        $this->assertTrue(
            class_exists('Poweradmin\Application\Routing\SymfonyRouter'),
            'SymfonyRouter should be available'
        );

        // Set up minimal $_SERVER variables for SymfonyRouter
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['SERVER_NAME'] = 'localhost';
        $_SERVER['SERVER_PORT'] = '80';
        $_SERVER['HTTPS'] = '';
        $_SERVER['REQUEST_URI'] = '/';

        // Test router instantiation
        $router = new SymfonyRouter(ConfigurationManager::getInstance());
        $this->assertInstanceOf('Poweradmin\Application\Routing\SymfonyRouter', $router);
    }

    /**
     * Test BaseController integration
     */
    public function testBaseControllerIntegration(): void
    {
        // Test that BaseController class exists
        $this->assertTrue(
            class_exists('Poweradmin\Application\Controller\BaseController'),
            'BaseController should be available'
        );

        // Test that expectsJson method exists and is static
        $this->assertTrue(
            method_exists('Poweradmin\Application\Http\RequestContext', 'expectsJson'),
            'RequestContext::expectsJson() should be available'
        );

        $reflection = new ReflectionMethod('Poweradmin\Application\Http\RequestContext', 'expectsJson');
        $this->assertTrue(
            $reflection->isStatic(),
            'RequestContext::expectsJson() should be static'
        );
    }

    /**
     * Test that all required classes are autoloadable
     */
    public function testAutoloadingIntegration(): void
    {
        $requiredClasses = [
            'Poweradmin\Application\Routing\SymfonyRouter',
            'Poweradmin\Infrastructure\Configuration\ConfigurationManager',
            'Poweradmin\Application\Controller\BaseController',
            'Poweradmin\Application\Controller\System\NotFoundController',
        ];

        foreach ($requiredClasses as $className) {
            $this->assertTrue(
                class_exists($className),
                "Required class should be autoloadable: {$className}"
            );
        }
    }

    /**
     * Test request processing flow (without actual HTTP)
     */
    public function testRequestProcessingFlow(): void
    {
        // Set up a test request
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['SERVER_NAME'] = 'localhost';
        $_SERVER['SERVER_PORT'] = '80';
        $_SERVER['HTTPS'] = '';

        // Test RequestContext JSON detection
        $expectsJson = RequestContext::expectsJson();
        $this->assertFalse($expectsJson, 'Home page request should not expect JSON');

        // Test router setup
        $router = new SymfonyRouter(ConfigurationManager::getInstance());

        // Verify router is properly configured
        $this->assertInstanceOf('Poweradmin\Application\Routing\SymfonyRouter', $router);

        // Test route matching
        $routeInfo = $router->match();
        $this->assertIsArray($routeInfo);
        $this->assertArrayHasKey('controller', $routeInfo);
    }

    /**
     * Test memory usage of initialization process
     */
    public function testInitializationMemoryUsage(): void
    {
        $memoryBefore = memory_get_usage();

        // Set up minimal $_SERVER for SymfonyRouter
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['SERVER_NAME'] = 'localhost';
        $_SERVER['SERVER_PORT'] = '80';
        $_SERVER['HTTPS'] = '';
        $_SERVER['REQUEST_URI'] = '/';

        // Simulate key initialization steps
        ConfigurationManager::getInstance();
        $router = new SymfonyRouter(ConfigurationManager::getInstance());
        RequestContext::expectsJson();

        $memoryAfter = memory_get_usage();
        $memoryUsed = $memoryAfter - $memoryBefore;

        // Should not use excessive memory for initialization
        $this->assertLessThan(
            10 * 1024 * 1024,
            $memoryUsed,
            "Initialization should not use excessive memory: {$memoryUsed} bytes"
        );
    }

    /**
     * Test session cookie security settings
     */
    public function testSessionSecuritySettings(): void
    {
        // Get current session cookie parameters
        $params = session_get_cookie_params();

        // Test that we can configure secure session settings
        // (Note: actual session_set_cookie_params call is in initializeSession)
        $this->assertIsArray($params);
        $this->assertArrayHasKey('secure', $params);
        $this->assertArrayHasKey('httponly', $params);

        // The actual security depends on HTTPS detection in initializeSession
        // This test verifies the structure is available
    }

    /**
     * Test that refactored code maintains backward compatibility
     */
    public function testBackwardCompatibility(): void
    {
        // Test that all previously working functionality still works

        // ConfigurationManager singleton
        $config = ConfigurationManager::getInstance();
        $this->assertInstanceOf(
            'Poweradmin\Infrastructure\Configuration\ConfigurationManager',
            $config
        );

        // Router with various request types
        $testRequests = [
            [],
            ['page' => 'index'],
            ['page' => 'zones', 'action' => 'edit'],
        ];

        // Set up $_SERVER for SymfonyRouter
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['SERVER_NAME'] = 'localhost';
        $_SERVER['SERVER_PORT'] = '80';
        $_SERVER['HTTPS'] = '';

        foreach ($testRequests as $request) {
            // SymfonyRouter doesn't take request in constructor, uses $_SERVER
            $_SERVER['REQUEST_URI'] = $request['page'] ?? '/';
            $router = new SymfonyRouter(ConfigurationManager::getInstance());
            $this->assertInstanceOf('Poweradmin\Application\Routing\SymfonyRouter', $router);
        }

        // RequestContext JSON detection. The path has to name a real API family:
        // isApiPath() matches per segment so web pages under /api/ still get HTML.
        $_SERVER['HTTP_ACCEPT'] = 'text/html';

        $_SERVER['REQUEST_URI'] = '/api/v2/zones';
        $this->assertTrue(RequestContext::expectsJson());

        $_SERVER['REQUEST_URI'] = '/api/test';
        $this->assertFalse(RequestContext::expectsJson());

        $_SERVER['REQUEST_URI'] = '/dashboard';
        $this->assertFalse(RequestContext::expectsJson());
    }
}
