<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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

    public function testAutoDetectionWhenNoConfigurationExists(): void
    {
        // Simulate environment without configured application_url
        $_SERVER['HTTP_HOST'] = 'autodetect.com';
        $_SERVER['HTTPS'] = 'on';

        $config = $this->createMockConfig([
            'interface' => [
                'application_url' => '',
                'base_url_prefix' => ''
            ]
        ]);

        $urlService = new UrlService($config);

        // Get absolute URL
        $url = $urlService->getAbsoluteUrl('/test');

        // Auto-detection should work when no configuration exists
        $this->assertEquals('https://autodetect.com/test', $url);
    }

    public function testCaseInsensitiveHostComparison(): void
    {
        // Simulate host with different case
        $_SERVER['HTTP_HOST'] = 'LEGITIMATE.COM';
        $_SERVER['HTTPS'] = 'on';

        $config = $this->createMockConfig([
            'interface' => [
                'application_url' => 'https://legitimate.com',
                'base_url_prefix' => ''
            ]
        ]);

        $urlService = new UrlService($config);

        // Get URL
        $url = $urlService->getAbsoluteUrl('/test');

        // Should accept uppercase host as legitimate
        $this->assertStringContainsString('legitimate.com', strtolower($url));
    }

    public function testCliContextDoesNotUseScriptName(): void
    {
        // Simulate CLI context (like PHPUnit, cron jobs, queue workers)
        $_SERVER['SCRIPT_NAME'] = 'bin/console';
        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['HTTPS'] = 'on';

        // No configured application_url or base_url_prefix
        $config = $this->createMockConfig([
            'interface' => [
                'application_url' => '',
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
