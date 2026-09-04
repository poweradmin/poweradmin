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

namespace Poweradmin\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\AvatarService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

class AvatarServiceTest extends TestCase
{
    private const OAUTH_URL = 'https://cdn.example.com/avatar.png';

    public function testOauthPriorityPrefersOauthUrl(): void
    {
        $service = $this->serviceWithPriority('oauth');

        $this->assertStringContainsString(
            'cdn.example.com',
            (string) $service->getAvatarUrl('user@example.com', self::OAUTH_URL)
        );
    }

    public function testGravatarPriorityPrefersGravatar(): void
    {
        $service = $this->serviceWithPriority('gravatar');

        $this->assertStringContainsString(
            'gravatar.com',
            (string) $service->getAvatarUrl('user@example.com', self::OAUTH_URL)
        );
    }

    public function testUnknownPriorityFallsBackToOauthFirst(): void
    {
        $service = $this->serviceWithPriority('gravitar');

        $this->assertStringContainsString(
            'cdn.example.com',
            (string) $service->getAvatarUrl('user@example.com', self::OAUTH_URL)
        );
    }

    public function testUnknownPriorityStillFallsBackToGravatarWithoutOauthUrl(): void
    {
        $service = $this->serviceWithPriority('gravitar');

        $this->assertStringContainsString(
            'gravatar.com',
            (string) $service->getAvatarUrl('user@example.com', null)
        );
    }

    public function testNoProvidersEnabledReturnsNull(): void
    {
        $service = $this->serviceWithPriority('oauth', false);

        $this->assertNull($service->getAvatarUrl('user@example.com', self::OAUTH_URL));
    }

    private function serviceWithPriority(string $priority, bool $providersEnabled = true): AvatarService
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            static fn(string $group, string $key, mixed $default = null): mixed => match ($key) {
                'avatar_oauth_enabled', 'avatar_gravatar_enabled' => $providersEnabled,
                'avatar_priority' => $priority,
                'avatar_size' => 40,
                default => $default,
            }
        );

        return new AvatarService($config);
    }
}
