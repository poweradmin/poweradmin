<?php

namespace Poweradmin\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Poweradmin\Application\Service\SamlService;
use Poweradmin\Application\Service\SamlConfigurationService;
use Poweradmin\Application\Service\UserProvisioningService;
use Poweradmin\Application\Http\Request;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use PDO;
use Poweradmin\Infrastructure\Logger\Logger;
use ReflectionClass;
use ReflectionMethod;

class SamlResponseValidationTest extends TestCase
{
    private SamlService $service;
    private ConfigurationManager|MockObject $mockConfig;
    private Logger|MockObject $mockLogger;
    private SamlConfigurationService|MockObject $mockSamlConfig;
    private UserProvisioningService|MockObject $mockUserProvisioning;
    private PDO|MockObject $mockDb;
    private Request|MockObject $mockRequest;

    protected function setUp(): void
    {
        $this->mockConfig = $this->createMock(ConfigurationManager::class);
        $this->mockLogger = $this->createMock(Logger::class);
        $this->mockSamlConfig = $this->createMock(SamlConfigurationService::class);
        $this->mockUserProvisioning = $this->createMock(UserProvisioningService::class);
        $this->mockDb = $this->createMock(PDO::class);
        $this->mockRequest = $this->createMock(Request::class);

        $this->service = new SamlService(
            $this->mockConfig,
            $this->mockSamlConfig,
            $this->mockUserProvisioning,
            $this->mockLogger,
            $this->mockDb,
            $this->mockRequest
        );

        // Initialize session for testing
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    protected function tearDown(): void
    {
        // Clean up session after each test
        $_SESSION = [];
        $_POST = [];
        $_SERVER = [];
    }

    public function testIsReverseProxyEnvironmentDetection(): void
    {
        $method = $this->getPrivateMethod('isReverseProxyEnvironment');

        // Test with no proxy headers
        $_SERVER = [];
        $this->assertFalse($method->invoke($this->service), 'Should not detect reverse proxy without headers');

        // Test with X-Forwarded-Proto header
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $this->assertTrue($method->invoke($this->service), 'Should detect reverse proxy with X-Forwarded-Proto');

        // Reset and test with X-Forwarded-Host
        $_SERVER = ['HTTP_X_FORWARDED_HOST' => 'example.com'];
        $this->assertTrue($method->invoke($this->service), 'Should detect reverse proxy with X-Forwarded-Host');

        // Test with CF-Visitor (Cloudflare)
        $_SERVER = ['HTTP_CF_VISITOR' => '{"scheme":"https"}'];
        $this->assertTrue($method->invoke($this->service), 'Should detect reverse proxy with CF-Visitor');

        // Test with X-Real-IP
        $_SERVER = ['HTTP_X_REAL_IP' => '192.168.1.1'];
        $this->assertTrue($method->invoke($this->service), 'Should detect reverse proxy with X-Real-IP');

        // Test with standard Forwarded header (RFC 7239)
        $_SERVER = ['HTTP_FORWARDED' => 'for=192.0.2.60;proto=http;by=203.0.113.43'];
        $this->assertTrue($method->invoke($this->service), 'Should detect reverse proxy with Forwarded header');
    }

    public function testReverseProxyEnvironmentWithHttpsConfigMismatch(): void
    {
        $method = $this->getPrivateMethod('isReverseProxyEnvironment');

        // Setup configuration with HTTPS ACS URL but no HTTPS in environment
        $this->mockConfig->method('get')
            ->with('saml', 'sp', [])
            ->willReturn([
                'assertion_consumer_service_url' => 'https://poweradmin.example.com/saml/acs'
            ]);

        $_SERVER = []; // No HTTPS environment variable
        $this->assertTrue($method->invoke($this->service), 'Should detect reverse proxy when config expects HTTPS but env does not');

        // Test with HTTPS environment variable set
        $_SERVER['HTTPS'] = 'on';
        $this->assertFalse($method->invoke($this->service), 'Should not detect reverse proxy when HTTPS matches config');
    }

    public function testReverseProxyEnvironmentWithForwardedProtoMismatch(): void
    {
        $method = $this->getPrivateMethod('isReverseProxyEnvironment');

        // Test X-Forwarded-Proto HTTPS mismatch
        $_SERVER = [
            'HTTP_X_FORWARDED_PROTO' => 'https'
            // No HTTPS environment variable
        ];
        $this->assertTrue($method->invoke($this->service), 'Should detect reverse proxy with X-Forwarded-Proto HTTPS mismatch');

        // Test with matching HTTPS
        $_SERVER['HTTPS'] = 'on';
        $this->assertTrue($method->invoke($this->service), 'Should still detect reverse proxy presence even when HTTPS matches');
    }

    public function testCurrentUrlIsTheAdvertisedEndpointBehindAPortMapping(): void
    {
        // nginx inside the container listens on 80; the host publishes it as 8089
        $_SERVER = ['SERVER_PORT' => '80', 'HTTP_HOST' => 'localhost:8089', 'REQUEST_URI' => '/saml/acs', 'SCRIPT_NAME' => '/index.php'];

        $seen = $this->getPrivateMethod('withSelfUrl')
            ->invoke($this->service, fn() => \OneLogin\Saml2\Utils::getSelfRoutedURLNoQuery(), 'http://localhost:8089/saml/acs');

        $this->assertSame('http://localhost:8089/saml/acs', $seen);
        // The pin is static state, so it must not outlive the call
        $this->assertSame('80', \OneLogin\Saml2\Utils::getSelfPort());
    }

    public function testCurrentUrlKeepsThePathPrefixAProxyStripped(): void
    {
        $_SERVER = ['SERVER_PORT' => '80', 'HTTP_HOST' => 'internal', 'REQUEST_URI' => '/saml/acs?x=1', 'SCRIPT_NAME' => '/index.php'];

        $seen = $this->getPrivateMethod('withSelfUrl')
            ->invoke($this->service, fn() => \OneLogin\Saml2\Utils::getSelfRoutedURLNoQuery(), 'https://dns.example.com/poweradmin/saml/acs');

        $this->assertSame('https://dns.example.com/poweradmin/saml/acs', $seen);
        // The base path is static state too
        $this->assertSame('http://internal/saml/acs', \OneLogin\Saml2\Utils::getSelfRoutedURLNoQuery());
    }

    public function testCurrentUrlIsUnchangedWhenThePrefixReachesPhp(): void
    {
        $_SERVER = ['SERVER_PORT' => '80', 'HTTP_HOST' => 'internal', 'REQUEST_URI' => '/poweradmin/saml/acs', 'SCRIPT_NAME' => '/poweradmin/index.php'];

        $seen = $this->getPrivateMethod('withSelfUrl')
            ->invoke($this->service, fn() => \OneLogin\Saml2\Utils::getSelfRoutedURLNoQuery(), 'https://dns.example.com/poweradmin/saml/acs');

        $this->assertSame('https://dns.example.com/poweradmin/saml/acs', $seen);
    }

    public function testSingleLogoutIsCheckedAgainstTheSlsUrlEvenOnAnotherHost(): void
    {
        $this->mockSamlConfig->method('getServiceProviderConfig')->willReturn([
            'assertion_consumer_service_url' => 'https://dns.example.com/saml/acs',
            'single_logout_service_url' => 'https://sso.example.com/saml/sls',
        ]);
        $_SERVER = ['SERVER_PORT' => '80', 'HTTP_HOST' => 'internal', 'REQUEST_URI' => '/saml/sls', 'SCRIPT_NAME' => '/index.php'];

        $sloUrl = $this->getPrivateMethod('getSloUrl')->invoke($this->service);
        $seen = $this->getPrivateMethod('withSelfUrl')
            ->invoke($this->service, fn() => \OneLogin\Saml2\Utils::getSelfRoutedURLNoQuery(), $sloUrl);

        $this->assertSame('https://sso.example.com/saml/sls', $seen);
    }

    /**
     * Helper method to get private method via reflection
     */
    private function getPrivateMethod(string $methodName): ReflectionMethod
    {
        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method;
    }
}
