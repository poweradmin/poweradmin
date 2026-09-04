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
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\UserPreference;
use Poweradmin\Domain\Repository\UserPreferenceRepositoryInterface;
use Poweradmin\Domain\Service\UserPreferenceService;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;

#[CoversClass(UserPreferenceService::class)]
class UserPreferenceServiceRecordCountTest extends TestCase
{
    private function makeService(
        ?UserPreferenceRepositoryInterface $repo,
        bool $configDefault
    ): UserPreferenceService {
        $repo ??= $this->createMock(UserPreferenceRepositoryInterface::class);
        $config = $this->createMock(ConfigurationInterface::class);
        $config->method('get')->willReturnCallback(
            function (string $section, string $key, $default = null) use ($configDefault) {
                if ($section === 'interface' && $key === 'show_zone_record_count') {
                    return $configDefault;
                }
                return $default;
            }
        );
        return new UserPreferenceService($repo, $config);
    }

    #[Test]
    public function testDefaultsToConfigTrueWhenNoStoredPreference(): void
    {
        $repo = $this->createMock(UserPreferenceRepositoryInterface::class);
        $repo->method('findByUserIdAndKey')->willReturn(null);

        $service = $this->makeService($repo, true);
        $this->assertTrue($service->getShowZoneRecordCount(42));
    }

    #[Test]
    public function testDefaultsToConfigFalseWhenNoStoredPreference(): void
    {
        $repo = $this->createMock(UserPreferenceRepositoryInterface::class);
        $repo->method('findByUserIdAndKey')->willReturn(null);

        $service = $this->makeService($repo, false);
        $this->assertFalse($service->getShowZoneRecordCount(42));
    }

    #[Test]
    public function testStoredTrueOverridesConfigFalse(): void
    {
        $stored = new UserPreference(1, 42, UserPreference::KEY_SHOW_ZONE_RECORD_COUNT, 'true');
        $repo = $this->createMock(UserPreferenceRepositoryInterface::class);
        $repo->method('findByUserIdAndKey')->willReturn($stored);

        $service = $this->makeService($repo, false);
        $this->assertTrue($service->getShowZoneRecordCount(42));
    }

    #[Test]
    public function testStoredFalseOverridesConfigTrue(): void
    {
        $stored = new UserPreference(1, 42, UserPreference::KEY_SHOW_ZONE_RECORD_COUNT, 'false');
        $repo = $this->createMock(UserPreferenceRepositoryInterface::class);
        $repo->method('findByUserIdAndKey')->willReturn($stored);

        $service = $this->makeService($repo, true);
        $this->assertFalse($service->getShowZoneRecordCount(42));
    }
}
