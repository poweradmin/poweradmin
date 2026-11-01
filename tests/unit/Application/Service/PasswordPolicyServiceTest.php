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

namespace Poweradmin\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\PasswordPolicyService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

class PasswordPolicyServiceTest extends TestCase
{
    private function serviceWithRules(bool $enabled): PasswordPolicyService
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            fn($group, $key, $default = null) => $key === 'password_policy.enable_password_rules' ? $enabled : $default
        );

        return new PasswordPolicyService($config);
    }

    public function testDisabledRulesAcceptAnyNonEmptyPassword(): void
    {
        $this->assertSame([], $this->serviceWithRules(false)->validatePassword('x'));
    }

    public function testAnEmptyPasswordIsRefusedEvenWithRulesDisabled(): void
    {
        $this->assertSame(['An empty password is not allowed'], $this->serviceWithRules(false)->validatePassword(''));
    }
}
