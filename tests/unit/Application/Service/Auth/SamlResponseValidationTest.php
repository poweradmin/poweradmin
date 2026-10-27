<?php

namespace Poweradmin\Tests\Unit\Application\Service\Auth;

use OneLogin\Saml2\Auth;
use PHPUnit\Framework\Attributes\BackupGlobals;
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

#[BackupGlobals(true)]
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
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
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

    public function testCurrentUrlIsTheAdvertisedAcsUrlBehindAPortMapping(): void
    {
        $method = $this->getPrivateMethod('withProxyServerVars');
        $this->mockConfig->method('get')->willReturn([]);
        $this->mockSamlConfig->method('getServiceProviderConfig')
            ->willReturn(['assertion_consumer_service_url' => 'http://localhost:8089/saml/acs']);
        // nginx inside the container listens on 80; the host publishes it as 8089
        $_SERVER = ['SERVER_PORT' => '80', 'HTTP_HOST' => 'localhost:8089', 'REQUEST_URI' => '/saml/acs', 'SCRIPT_NAME' => '/index.php'];

        $seen = $method->invoke($this->service, fn() => \OneLogin\Saml2\Utils::getSelfRoutedURLNoQuery(), 'http://localhost:8089/saml/acs');

        $this->assertSame('http://localhost:8089/saml/acs', $seen);
        // The pin is static state, so it must not outlive the call
        $this->assertSame('80', \OneLogin\Saml2\Utils::getSelfPort());
    }

    public function testCurrentUrlFollowsAnHttpsAcsUrlWithoutAPort(): void
    {
        $method = $this->getPrivateMethod('withProxyServerVars');
        $this->mockConfig->method('get')->willReturn([]);
        $this->mockSamlConfig->method('getServiceProviderConfig')
            ->willReturn(['assertion_consumer_service_url' => 'https://dns.example.com/saml/acs']);
        $_SERVER = ['SERVER_PORT' => '8080', 'HTTP_HOST' => 'internal:8080', 'REQUEST_URI' => '/saml/acs', 'SCRIPT_NAME' => '/index.php'];

        $seen = $method->invoke($this->service, fn() => \OneLogin\Saml2\Utils::getSelfRoutedURLNoQuery(), 'https://dns.example.com/saml/acs');

        $this->assertSame('https://dns.example.com/saml/acs', $seen);
    }

    public function testCurrentUrlKeepsThePathPrefixAProxyStripped(): void
    {
        $this->mockConfig->method('get')->willReturn([]);
        $_SERVER = ['SERVER_PORT' => '80', 'HTTP_HOST' => 'internal', 'REQUEST_URI' => '/saml/acs?x=1', 'QUERY_STRING' => 'x=1', 'SCRIPT_NAME' => '/index.php'];

        $seen = $this->routedUrlFor('https://dns.example.com/poweradmin/saml/acs');

        $this->assertSame('https://dns.example.com/poweradmin/saml/acs', $seen);
        $this->assertSame('/saml/acs?x=1', $_SERVER['REQUEST_URI']);
    }

    public function testCurrentUrlKeepsAPrefixThatRepeatsTheRoute(): void
    {
        $this->mockConfig->method('get')->willReturn([]);
        $_SERVER = ['SERVER_PORT' => '80', 'HTTP_HOST' => 'internal', 'REQUEST_URI' => '/saml/sls', 'SCRIPT_NAME' => '/index.php'];

        $seen = $this->routedUrlFor('https://dns.example.com/saml/saml/sls');

        $this->assertSame('https://dns.example.com/saml/saml/sls', $seen);
    }

    public function testCurrentUrlIsUnchangedWhenThePrefixReachesPhp(): void
    {
        $this->mockConfig->method('get')->willReturn([]);
        $_SERVER = ['SERVER_PORT' => '80', 'HTTP_HOST' => 'internal', 'REQUEST_URI' => '/poweradmin/saml/acs', 'SCRIPT_NAME' => '/poweradmin/index.php'];

        $seen = $this->routedUrlFor('https://dns.example.com/poweradmin/saml/acs');

        $this->assertSame('https://dns.example.com/poweradmin/saml/acs', $seen);
        $this->assertSame('/poweradmin/saml/acs', $_SERVER['REQUEST_URI']);
    }

    public function testRequestUriIsRestoredWhenTheCheckThrows(): void
    {
        $_SERVER = ['REQUEST_URI' => '/saml/acs'];

        try {
            $this->getPrivateMethod('withPublicRequestUri')->invoke($this->service, function (): never {
                throw new \RuntimeException('boom');
            }, 'https://dns.example.com/poweradmin/saml/acs');
            $this->fail('Expected the exception to propagate');
        } catch (\RuntimeException) {
        }

        $this->assertSame('/saml/acs', $_SERVER['REQUEST_URI']);
    }

    private function routedUrlFor(string $endpointUrl): string
    {
        $withUri = $this->getPrivateMethod('withPublicRequestUri');

        return $this->getPrivateMethod('withProxyServerVars')->invoke(
            $this->service,
            fn() => $withUri->invoke($this->service, fn() => \OneLogin\Saml2\Utils::getSelfRoutedURLNoQuery(), $endpointUrl),
            $endpointUrl
        );
    }

    public function testAssertionIsCheckedWithThePathPrefixAProxyStripped(): void
    {
        $this->mockConfig->method('get')->willReturn([]);
        $this->mockSamlConfig->method('getServiceProviderConfig')
            ->willReturn(['assertion_consumer_service_url' => 'https://dns.example.com/saml/saml/acs']);
        $this->mockSamlConfig->method('getProviderConfig')->willReturn(['allow_idp_initiated' => true]);
        $this->session->set('saml_provider', 'idp');
        $_SERVER = ['SERVER_PORT' => '80', 'HTTP_HOST' => 'internal', 'REQUEST_URI' => '/saml/acs', 'SCRIPT_NAME' => '/index.php'];

        $seen = null;
        $auth = $this->createMock(Auth::class);
        $auth->method('processResponse')->willReturnCallback(function () use (&$seen): void {
            $seen = \OneLogin\Saml2\Utils::getSelfRoutedURLNoQuery();
        });
        $auth->method('getErrors')->willReturn(['stop here']);

        $this->serviceWithAuth($auth)->handleAssertion();

        $this->assertSame('https://dns.example.com/saml/saml/acs', $seen);
        $this->assertSame('/saml/acs', $_SERVER['REQUEST_URI']);
    }

    public function testSingleLogoutIsCheckedWithThePathPrefixAProxyStripped(): void
    {
        $this->mockConfig->method('get')->willReturn([]);
        $this->mockSamlConfig->method('getServiceProviderConfig')
            ->willReturn(['single_logout_service_url' => 'https://dns.example.com/saml/saml/sls']);
        $this->session->set('saml_provider', 'idp');
        $_SERVER = ['SERVER_PORT' => '80', 'HTTP_HOST' => 'internal', 'REQUEST_URI' => '/saml/sls?SAMLResponse=x', 'QUERY_STRING' => 'SAMLResponse=x', 'SCRIPT_NAME' => '/index.php'];

        $seen = null;
        $auth = $this->createMock(Auth::class);
        $auth->method('processSLO')->willReturnCallback(function () use (&$seen): void {
            $seen = \OneLogin\Saml2\Utils::getSelfRoutedURLNoQuery();
        });
        $auth->method('getErrors')->willReturn([]);

        $this->serviceWithAuth($auth)->handleSingleLogout();

        $this->assertSame('https://dns.example.com/saml/saml/sls', $seen);
        $this->assertSame('/saml/sls?SAMLResponse=x', $_SERVER['REQUEST_URI']);
    }

    private function serviceWithAuth(Auth $auth): SamlService
    {
        return new SamlService(
            $this->mockConfig,
            $this->mockSamlConfig,
            $this->mockUserProvisioning,
            $this->mockLogger,
            $this->createMock(AuthenticationService::class),
            $this->createMock(AuditService::class),
            $this->createMock(MfaService::class),
            $this->session,
            $this->mockRequest,
            $auth
        );
    }

    public function testSingleLogoutIsCheckedAgainstTheSlsUrlEvenOnAnotherHost(): void
    {
        $this->mockConfig->method('get')->willReturn([]);
        $this->mockSamlConfig->method('getServiceProviderConfig')->willReturn([
            'assertion_consumer_service_url' => 'https://dns.example.com/saml/acs',
            'single_logout_service_url' => 'https://sso.example.com/saml/sls',
        ]);
        $_SERVER = ['SERVER_PORT' => '80', 'HTTP_HOST' => 'internal', 'REQUEST_URI' => '/saml/sls', 'SCRIPT_NAME' => '/index.php'];

        $sloUrl = $this->getPrivateMethod('getSloUrl')->invoke($this->service);
        $seen = $this->getPrivateMethod('withProxyServerVars')
            ->invoke($this->service, fn() => \OneLogin\Saml2\Utils::getSelfRoutedURLNoQuery(), $sloUrl);

        $this->assertSame('https://sso.example.com/saml/sls', $seen);
    }

    private function getPrivateMethod(string $methodName): ReflectionMethod
    {
        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod($methodName);
        return $method;
    }
}
