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

namespace Poweradmin\Tests\Unit\Application\Service\User;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\User\PasswordPolicyService;
use TestHelpers\FakeConfiguration;

class PasswordPolicyServiceTest extends TestCase
{
    public function testRulesComeFromTheInjectedConfiguration(): void
    {
        $service = new PasswordPolicyService(new FakeConfiguration(['security' => [
            'password_policy.enable_password_rules' => true,
            'password_policy.min_length' => 20,
            'password_policy.require_uppercase' => true,
            'password_policy.require_lowercase' => false,
            'password_policy.require_numbers' => false,
            'password_policy.require_special' => false,
        ]]));

        $this->assertSame(
            ['Password must be at least 20 characters long', 'Password must contain at least one uppercase letter'],
            $service->validatePassword('short')
        );
        $this->assertSame(20, $service->getPolicyConfig()['min_length']);
    }

    public function testDisabledRulesAcceptAnything(): void
    {
        $service = new PasswordPolicyService(new FakeConfiguration(['security' => [
            'password_policy.enable_password_rules' => false,
        ]]));

        $this->assertSame([], $service->validatePassword('x'));
    }
}
