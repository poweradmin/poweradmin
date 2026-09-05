<?php

namespace unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\Permission;

class PermissionDnssecTest extends TestCase
{
    public function testOwnerWithEditOwnMayManage(): void
    {
        $this->assertTrue(Permission::canManageDnssec('own', true));
    }

    public function testNonOwningAdminMayManage(): void
    {
        $this->assertTrue(Permission::canManageDnssec('all', false));
    }

    public function testOwnerWithoutEditPermissionMayNotManage(): void
    {
        $this->assertFalse(Permission::canManageDnssec('none', true));
    }

    public function testClientLevelEditorMayNotManage(): void
    {
        $this->assertFalse(Permission::canManageDnssec('own_as_client', true));
    }

    public function testNonOwnerWithEditOwnMayNotManage(): void
    {
        $this->assertFalse(Permission::canManageDnssec('own', false));
    }
}
