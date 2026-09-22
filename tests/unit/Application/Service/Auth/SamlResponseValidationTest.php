<?php

namespace Poweradmin\Tests\Unit\Application\Service\Auth;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Poweradmin\Application\Service\Web\AuditService;
use Poweradmin\Application\Service\Auth\SamlService;
use Poweradmin\Application\Service\Auth\SamlConfigurationService;
use Poweradmin\Application\Service\Auth\UserProvisioningService;
use Poweradmin\Application\Http\Request;
use Poweradmin\Domain\Service\Auth\MfaService;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Infrastructure\Logger\Logger;
use Poweradmin\Application\Service\Auth\AuthenticationService;
use ReflectionClass;
use ReflectionMethod;
use Poweradmin\Infrastructure\Session\ArraySession;

class SamlResponseValidationTest extends TestCase
{
    private ArraySession $session;

    private SamlService $service;
    private ConfigurationInterface&MockObject $mockConfig;
    private Logger|MockObject $mockLogger;
    private SamlConfigurationService|MockObject $mockSamlConfig;
    private UserProvisioningService|MockObject $mockUserProvisioning;
    private Request|MockObject $mockRequest;

    protected function setUp(): void
    {
        $this->session = new ArraySession();
        $this->mockConfig = $this->createMock(ConfigurationInterface::class);
        $this->mockLogger = $this->createMock(Logger::class);
        $this->mockSamlConfig = $this->createMock(SamlConfigurationService::class);
        $this->mockUserProvisioning = $this->createMock(UserProvisioningService::class);
        $this->mockRequest = $this->createMock(Request::class);

        $this->service = new SamlService(
            $this->mockConfig,
            $this->mockSamlConfig,
            $this->mockUserProvisioning,
            $this->mockLogger,
            $this->createMock(AuthenticationService::class),
            $this->createMock(AuditService::class),
            $this->createMock(MfaService::class),
            $this->session,
            $this->mockRequest
        );
    }

    protected function tearDown(): void
    {
        // Clean up session after each test
        $this->session = new ArraySession();
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

    /**
     * Helper method to get private method via reflection
     */
    public function testProxyServerVarsAreForcedForTheCallAndRestoredAfterIt(): void
    {
        $method = $this->getPrivateMethod('withProxyServerVars');
        $_SERVER = ['HTTP_X_FORWARDED_PROTO' => 'https', 'HTTPS' => 'off', 'SERVER_PORT' => '8080'];

        $seen = null;
        $result = $method->invoke($this->service, function () use (&$seen) {
            $seen = [$_SERVER['HTTPS'], $_SERVER['SERVER_PORT']];
            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertSame(['on', '443'], $seen);
        $this->assertSame('off', $_SERVER['HTTPS']);
        $this->assertSame('8080', $_SERVER['SERVER_PORT']);
    }

    public function testProxyServerVarsAreRestoredWhenTheCallThrows(): void
    {
        $method = $this->getPrivateMethod('withProxyServerVars');
        $_SERVER = ['HTTP_X_FORWARDED_PROTO' => 'https'];

        try {
            $method->invoke($this->service, function () {
                throw new \RuntimeException('boom');
            });
            $this->fail('Expected the exception to propagate.');
        } catch (\RuntimeException) {
            // expected
        }

        // Absent before the call, so they must be absent again rather than left forced.
        $this->assertArrayNotHasKey('HTTPS', $_SERVER);
        $this->assertArrayNotHasKey('SERVER_PORT', $_SERVER);
    }

    public function testProxyServerVarsAreLeftAloneOutsideAReverseProxy(): void
    {
        $method = $this->getPrivateMethod('withProxyServerVars');
        $this->mockConfig->method('get')->willReturn([]);
        $_SERVER = ['SERVER_PORT' => '80'];

        $seen = null;
        $method->invoke($this->service, function () use (&$seen) {
            $seen = $_SERVER;
        });

        $this->assertSame(['SERVER_PORT' => '80'], $seen);
        $this->assertSame(['SERVER_PORT' => '80'], $_SERVER);
    }

    private function getPrivateMethod(string $methodName): ReflectionMethod
    {
        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method;
    }
}
