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

namespace Poweradmin\Tests\Unit\Domain\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\UserPreference;
use Poweradmin\Domain\Service\UserPreferenceService;
use Poweradmin\Domain\Service\UserTimezoneService;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;

#[CoversClass(UserTimezoneService::class)]
class UserTimezoneServiceTest extends TestCase
{
    private UserPreferenceService&MockObject $preferenceService;
    private ConfigurationInterface&MockObject $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->preferenceService = $this->createMock(UserPreferenceService::class);
        $this->config = $this->createMock(ConfigurationInterface::class);
    }

    private function makeService(): UserTimezoneService
    {
        return new UserTimezoneService($this->preferenceService, $this->config);
    }

    #[Test]
    public function testReturnsUserPreferenceWhenSetAndValid(): void
    {
        $this->preferenceService
            ->expects($this->once())
            ->method('getPreference')
            ->with(42, UserPreference::KEY_TIMEZONE)
            ->willReturn('Europe/Berlin');

        $service = $this->makeService();
        $this->assertSame('Europe/Berlin', $service->getEffectiveTimezone(42));
    }

    #[Test]
    public function testFallsBackToGlobalConfigWhenUserPreferenceMissing(): void
    {
        $this->preferenceService->method('getPreference')->willReturn(null);
        $this->config->method('get')
            ->with('misc', 'timezone', null)
            ->willReturn('America/New_York');

        $service = $this->makeService();
        $this->assertSame('America/New_York', $service->getEffectiveTimezone(42));
    }

    #[Test]
    public function testFallsBackToGlobalConfigWhenUserPreferenceIsInvalid(): void
    {
        $this->preferenceService->method('getPreference')->willReturn('Bogus/NotReal');
        $this->config->method('get')->willReturn('UTC');

        $service = $this->makeService();
        $this->assertSame('UTC', $service->getEffectiveTimezone(42));
    }

    #[Test]
    public function testFallsBackToUtcWhenNothingConfigured(): void
    {
        $this->preferenceService->method('getPreference')->willReturn(null);
        $this->config->method('get')->willReturn(null);

        $service = $this->makeService();
        $this->assertSame('UTC', $service->getEffectiveTimezone(42));
    }

    #[Test]
    public function testFallsBackToConfigForNullUserId(): void
    {
        $this->preferenceService->expects($this->never())->method('getPreference');
        $this->config->method('get')->willReturn('Asia/Tokyo');

        $service = $this->makeService();
        $this->assertSame('Asia/Tokyo', $service->getEffectiveTimezone(null));
    }

    #[Test]
    public function testCachesResolvedTimezonePerUser(): void
    {
        $this->preferenceService
            ->expects($this->once())
            ->method('getPreference')
            ->willReturn('Europe/Madrid');

        $service = $this->makeService();
        $service->getEffectiveTimezone(7);
        $service->getEffectiveTimezone(7);
        $service->getEffectiveTimezone(7);
    }

    #[Test]
    public function testClearCacheResetsLookup(): void
    {
        $this->preferenceService
            ->expects($this->exactly(2))
            ->method('getPreference')
            ->willReturn('Europe/Madrid');

        $service = $this->makeService();
        $service->getEffectiveTimezone(7);
        $service->clearCache();
        $service->getEffectiveTimezone(7);
    }
}
