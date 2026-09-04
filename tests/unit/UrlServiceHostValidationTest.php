<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2026 Poweradmin Development Team
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace Poweradmin\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\UrlService;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;

class UrlServiceHostValidationTest extends TestCase
{
    private function createMockConfig(array $values = []): ConfigurationInterface
    {
        $mock = $this->createMock(ConfigurationInterface::class);
        $mock->method('get')->willReturnCallback(function ($section, $key, $default = null) use ($values) {
            return $values[$section][$key] ?? $default;
        });
        return $mock;
    }

    public function testHostHeaderInjectionPrevention(): void
    {
        // Simulate host header injection attack
        $_SERVER['HTTP_HOST'] = 'evil.com';
        $_SERVER['HTTPS'] = 'on';

        // Configure legitimate application URL
        $config = $this->createMockConfig([
            'interface' => [
                'application_url' => 'https://legitimate.com',
                'base_url_prefix' => ''
            ]
        ]);

        $urlService = new UrlService($config);

        // Get password reset URL
        $resetUrl = $urlService->getAbsoluteUrl('/password/reset?token=abc123');

        // Verify the URL uses the configured host, NOT the injected host
        $this->assertStringContainsString('https://legitimate.com', $resetUrl);
        $this->assertStringNotContainsString('evil.com', $resetUrl);
    }

    public function testHostHeaderInjectionWithPort(): void
    {
        // Simulate host header injection with port
        $_SERVER['HTTP_HOST'] = 'evil.com:8080';
        $_SERVER['HTTPS'] = 'on';

        // Configure legitimate application URL with port
        $config = $this->createMockConfig([
            'interface' => [
                'application_url' => 'https://legitimate.com:8443',
                'base_url_prefix' => ''
            ]
        ]);

        $urlService = new UrlService($config);

        // Get zone edit URL
        $zoneUrl = $urlService->getZoneEditUrl(123);

        // Verify the URL uses the configured host with correct port
        $this->assertStringContainsString('https://legitimate.com:8443', $zoneUrl);
        $this->assertStringNotContainsString('evil.com', $zoneUrl);
    }

    public function testLegitimateHostIsAccepted(): void
    {
        // Simulate legitimate request
        $_SERVER['HTTP_HOST'] = 'legitimate.com';
        $_SERVER['HTTPS'] = 'on';

        // Configure same application URL
        $config = $this->createMockConfig([
            'interface' => [
                'application_url' => 'https://legitimate.com',
                'base_url_prefix' => ''
            ]
        ]);

        $urlService = new UrlService($config);

        // Get login URL
        $loginUrl = $urlService->getLoginUrl();

        // Verify the URL is built correctly
        $this->assertEquals('https://legitimate.com/login', $loginUrl);
    }

    public function testAutoDetectionIgnoresServerNameWhenNoConfigurationExists(): void
    {
        // SERVER_NAME follows the client Host header under FrankenPHP and Apache
        // defaults, so without application_url neither header may reach the output
        $_SERVER['HTTP_HOST'] = 'evil.attacker.test';
        $_SERVER['SERVER_NAME'] = 'evil.attacker.test';
        $_SERVER['SERVER_PORT'] = '443';
        $_SERVER['HTTPS'] = 'on';

        $config = $this->createMockConfig([
            'interface' => [
                'application_url' => '',
                'base_url_prefix' => ''
            ]
        ]);

        $urlService = new UrlService($config);

        $url = $urlService->getAbsoluteUrl('/test');

        $this->assertEquals('https://localhost/test', $url);
        $this->assertStringNotContainsString('evil.attacker.test', $url);
    }

    public function testZoneEditUrlRefusesWhenApplicationUrlEmpty(): void
    {
        // Regression: the emailed zone-access link must not honour a forged Host
        // header, and SERVER_NAME is forgeable on the shipped Docker image
        $_SERVER['HTTP_HOST'] = 'evil.attacker.test';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $_SERVER['SERVER_NAME'] = 'evil.attacker.test';
        $_SERVER['SERVER_PORT'] = '443';

        $config = $this->createMockConfig([
            'interface' => [
                'application_url' => '',
                'base_url_prefix' => ''
            ]
        ]);

        $urlService = new UrlService($config);

        $this->assertNull($urlService->getZoneEditUrl(42));
    }

