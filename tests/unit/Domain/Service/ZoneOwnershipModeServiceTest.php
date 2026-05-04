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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\ZoneOwnershipModeService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

#[CoversClass(ZoneOwnershipModeService::class)]
class ZoneOwnershipModeServiceTest extends TestCase
{
    /**
     * @return array<string, array{0: mixed, 1: string, 2: bool, 3: bool}>
     */
    public static function modeProvider(): array
    {
        return [
            'both'              => ['both',         'both',         true,  true],
            'users_only'        => ['users_only',   'users_only',   true,  false],
            'groups_only'       => ['groups_only',  'groups_only',  false, true],
            'unknown falls back to both'
                                => ['unexpected',   'both',         true,  true],
            'null falls back to both (default)'
                                => [null,           'both',         true,  true],
            'non-string falls back to both'
                                => [42,             'both',         true,  true],
        ];
    }

    #[DataProvider('modeProvider')]
    public function testModeResolution(
        mixed $configValue,
        string $expectedMode,
        bool $expectUserAllowed,
        bool $expectGroupAllowed
    ): void {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')
            ->with('dns', 'zone_ownership_mode', 'both')
            ->willReturn($configValue);

        $service = new ZoneOwnershipModeService($config);

        $this->assertSame($expectedMode, $service->getMode());
        $this->assertSame($expectUserAllowed, $service->isUserOwnerAllowed());
        $this->assertSame($expectGroupAllowed, $service->isGroupOwnerAllowed());
    }
}
