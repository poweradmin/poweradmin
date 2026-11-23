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
 */

namespace unit\Application\Controller;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\EditUserController;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Infrastructure\Database\PDOLayer;

/**
 * Editing your own account: a changed username or email needs the current password,
 * and an LDAP account or an empty password never passes the check.
 */
class EditUserControllerOwnIdentityTest extends TestCase
{
    public function testOnlyAChangedUsernameOrEmailCountsAsASignInChange(): void
    {
        $stored = ['username' => 'alice', 'email' => 'alice@example.com'];

        $this->assertFalse(EditUserController::changesSignInIdentity($stored, 'alice', 'alice@example.com'));
        $this->assertTrue(EditUserController::changesSignInIdentity($stored, 'alice', 'attacker@example.net'));
        $this->assertTrue(EditUserController::changesSignInIdentity($stored, 'mallory', 'alice@example.com'));
    }

    public function testTheCurrentPasswordIsCheckedAgainstTheStoredHash(): void
    {
        $hash = password_hash('right-password', PASSWORD_BCRYPT);

        $this->assertTrue(UserManager::verify_user_password($this->dbWith(['password' => $hash, 'use_ldap' => 0]), 5, 'right-password'));
        $this->assertFalse(UserManager::verify_user_password($this->dbWith(['password' => $hash, 'use_ldap' => 0]), 5, 'wrong-password'));
    }

    public function testAnEmptyPasswordOrAnLdapAccountNeverPasses(): void
    {
        $hash = password_hash('', PASSWORD_BCRYPT);

        $this->assertFalse(UserManager::verify_user_password($this->dbWith(['password' => $hash, 'use_ldap' => 0]), 5, ''));
        $this->assertFalse(UserManager::verify_user_password($this->dbWith(['password' => $hash, 'use_ldap' => 1]), 5, 'x'));
        $this->assertFalse(UserManager::verify_user_password($this->dbWith(['password' => '', 'use_ldap' => 0]), 5, 'x'));
        $this->assertFalse(UserManager::verify_user_password($this->dbWith(false), 5, 'x'));
    }

    private function dbWith(array|false $row): PDOLayer
    {
        $db = $this->createMock(PDOLayer::class);
        $db->method('quote')->willReturnCallback(static fn($value): string => "'" . $value . "'");
        $db->method('queryRow')->willReturn($row);

        return $db;
    }
}
