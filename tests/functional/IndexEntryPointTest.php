<?php

declare(strict_types=1);

namespace Poweradmin\Tests\Functional;

use PHPUnit\Framework\TestCase;

/**
 * Functional tests for index.php entry point
 *
 * These tests verify the main application entry point handles various
 * scenarios correctly after refactoring. Tests focus on:
 * - Session initialization
 * - Configuration loading
 * - Router setup
 * - Error handling integration
 * - Security measures
 *
 * Note: These tests may require database connection mocking or
 * environment-specific configuration for full functionality.
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
            function_exists('sendJsonError'),
            'sendJsonError() function should be available'
        );
        $this->assertTrue(
            function_exists('displayHtmlError'),
            'displayHtmlError() function should be available'
        );
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
     * Test error handling integration with various scenarios
     */
    public function testErrorHandlingIntegration(): void
    {
        // Test JSON error output
        ob_start();
        sendJsonError("Test API error", "/api/endpoint.php", 123, ["API call failed"]);
        $jsonOutput = ob_get_clean();

        $this->assertJson($jsonOutput);
        $decoded = json_decode($jsonOutput, true);
        $this->assertArrayHasKey('error', $decoded);
        $this->assertTrue($decoded['error']);

        // Test HTML error output
        $testException = new \Exception("Test web error");
        ob_start();
        displayHtmlError($testException);
        $htmlOutput = ob_get_clean();

        $this->assertStringContainsString('<pre>', $htmlOutput);
        $this->assertStringContainsString('Test web error', $htmlOutput);
    }

    /**
     * Test security measures in error handling
     */
    public function testErrorHandlingSecurity(): void
    {
        // Test XSS protection in HTML errors
        $xssException = new \Exception("<script>alert('xss')</script>");
        ob_start();
        displayHtmlError($xssException);
        $output = ob_get_clean();

        $this->assertStringNotContainsString('<script>alert(', $output);
        $this->assertStringContainsString('&lt;script&gt;', $output);

        // Test that file paths in production don't expose sensitive info
        ob_start();
        sendJsonError("Production error", "/var/www/poweradmin/config/database.php", 50, []);
        $jsonOutput = ob_get_clean();

        $decoded = json_decode($jsonOutput, true);
        // In production, file paths might be filtered - this tests the structure
        $this->assertArrayHasKey('file', $decoded);
        $this->assertArrayHasKey('message', $decoded);
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
        $config1 = \Poweradmin\Infrastructure\Configuration\ConfigurationManager::getInstance();
        $config2 = \Poweradmin\Infrastructure\Configuration\ConfigurationManager::getInstance();
        $this->assertSame($config1, $config2, 'ConfigurationManager should be singleton');
    }

    /**
     * Test router integration
     */
    public function testRouterIntegration(): void
    {
        // Test that BasicRouter class exists and is loadable
        $this->assertTrue(
            class_exists('Poweradmin\Application\Routing\BasicRouter'),
            'BasicRouter should be available'
        );

        // Test router instantiation with empty request
        $_REQUEST = [];
        $router = new \Poweradmin\Application\Routing\BasicRouter($_REQUEST);
        $this->assertInstanceOf('Poweradmin\Application\Routing\BasicRouter', $router);
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
        $pages = \Poweradmin\Pages::getPages();
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
            method_exists('Poweradmin\BaseController', 'expectsJson'),
            'BaseController::expectsJson() should be available'
        );

        $reflection = new \ReflectionMethod('Poweradmin\BaseController', 'expectsJson');
        $this->assertTrue(
            $reflection->isStatic(),
            'BaseController::expectsJson() should be static'
        );
    }

    /**
     * Test that all required classes are autoloadable
     */
    public function testAutoloadingIntegration(): void
    {
        $requiredClasses = [
            'Poweradmin\Application\Routing\BasicRouter',
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
        $_REQUEST = ['page' => 'dashboard'];
        $_SERVER['REQUEST_URI'] = '/dashboard';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';

        // Test BaseController JSON detection
        $expectsJson = \Poweradmin\BaseController::expectsJson();
        $this->assertFalse($expectsJson, 'Dashboard request should not expect JSON');

        // Test router setup
        $router = new \Poweradmin\Application\Routing\BasicRouter($_REQUEST);
        $router->setDefaultPage('index');
        $router->setPages(\Poweradmin\Pages::getPages());

        // Verify router is properly configured
        $this->assertInstanceOf('Poweradmin\Application\Routing\BasicRouter', $router);
    }

    /**
     * Test memory usage of initialization process
     */
    public function testInitializationMemoryUsage(): void
    {
        $memoryBefore = memory_get_usage();

        // Simulate key initialization steps
        \Poweradmin\Infrastructure\Configuration\ConfigurationManager::getInstance();
        $router = new \Poweradmin\Application\Routing\BasicRouter([]);
        $pages = \Poweradmin\Pages::getPages();
        \Poweradmin\BaseController::expectsJson();

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
     * Test error handling with different exception types
     */
    public function testErrorHandlingWithDifferentExceptions(): void
    {
        $exceptionTypes = [
            new \Exception("General exception"),
            new \RuntimeException("Runtime error"),
            new \InvalidArgumentException("Invalid argument"),
            new \ErrorException("PHP error", 0, E_ERROR, __FILE__, __LINE__),
        ];

        foreach ($exceptionTypes as $exception) {
            // Test HTML error display
            ob_start();
            displayHtmlError($exception);
            $htmlOutput = ob_get_clean();

            $this->assertStringContainsString($exception->getMessage(), $htmlOutput);
            $this->assertStringContainsString('Error:', $htmlOutput);
            $this->assertStringContainsString('File:', $htmlOutput);
            $this->assertStringContainsString('Line:', $htmlOutput);

            // Test JSON error (simulating what index.php would do)
            ob_start();
            sendJsonError(
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine(),
                explode("\n", $exception->getTraceAsString())
            );
            $jsonOutput = ob_get_clean();

            $decoded = json_decode($jsonOutput, true);
            $this->assertIsArray($decoded);
            $this->assertEquals($exception->getMessage(), $decoded['message']);
        }
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
        $config = \Poweradmin\Infrastructure\Configuration\ConfigurationManager::getInstance();
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

        foreach ($testRequests as $request) {
            $router = new \Poweradmin\Application\Routing\BasicRouter($request);
            $this->assertInstanceOf('Poweradmin\Application\Routing\BasicRouter', $router);
        }

        // BaseController JSON detection
        $_SERVER['REQUEST_URI'] = '/api/test';
        $this->assertTrue(\Poweradmin\BaseController::expectsJson());

        $_SERVER['REQUEST_URI'] = '/dashboard';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';
        $this->assertFalse(\Poweradmin\BaseController::expectsJson());
    }
}