    public function testZoneEditUrlUsesApplicationUrlWhenConfigured(): void
    {
        $_SERVER['HTTP_HOST'] = 'evil.attacker.test';
        $_SERVER['SERVER_NAME'] = 'evil.attacker.test';

        $config = $this->createMockConfig([
            'interface' => [
                'application_url' => 'https://dns.legitimate.example',
                'base_url_prefix' => ''
            ]
        ]);

        $urlService = new UrlService($config);

        $url = $urlService->getZoneEditUrl(42);

        $this->assertEquals('https://dns.legitimate.example/zones/42/edit', $url);
        $this->assertStringNotContainsString('evil.attacker.test', (string)$url);
    }

    public function testConfiguredHostWinsRegardlessOfHostHeaderCase(): void
    {
        // A case-variant Host must not change the emitted URL either
        $_SERVER['HTTP_HOST'] = 'LEGITIMATE.COM';
        $_SERVER['SERVER_NAME'] = 'EVIL.ATTACKER.TEST';
        $_SERVER['HTTPS'] = 'on';

        $config = $this->createMockConfig([
            'interface' => [
                'application_url' => 'https://legitimate.com',
                'base_url_prefix' => ''
            ]
        ]);

        $urlService = new UrlService($config);

        $url = $urlService->getAbsoluteUrl('/test');

        $this->assertSame('https://legitimate.com/test', $url);
    }

    public function testCliContextDoesNotUseScriptName(): void
    {
        // Simulate CLI context (like PHPUnit, cron jobs, queue workers)
        $_SERVER['SCRIPT_NAME'] = 'bin/console';
        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['SERVER_PORT'] = '443';
        $_SERVER['HTTPS'] = 'on';

        $config = $this->createMockConfig([
            'interface' => [
                'application_url' => 'https://example.com',
                'base_url_prefix' => ''
            ]
        ]);

        $urlService = new UrlService($config);

        // Get URL - should NOT include 'bin' prefix from SCRIPT_NAME
        $url = $urlService->getAbsoluteUrl('/password/reset');

        // Verify the URL is correct without 'bin' prefix
        $this->assertEquals('https://example.com/password/reset', $url);
        $this->assertStringNotContainsString('bin', $url);
    }

    public function testProtocolDetectionWithHttpsOn(): void
    {
        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['HTTPS'] = 'on';

        $config = $this->createMockConfig([
            'interface' => [
                'application_url' => '',
                'base_url_prefix' => ''
            ]
        ]);

        $urlService = new UrlService($config);
        $url = $urlService->getAbsoluteUrl('/test');

        $this->assertStringStartsWith('https://', $url);
    }

    public function testProtocolDetectionWithHttpsOff(): void
    {
        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['HTTPS'] = 'off';

        $config = $this->createMockConfig([
            'interface' => [
                'application_url' => '',
                'base_url_prefix' => ''
            ]
        ]);

        $urlService = new UrlService($config);
        $url = $urlService->getAbsoluteUrl('/test');

        $this->assertStringStartsWith('http://', $url);
    }

    public function testProtocolDetectionWithForwardedProto(): void
    {
        // Simulate reverse proxy scenario: no HTTPS but X-Forwarded-Proto is set
        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

        $config = $this->createMockConfig([
            'interface' => [
                'application_url' => '',
                'base_url_prefix' => ''
            ]
        ]);

        $urlService = new UrlService($config);
        $url = $urlService->getAbsoluteUrl('/test');

        $this->assertStringStartsWith('https://', $url);
    }

    public function testProtocolDetectionWithForwardedProtoWhenHttpsOff(): void
    {
        // Simulate reverse proxy: HTTPS=off but X-Forwarded-Proto=https
        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['HTTPS'] = 'off';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

        $config = $this->createMockConfig([
            'interface' => [
                'application_url' => '',
                'base_url_prefix' => ''
            ]
        ]);

        $urlService = new UrlService($config);
        $url = $urlService->getAbsoluteUrl('/test');

        $this->assertStringStartsWith('https://', $url);
    }

