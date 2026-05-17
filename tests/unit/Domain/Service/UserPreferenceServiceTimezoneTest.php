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

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\UserPreference;
use Poweradmin\Domain\Repository\UserPreferenceRepositoryInterface;
use Poweradmin\Domain\Service\UserPreferenceService;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;

#[CoversClass(UserPreferenceService::class)]
class UserPreferenceServiceTimezoneTest extends TestCase
{
    private function makeService(?UserPreferenceRepositoryInterface $repo = null): UserPreferenceService
    {
        $repo ??= $this->createMock(UserPreferenceRepositoryInterface::class);
        $config = $this->createMock(ConfigurationInterface::class);
        $config->method('get')->willReturnArgument(2);
        return new UserPreferenceService($repo, $config);
    }

    #[Test]
    public function testSetPreferenceAcceptsCanonicalTimezone(): void
    {
        $repo = $this->createMock(UserPreferenceRepositoryInterface::class);
        $repo->expects($this->once())
            ->method('createOrUpdate')
            ->with(7, UserPreference::KEY_TIMEZONE, 'Europe/Berlin');

        $service = $this->makeService($repo);
        $service->setPreference(7, UserPreference::KEY_TIMEZONE, 'Europe/Berlin');
    }

    #[Test]
    public function testSetPreferenceRejectsAliasTimezone(): void
    {
        // Even though US/Eastern is a valid PHP timezone, storing it as a
        // user preference is blocked - the UI selector can't round-trip it.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid timezone: US/Eastern');

        $service = $this->makeService();
        $service->setPreference(7, UserPreference::KEY_TIMEZONE, 'US/Eastern');
    }

    #[Test]
    public function testSetPreferenceRejectsInvalidTimezone(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid timezone: Mars/Olympus');

        $service = $this->makeService();
        $service->setPreference(7, UserPreference::KEY_TIMEZONE, 'Mars/Olympus');
    }

    #[Test]
    public function testSetPreferenceAllowsClearingTimezone(): void
    {
        $repo = $this->createMock(UserPreferenceRepositoryInterface::class);
        $repo->expects($this->exactly(2))->method('createOrUpdate');

        $service = $this->makeService($repo);
        $service->setPreference(7, UserPreference::KEY_TIMEZONE, null);
        $service->setPreference(7, UserPreference::KEY_TIMEZONE, '');
    }
}
