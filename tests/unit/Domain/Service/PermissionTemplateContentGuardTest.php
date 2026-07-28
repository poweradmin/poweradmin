<?php

namespace Poweradmin\Tests\Unit\Domain\Service;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\PermissionTemplateContentGuard;

/**
 * templ_perm_edit delegates template management, but a template's permission list is
 * itself an authority grant: ticking user_is_ueberuser on the template you already
 * hold made you a superuser with no assignment step.
 */
class PermissionTemplateContentGuardTest extends TestCase
{
    private const ALL_PERMISSIONS = [
        ['id' => 4, 'name' => 'zone_content_view_own'],
        ['id' => 53, 'name' => 'user_is_ueberuser'],
    ];

    public function testSuperuserMaySubmitTheUberuserPermission(): void
    {
        $this->assertNull(
            PermissionTemplateContentGuard::apply(true, self::ALL_PERMISSIONS, [], [53])
        );
    }

    public function testDelegatedEditorMayNotAddTheUberuserPermission(): void
    {
        $this->assertSame(
            PermissionTemplateContentGuard::CONTENT_SUPERUSER_DENIED,
            PermissionTemplateContentGuard::apply(false, self::ALL_PERMISSIONS, [], [4, 53])
        );
    }

    public function testPostedStringIdsAreStillCaught(): void
    {
        // POST arrays arrive as strings, so a strict comparison would have missed this.
        $this->assertSame(
            PermissionTemplateContentGuard::CONTENT_SUPERUSER_DENIED,
            PermissionTemplateContentGuard::apply(false, self::ALL_PERMISSIONS, [], ['53'])
        );
    }

    public function testDelegatedEditorMayNotTouchASuperuserTemplate(): void
    {
        // Also the lockout defence: they cannot strip the Administrator template bare.
        $current = [['id' => 53, 'name' => 'user_is_ueberuser']];

        $this->assertSame(
            PermissionTemplateContentGuard::EDIT_SUPERUSER_DENIED,
            PermissionTemplateContentGuard::apply(false, self::ALL_PERMISSIONS, $current, [])
        );
    }

    public function testDelegatedEditorMayStillEditAnOrdinaryTemplate(): void
    {
        $current = [['id' => 4, 'name' => 'zone_content_view_own']];

        $this->assertNull(
            PermissionTemplateContentGuard::apply(false, self::ALL_PERMISSIONS, $current, [4])
        );
    }

    public function testEveryRowCarryingTheNameIsBlocked(): void
    {
        // perm_items has no unique constraint on name, so a duplicate row grants it too.
        $all = [
            ['id' => 53, 'name' => 'user_is_ueberuser'],
            ['id' => 91, 'name' => 'user_is_ueberuser'],
        ];

        $this->assertSame(
            PermissionTemplateContentGuard::CONTENT_SUPERUSER_DENIED,
            PermissionTemplateContentGuard::apply(false, $all, [], [91])
        );
    }

    public function testNestedArraysNeverMatchByCoercion(): void
    {
        // (int)[1] is 1 in PHP, so non-scalars must be skipped rather than cast.
        $all = [['id' => 1, 'name' => 'user_is_ueberuser']];

        $this->assertNull(
            PermissionTemplateContentGuard::apply(false, $all, [], [[42]])
        );
    }

    public function testFilterHidesTheUberuserRowFromNonSuperusers(): void
    {
        $filtered = PermissionTemplateContentGuard::filterOfferedPermissions(self::ALL_PERMISSIONS, false);

        $this->assertCount(1, $filtered);
        $this->assertSame('zone_content_view_own', $filtered[0]['name']);
    }

    public function testFilterLeavesTheListIntactForSuperusers(): void
    {
        $this->assertSame(
            self::ALL_PERMISSIONS,
            PermissionTemplateContentGuard::filterOfferedPermissions(self::ALL_PERMISSIONS, true)
        );
    }
}