    public function testGetEmailUrlIgnoresForgedHostWhenApplicationUrlConfigured(): void
    {
        $_SERVER['HTTP_HOST'] = 'evil.attacker.test';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

        $config = $this->createMockConfig([
            'interface' => [
                'application_url' => 'https://dns.legitimate.example',
                'base_url_prefix' => ''
            ]
        ]);

        $urlService = new UrlService($config);
        $url = $urlService->getEmailUrl('/password/reset?token=abc');

        $this->assertSame('https://dns.legitimate.example/password/reset?token=abc', $url);
        $this->assertStringNotContainsString('evil.attacker.test', (string) $url);
    }

    public function testGetEmailUrlReturnsNullWhenApplicationUrlEmpty(): void
    {
        $_SERVER['HTTP_HOST'] = 'evil.attacker.test';

        $config = $this->createMockConfig([
            'interface' => [
                'application_url' => '',
                'base_url_prefix' => ''
            ]
        ]);

        $urlService = new UrlService($config);

        $this->assertNull($urlService->getEmailUrl('/password/reset?token=abc'));
    }

    public function testLoginUrlForEmailIgnoresForgedHostAndServerName(): void
    {
        // The username-recovery mail goes to the account owner, so a link built from
        // request state would let an attacker phish a third party with a genuine mail.
        $_SERVER['HTTP_HOST'] = 'evil.attacker.test';
        $_SERVER['SERVER_NAME'] = 'evil.attacker.test';
        $_SERVER['SERVER_PORT'] = '443';
        $_SERVER['HTTPS'] = 'on';

        $config = $this->createMockConfig([
            'interface' => ['application_url' => '', 'base_url_prefix' => '']
        ]);

        $urlService = new UrlService($config);

        $this->assertNull($urlService->getEmailUrl('/login'));
    }

    public function testLoginUrlForEmailUsesApplicationUrlWhenConfigured(): void
    {
        $_SERVER['HTTP_HOST'] = 'evil.attacker.test';
        $_SERVER['SERVER_NAME'] = 'evil.attacker.test';
        $_SERVER['HTTPS'] = 'on';

        $config = $this->createMockConfig([
            'interface' => ['application_url' => 'https://configured.example']
        ]);

        $urlService = new UrlService($config);

        $url = $urlService->getEmailUrl('/login');
        $this->assertSame('https://configured.example/login', $url);
        $this->assertStringNotContainsString('evil.attacker.test', (string) $url);
    }

    public function testGetEmailUrlNormalizesSlashes(): void
    {
        $config = $this->createMockConfig([
            'interface' => [
                'application_url' => 'https://dns.legitimate.example/',
            ]
        ]);

        $urlService = new UrlService($config);

        $this->assertSame('https://dns.legitimate.example/x', $urlService->getEmailUrl('/x'));
        $this->assertSame('https://dns.legitimate.example/x', $urlService->getEmailUrl('x'));
    }

    public function testProtocolDetectionDefaultsToHttp(): void
    {
        // No HTTPS indicators
        $_SERVER['HTTP_HOST'] = 'example.com';

        $config = $this->createMockConfig([
            'interface' => [
                'application_url' => '',
                'base_url_prefix' => ''
            ]
        ]);

        $urlService = new UrlService($config);
        $url = $urlService->getAbsoluteUrl('/test');

        $this->assertStringStartsWith('http://', $url);
    }

    protected function tearDown(): void
    {
        // Clean up server variables
        unset($_SERVER['HTTP_HOST']);
        unset($_SERVER['HTTPS']);
        unset($_SERVER['SERVER_NAME']);
        unset($_SERVER['SERVER_PORT']);
        unset($_SERVER['HTTP_X_FORWARDED_PROTO']);
        unset($_SERVER['SCRIPT_NAME']);
    }
}
