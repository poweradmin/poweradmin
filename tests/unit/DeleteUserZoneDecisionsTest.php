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

namespace Poweradmin\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\DeleteUserController;

#[CoversClass(DeleteUserController::class)]
class DeleteUserZoneDecisionsTest extends TestCase
{
    #[Test]
    public function parsesDeleteAndNewOwnerDecisions(): void
    {
        $json = '[{"zid":"5","target":"delete"},{"zid":"9","target":"new_owner","newowner":"3"}]';

        $this->assertSame([
            ['zid' => 5, 'target' => 'delete'],
            ['zid' => 9, 'target' => 'new_owner', 'newowner' => 3],
        ], DeleteUserController::parseZoneDecisions($json));
    }

    #[Test]
    public function returnsEmptyArrayForEmptyDecisionList(): void
    {
        $this->assertSame([], DeleteUserController::parseZoneDecisions('[]'));
    }

    #[Test]
    public function deduplicatesRepeatedZoneIds(): void
    {
        $json = '[{"zid":5,"target":"delete"},{"zid":5,"target":"new_owner","newowner":3}]';

        $this->assertSame([
            ['zid' => 5, 'target' => 'new_owner', 'newowner' => 3],
        ], DeleteUserController::parseZoneDecisions($json));
    }

    #[Test]
    public function rejectsMalformedPayloads(): void
    {
        $this->assertNull(DeleteUserController::parseZoneDecisions('not json'));
        $this->assertNull(DeleteUserController::parseZoneDecisions('null'));
        $this->assertNull(DeleteUserController::parseZoneDecisions('{"zid":5}'));
        $this->assertNull(DeleteUserController::parseZoneDecisions('[5]'));
        $this->assertNull(DeleteUserController::parseZoneDecisions('[{"zid":0,"target":"delete"}]'));
        $this->assertNull(DeleteUserController::parseZoneDecisions('[{"zid":5,"target":"leave"}]'));
        $this->assertNull(DeleteUserController::parseZoneDecisions('[{"zid":5,"target":"drop table"}]'));
        $this->assertNull(DeleteUserController::parseZoneDecisions('[{"zid":5,"target":"new_owner"}]'));
        $this->assertNull(DeleteUserController::parseZoneDecisions('[{"zid":5,"target":"new_owner","newowner":0}]'));
    }
}
