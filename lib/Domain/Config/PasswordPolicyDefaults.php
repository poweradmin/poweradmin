<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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

namespace Poweradmin\Domain\Config;

class PasswordPolicyDefaults
{
    public static function getDefaults(): array
    {
        return [
            'enable_password_rules' => false,
            'min_length' => 6,
            'require_uppercase' => true,
            'require_lowercase' => true,
            'require_numbers' => true,
            'require_special' => false,
            'special_characters' => '!@#$%^&*()+-=[]{}|;:,.<>?',

            'enable_expiration' => false,
            'max_age_days' => 90,

            'enable_reuse_prevention' => false,
            'prevent_reuse' => 5,

            'enable_lockout' => false,
            'lockout_attempts' => 5,
            'lockout_duration' => 15, // Duration in minutes
        ];
    }
}
