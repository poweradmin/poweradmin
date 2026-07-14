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
    public function testSoaIsRestrictedForOwnAsClient(): void
    {
        $this->assertTrue(Permission::isRecordTypeRestrictedForClient('SOA', 'own_as_client'));
    }

    public function testNsIsRestrictedForOwnAsClient(): void
    {
        $this->assertTrue(Permission::isRecordTypeRestrictedForClient('NS', 'own_as_client'));
    }

    public function testLowercaseTypeStillRestricted(): void
    {
        $this->assertTrue(Permission::isRecordTypeRestrictedForClient('soa', 'own_as_client'));
        $this->assertTrue(Permission::isRecordTypeRestrictedForClient('ns', 'own_as_client'));
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

    public function testOwnEditorIsNotRestricted(): void
    {
        $this->assertFalse(Permission::isRecordTypeRestrictedForClient('SOA', 'own'));
        $this->assertFalse(Permission::isRecordTypeRestrictedForClient('NS', 'own'));
    }

    public function testAllEditorIsNotRestricted(): void
    {
        $this->assertFalse(Permission::isRecordTypeRestrictedForClient('SOA', 'all'));
        $this->assertFalse(Permission::isRecordTypeRestrictedForClient('NS', 'all'));
    }

    public function testNoneEditorIsNotRestrictedByThisHelper(): void
    {
        // "none" is gated by the zone-level check, not the record-type list.
        $this->assertFalse(Permission::isRecordTypeRestrictedForClient('SOA', 'none'));
    }

    public function testRestrictedRecordTypeMessageForAdd(): void
    {
        $this->assertSame(
            'You do not have the permission to add SOA record.',
            Permission::restrictedRecordTypeMessage('SOA', 'add')
        );
        $this->assertSame(
            'You do not have the permission to add NS record.',
            Permission::restrictedRecordTypeMessage('NS', 'add')
        );
    }

    public function testRestrictedRecordTypeMessageForEdit(): void
    {
        $this->assertSame(
            'You do not have the permission to edit this SOA record.',
            Permission::restrictedRecordTypeMessage('SOA', 'edit')
        );
        $this->assertSame(
            'You do not have the permission to edit this NS record.',
            Permission::restrictedRecordTypeMessage('NS', 'edit')
        );
    }

    public function testRestrictedRecordTypeMessageForDelete(): void
    {
        $this->assertSame(
            'You do not have the permission to delete SOA records.',
            Permission::restrictedRecordTypeMessage('SOA', 'delete')
        );
        $this->assertSame(
            'You do not have the permission to delete NS records.',
            Permission::restrictedRecordTypeMessage('NS', 'delete')
        );
    }

    public function testRestrictedRecordTypeMessageIsCaseInsensitive(): void
    {
        $this->assertSame(
            'You do not have the permission to add NS record.',
            Permission::restrictedRecordTypeMessage('ns', 'add')
        );
    }

    public function testSubzoneNsIsPermittedWithSubzonePermission(): void
    {
        $this->assertFalse(
            Permission::isRecordRestrictedForClient('NS', 'own_as_client', 'sub.example.com', 'example.com', true)
        );
        $this->assertFalse(
            Permission::isRecordRestrictedForClient('ns', 'own_as_client', 'SUB.EXAMPLE.COM', 'example.com', true)
        );
    }

    public function testApexNsStaysRestrictedDespiteSubzonePermission(): void
    {
        $this->assertTrue(
            Permission::isRecordRestrictedForClient('NS', 'own_as_client', 'example.com', 'example.com', true)
        );
        $this->assertTrue(
            Permission::isRecordRestrictedForClient('NS', 'own_as_client', 'EXAMPLE.COM.', 'example.com', true),
            'Trailing dot and case must not bypass the apex check'
        );
    }

    public function testSoaStaysRestrictedDespiteSubzonePermission(): void
    {
        $this->assertTrue(
            Permission::isRecordRestrictedForClient('SOA', 'own_as_client', 'sub.example.com', 'example.com', true)
        );
    }

    public function testSubzoneNsStaysRestrictedWithoutSubzonePermission(): void
    {
        $this->assertTrue(
            Permission::isRecordRestrictedForClient('NS', 'own_as_client', 'sub.example.com', 'example.com', false)
        );
    }

    public function testMissingNamesKeepTypeOnlyRestriction(): void
    {
        $this->assertTrue(
            Permission::isRecordRestrictedForClient('NS', 'own_as_client', null, null, true)
        );
        $this->assertTrue(
            Permission::isRecordRestrictedForClient('NS', 'own_as_client', 'sub.example.com', null, true)
        );
        $this->assertTrue(
            Permission::isRecordRestrictedForClient('NS', 'own_as_client', null, 'example.com', true)
        );
    }

    public function testStrongerEditorsAreNeverRestrictedByRecordCheck(): void
    {
        foreach (['all', 'own', 'none'] as $permEdit) {
            $this->assertFalse(
                Permission::isRecordRestrictedForClient('NS', $permEdit, 'example.com', 'example.com', false)
            );
        }
    }

    public function testNonRestrictedTypesArePermittedByRecordCheck(): void
    {
        $this->assertFalse(
            Permission::isRecordRestrictedForClient('A', 'own_as_client', 'example.com', 'example.com', false)
        );
    }
}
