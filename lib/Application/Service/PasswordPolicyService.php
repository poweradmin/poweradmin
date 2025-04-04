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

namespace Poweradmin\Application\Service;

use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

class PasswordPolicyService
{
    private ConfigurationManager $configManager;

    public function __construct(ConfigurationManager $configManager = null)
    {
        $this->configManager = $configManager ?? ConfigurationManager::getInstance();
    }

    public function validatePassword(string $password): array
    {
        $errors = [];

        if ($this->configManager->get('security', 'password_policy.enable_password_rules')) {
            $minLength = $this->configManager->get('security', 'password_policy.min_length');
            if (strlen($password) < $minLength) {
                $errors[] = "Password must be at least {$minLength} characters long";
            }

            if ($this->configManager->get('security', 'password_policy.require_uppercase') && !preg_match('/[A-Z]/', $password)) {
                $errors[] = 'Password must contain at least one uppercase letter';
            }

            if ($this->configManager->get('security', 'password_policy.require_lowercase') && !preg_match('/[a-z]/', $password)) {
                $errors[] = 'Password must contain at least one lowercase letter';
            }

            if ($this->configManager->get('security', 'password_policy.require_numbers') && !preg_match('/[0-9]/', $password)) {
                $errors[] = 'Password must contain at least one number';
            }

            if ($this->configManager->get('security', 'password_policy.require_special')) {
                $specialChars = preg_quote($this->configManager->get('security', 'password_policy.special_characters'), '/');
                if (!preg_match("/[$specialChars]/", $password)) {
                    $errors[] = 'Password must contain at least one special character';
                }
            }
        }

        return $errors;
    }

    public function getPolicyConfig(): array
    {
        return [
            'enabled' => $this->configManager->get('security', 'password_policy.enable_password_rules'),
            'min_length' => $this->configManager->get('security', 'password_policy.min_length'),
            'require_uppercase' => $this->configManager->get('security', 'password_policy.require_uppercase'),
            'require_lowercase' => $this->configManager->get('security', 'password_policy.require_lowercase'),
            'require_numbers' => $this->configManager->get('security', 'password_policy.require_numbers'),
            'require_special' => $this->configManager->get('security', 'password_policy.require_special'),
            'special_characters' => $this->configManager->get('security', 'password_policy.special_characters'),
        ];
    }
}