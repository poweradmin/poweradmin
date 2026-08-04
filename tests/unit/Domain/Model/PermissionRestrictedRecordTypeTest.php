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

namespace Poweradmin\Tests\Unit\Domain\Model;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\Permission;

class PermissionRestrictedRecordTypeTest extends TestCase
{
    public function testSoaAndNsAreRestrictedForOwnAsClient(): void
    {
        $this->assertTrue(Permission::isRecordTypeRestrictedForClient('SOA', 'own_as_client'));
        $this->assertTrue(Permission::isRecordTypeRestrictedForClient('NS', 'own_as_client'));
    }

    public function testLuaIsRestrictedForOwnAsClient(): void
    {
        // LUA records make PowerDNS execute script content, so a client-level editor
        // must not create them even where the server has LUA enabled.
        $this->assertTrue(Permission::isRecordTypeRestrictedForClient('LUA', 'own_as_client'));
    }

    public function testLuaStaysAvailableToFullEditors(): void
    {
        $this->assertFalse(Permission::isRecordTypeRestrictedForClient('LUA', 'own'));
        $this->assertFalse(Permission::isRecordTypeRestrictedForClient('LUA', 'all'));
    }

    public function testLowercaseTypeStillRestricted(): void
    {
        $this->assertTrue(Permission::isRecordTypeRestrictedForClient('lua', 'own_as_client'));
        $this->assertTrue(Permission::isRecordTypeRestrictedForClient('soa', 'own_as_client'));
    }

    public function testNonRestrictedTypesArePermitted(): void
    {
        foreach (['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'PTR', 'SRV'] as $type) {
            $this->assertFalse(
                Permission::isRecordTypeRestrictedForClient($type, 'own_as_client'),
                "Type {$type} must not be restricted for own_as_client"
            );
        }
    }

    public function testNoneEditorIsNotRestrictedByThisHelper(): void
    {
        // "none" is gated by the zone-level check, not the record-type list.
        $this->assertFalse(Permission::isRecordTypeRestrictedForClient('SOA', 'none'));
    }

    public function testTemplateGateHoldsLuaToAStricterBar(): void
    {
        // A template seeds its records into every zone created from it and they are
        // written straight to the backend, so LUA needs the standing to write one
        // directly - "none" is not enough even though the record-type list is not hit.
        $this->assertTrue(Permission::isTemplateRecordTypeRestricted('LUA', 'own_as_client'));
        $this->assertTrue(Permission::isTemplateRecordTypeRestricted('LUA', 'none'));
        $this->assertFalse(Permission::isTemplateRecordTypeRestricted('LUA', 'own'));
        $this->assertFalse(Permission::isTemplateRecordTypeRestricted('LUA', 'all'));
    }

    public function testTemplateGateStillCoversSoaAndNsForClients(): void
    {
        $this->assertTrue(Permission::isTemplateRecordTypeRestricted('SOA', 'own_as_client'));
        $this->assertTrue(Permission::isTemplateRecordTypeRestricted('NS', 'own_as_client'));
        $this->assertFalse(Permission::isTemplateRecordTypeRestricted('SOA', 'own'));
    }

    public function testTemplateGateLeavesOrdinaryTypesAlone(): void
    {
        $this->assertFalse(Permission::isTemplateRecordTypeRestricted('A', 'own_as_client'));
        $this->assertFalse(Permission::isTemplateRecordTypeRestricted('TXT', 'none'));
    }
}
