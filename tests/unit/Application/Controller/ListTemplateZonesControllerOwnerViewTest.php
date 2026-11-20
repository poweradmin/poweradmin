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

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\ListTemplateZonesController;

/**
 * The template zones page prints each zone's direct owner, so it follows the same
 * ownership view level as the zone lists.
 */
class ListTemplateZonesControllerOwnerViewTest extends TestCase
{
    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(): array
    {
        return [
            ['id' => 1, 'name' => 'mine.example', 'owner' => 7, 'owner_name' => 'me', 'owner_fullname' => 'Me Myself'],
            ['id' => 2, 'name' => 'theirs.example', 'owner' => 8, 'owner_name' => 'other', 'owner_fullname' => 'Other Person'],
        ];
    }

    #[Test]
    public function testAllLevelKeepsEveryOwner(): void
    {
        $this->assertSame($this->rows(), ListTemplateZonesController::withVisibleOwners($this->rows(), 'all', fn(int $id): bool => false));
    }

    #[Test]
    public function testNoneLevelBlanksEveryOwner(): void
    {
        foreach (ListTemplateZonesController::withVisibleOwners($this->rows(), 'none', fn(int $id): bool => true) as $row) {
            $this->assertNull($row['owner_name']);
            $this->assertNull($row['owner_fullname']);
        }
    }

    #[Test]
    public function testOwnLevelBlanksOnlyZonesTheCallerDoesNotOwn(): void
    {
        $rows = ListTemplateZonesController::withVisibleOwners($this->rows(), 'own', fn(int $id): bool => $id === 1);

        $this->assertSame('me', $rows[0]['owner_name']);
        $this->assertNull($rows[1]['owner_name']);
        $this->assertNull($rows[1]['owner_fullname']);
        $this->assertSame('theirs.example', $rows[1]['name']);
    }
}
