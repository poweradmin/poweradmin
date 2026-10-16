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

/**
 * A preference equal to the site default is not stored, so changing the default in
 * settings.php still reaches users who never chose otherwise (#1739).
 */
#[CoversClass(UserPreferenceService::class)]
class UserPreferenceServiceSiteDefaultTest extends TestCase
{
    private function service(UserPreferenceRepositoryInterface $repo, bool $hostnameOnly): UserPreferenceService
    {
        $config = $this->createMock(ConfigurationInterface::class);
        $config->method('get')->willReturnCallback(
            fn(string $section, string $key, mixed $default = null): mixed
                => $section === 'interface' && $key === 'display_hostname_only' ? $hostnameOnly : $default
        );

        return new UserPreferenceService($repo, $config);
    }

    #[Test]
    public function aValueEqualToTheSiteDefaultDropsTheStoredOne(): void
    {
        $repo = $this->createMock(UserPreferenceRepositoryInterface::class);
        $repo->expects($this->never())->method('createOrUpdate');
        $repo->expects($this->once())->method('deleteByUserIdAndKey')->with(7, UserPreference::KEY_DISPLAY_HOSTNAME_ONLY);

        $this->service($repo, false)->setPreference(7, UserPreference::KEY_DISPLAY_HOSTNAME_ONLY, 'false');
    }

    #[Test]
    public function aValueThatDiffersFromTheSiteDefaultIsStored(): void
    {
        $repo = $this->createMock(UserPreferenceRepositoryInterface::class);
        $repo->expects($this->once())->method('createOrUpdate')->with(7, UserPreference::KEY_DISPLAY_HOSTNAME_ONLY, 'true');
        $repo->expects($this->never())->method('deleteByUserIdAndKey');

        $this->service($repo, false)->setPreference(7, UserPreference::KEY_DISPLAY_HOSTNAME_ONLY, 'true');
    }

    #[Test]
    public function afterSavingTheDefaultTheSiteSettingDecides(): void
    {
        $repo = $this->createMock(UserPreferenceRepositoryInterface::class);
        $repo->method('findByUserIdAndKey')->willReturn(null);

        $service = $this->service($repo, true);
        $service->setPreference(7, UserPreference::KEY_DISPLAY_HOSTNAME_ONLY, 'true');

        $this->assertTrue($service->getDisplayHostnameOnly(7));
    }
}
