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
use Poweradmin\Application\Controller\LoginController;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use ReflectionClass;

class LoginControllerOidcProvidersTest extends TestCase
{
    private ReflectionClass $reflection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reflection = new ReflectionClass(LoginController::class);
    }

    private function createController(array $providersConfig): LoginController
    {
        $controller = $this->getMockBuilder(LoginController::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['run'])
            ->getMock();

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            fn(string $group, string $key, $default = null) =>
                $group === 'oidc' && $key === 'providers' ? $providersConfig : $default
        );

        $configProperty = $this->reflection->getParentClass()->getProperty('config');
        $configProperty->setValue($controller, $config);

        return $controller;
    }

    private function invokeBuild(LoginController $controller): array
    {
        $method = $this->reflection->getMethod('buildOidcProviders');
        return $method->invoke($controller);
    }

    public function testEmptyConfigYieldsEmptyList(): void
    {
        $providers = $this->invokeBuild($this->createController([]));
        $this->assertSame([], $providers);
    }

    public function testProviderWithCredentialsAndMissingEnabledFlagIsIncluded(): void
    {
        $providers = $this->invokeBuild($this->createController([
            'google' => [
                'client_id' => 'cid',
                'client_secret' => 'sec',
                'display_name' => 'Google',
            ],
        ]));

        $this->assertSame([
            'google' => ['id' => 'google', 'display_name' => 'Google'],
        ], $providers);
    }

    public function testExplicitlyDisabledProviderIsExcluded(): void
    {
        $providers = $this->invokeBuild($this->createController([
            'google' => [
                'enabled' => false,
                'client_id' => 'cid',
                'client_secret' => 'sec',
                'display_name' => 'Google',
            ],
        ]));

        $this->assertSame([], $providers);
    }

    public function testProviderMissingClientIdIsExcluded(): void
    {
        $providers = $this->invokeBuild($this->createController([
            'google' => [
                'client_secret' => 'sec',
            ],
        ]));

        $this->assertSame([], $providers);
    }

    public function testProviderMissingClientSecretIsExcluded(): void
    {
        $providers = $this->invokeBuild($this->createController([
            'google' => [
                'client_id' => 'cid',
            ],
        ]));

        $this->assertSame([], $providers);
    }

    public function testProviderWithEmptyCredentialsIsExcluded(): void
    {
        $providers = $this->invokeBuild($this->createController([
            'google' => [
                'client_id' => '',
                'client_secret' => '',
            ],
        ]));

        $this->assertSame([], $providers);
    }

    public function testDisplayNameFallsBackToUcfirstId(): void
    {
        $providers = $this->invokeBuild($this->createController([
            'okta' => [
                'client_id' => 'cid',
                'client_secret' => 'sec',
            ],
        ]));

        $this->assertSame([
            'okta' => ['id' => 'okta', 'display_name' => 'Okta'],
        ], $providers);
    }

    public function testMultipleProvidersOnlyValidOnesReturned(): void
    {
        $providers = $this->invokeBuild($this->createController([
            'google' => [
                'enabled' => true,
                'client_id' => 'g_cid',
                'client_secret' => 'g_sec',
                'display_name' => 'Google',
            ],
            'azure' => [
                'enabled' => false,
                'client_id' => 'a_cid',
                'client_secret' => 'a_sec',
            ],
            'broken' => [
                'client_id' => 'b_cid',
            ],
            'okta' => [
                'client_id' => 'o_cid',
                'client_secret' => 'o_sec',
            ],
        ]));

        $this->assertSame([
            'google' => ['id' => 'google', 'display_name' => 'Google'],
            'okta' => ['id' => 'okta', 'display_name' => 'Okta'],
        ], $providers);
    }
}
