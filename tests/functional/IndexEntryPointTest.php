<?php

declare(strict_types=1);

namespace Poweradmin\Tests\Functional;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Http\RequestContext;
use Poweradmin\Application\Routing\SymfonyRouter;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Pages;
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

        // Ensure helper functions are available
        require_once __DIR__ . '/../../lib/Application/Helpers/StartupHelpers.php';
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->originalServer;
        $_REQUEST = $this->originalRequest;
        parent::tearDown();
    }

    /**
     * Test that helper functions are properly loaded and accessible
     */
    public function testHelperFunctionsAreLoaded(): void
    {
        $this->assertTrue(
            function_exists('initializeSession'),
            'initializeSession() function should be available'
        );
        $this->assertTrue(
            function_exists('initializeTimezone'),
            'initializeTimezone() function should be available'
        );
    }

    /**
     * A configuration failure happens before the router exists, so it can only be
     * shaped if the whole bootstrap sits inside the front controller's try block.
     */
    public function testBootstrapFailureIsShapedInsteadOfEscapingAsAFatal(): void
    {
        $repositoryRoot = dirname(__DIR__, 2);

        $process = proc_open(
            [PHP_BINARY, $repositoryRoot . '/index.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repositoryRoot,
            [
                'PATH' => getenv('PATH'),
                'PA_CONFIG_PATH' => __DIR__ . '/fixtures/throwing-settings.php',
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/api/v2/zones',
                'HTTP_ACCEPT' => 'application/json',
                'SERVER_NAME' => 'localhost',
                'SERVER_PORT' => '80',
            ]
        );

        $this->assertIsResource($process, 'Failed to start the front controller subprocess');

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $this->assertSame(0, $exitCode, 'A configuration failure must not exit as an uncaught fatal');
        $this->assertSame('{"success":false,"data":null,"message":"Internal server error"}', $stdout);
        $this->assertStringContainsString('Simulated configuration failure', $stderr);
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
        $this->assertIsCallable('initializeSession');
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
        $router = new SymfonyRouter();
        $this->assertInstanceOf('Poweradmin\Application\Routing\SymfonyRouter', $router);
    }

    /**
     * Test Pages class integration
     */
    public function testPagesIntegration(): void
    {
        // Test that Pages class exists and has getPages method
        $this->assertTrue(class_exists('Poweradmin\Pages'), 'Pages class should be available');
        $this->assertTrue(
            method_exists('Poweradmin\Pages', 'getPages'),
            'Pages::getPages() method should be available'
        );

        // Test that getPages returns an array
        $pages = Pages::getPages();
        $this->assertIsArray($pages, 'Pages::getPages() should return an array');
    }

    /**
     * Test BaseController integration
     */
    public function testBaseControllerIntegration(): void
    {
        // Test that BaseController class exists
        $this->assertTrue(
            class_exists('Poweradmin\BaseController'),
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
            'Poweradmin\Pages',
            'Poweradmin\BaseController',
            'Poweradmin\Application\Controller\NotFoundController',
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
        $router = new SymfonyRouter();

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
        $router = new SymfonyRouter();
        $pages = Pages::getPages();
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
            $router = new SymfonyRouter();
            $this->assertInstanceOf('Poweradmin\Application\Routing\SymfonyRouter', $router);
        }

        // RequestContext JSON detection
        $_SERVER['REQUEST_URI'] = '/api/test';
        $this->assertTrue(RequestContext::expectsJson());

        $_SERVER['REQUEST_URI'] = '/dashboard';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';
        $this->assertFalse(RequestContext::expectsJson());
    }
}
