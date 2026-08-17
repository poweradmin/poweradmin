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

namespace Poweradmin\Tests\Unit\Application\Controller;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\MfaSetupController;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use ReflectionClass;

/**
 * Covers which second-factor methods the setup page offers.
 *
 * The point of interest is that `mfa.app_enabled` is deliberately not absolute:
 * it is ignored whenever email verification is unusable, so that turning both
 * methods off cannot leave an enforced user with nothing to set up.
 */
class MfaSetupControllerMethodToggleTest extends TestCase
{
    private ReflectionClass $reflection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reflection = new ReflectionClass(MfaSetupController::class);
    }

    /**
     * @param array<string, mixed> $settings keyed as "group.key"
     */
    private function createController(array $settings): MfaSetupController
    {
        $controller = $this->getMockBuilder(MfaSetupController::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['run'])
            ->getMock();

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            fn(string $group, string $key, $default = null) => $settings["$group.$key"] ?? $default
        );

        $configProperty = $this->reflection->getParentClass()->getProperty('config');
        $configProperty->setValue($controller, $config);

        return $controller;
    }

    private function appEnabled(array $settings): bool
    {
        $method = $this->reflection->getMethod('isAppMfaEnabled');
        return $method->invoke($this->createController($settings));
    }

    public function testAppMethodIsOfferedByDefault(): void
    {
        $this->assertTrue($this->appEnabled([]));
    }

    public function testAppMethodIsOfferedWhenExplicitlyEnabled(): void
    {
        $this->assertTrue($this->appEnabled([
            'security.mfa.app_enabled' => true,
            'security.mfa.email_enabled' => true,
            'mail.enabled' => true,
        ]));
    }

    public function testAppMethodIsWithdrawnWhenDisabledAndEmailRemainsUsable(): void
    {
        $this->assertFalse($this->appEnabled([
            'security.mfa.app_enabled' => false,
            'security.mfa.email_enabled' => true,
            'mail.enabled' => true,
        ]));
    }

    public function testAppMethodSurvivesWhenEmailIsAlsoDisabled(): void
    {
        $this->assertTrue($this->appEnabled([
            'security.mfa.app_enabled' => false,
            'security.mfa.email_enabled' => false,
            'mail.enabled' => true,
        ]));
    }

    public function testAppMethodSurvivesWhenMailTransportIsNotConfigured(): void
    {
        // Email verification is nominally on, but unusable without a mail
        // transport, so it cannot be the last remaining method.
        $this->assertTrue($this->appEnabled([
            'security.mfa.app_enabled' => false,
            'security.mfa.email_enabled' => true,
            'mail.enabled' => false,
        ]));
    }
}
