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

class PasswordGenerationService
{
    private ConfigurationManager $configManager;

    // Default settings used when password policy is disabled
    private array $defaultSettings = [
        'min_length' => 12,
        'require_uppercase' => true,
        'require_lowercase' => true,
        'require_numbers' => true,
        'require_special' => true,
        'special_characters' => '!@#$%^&*()-_=+[]{}|;:,.<>?'
    ];

    public function __construct(?ConfigurationManager $configManager = null)
    {
        $this->configManager = $configManager ?? ConfigurationManager::getInstance();
    }

    /**
     * Generates a random password that complies with password policy
     *
     * @param int|null $length Custom length (overrides policy settings)
     * @return string Generated password
     */
    public function generatePassword(?int $length = null): string
    {
        // Determine if we should use policy settings or defaults
        $usePolicy = $this->configManager->get('security', 'password_policy.enable_password_rules');

        // Get the appropriate settings (policy or default)
        $settings = $this->getSettings($usePolicy);

        // Override length if provided
        if ($length !== null) {
            $settings['min_length'] = max($length, 6); // Enforce minimum of 6 chars
        }

        $password = '';

        // Ensure at least one character from each required set
        if ($settings['require_uppercase']) {
            $password .= $this->getRandomChar('ABCDEFGHIJKLMNOPQRSTUVWXYZ');
        }

        if ($settings['require_lowercase']) {
            $password .= $this->getRandomChar('abcdefghijklmnopqrstuvwxyz');
        }

        if ($settings['require_numbers']) {
            $password .= $this->getRandomChar('0123456789');
        }

        if ($settings['require_special']) {
            $password .= $this->getRandomChar($settings['special_characters']);
        }

        // Build a character pool based on our requirements
        $charPool = '';
        if ($settings['require_uppercase']) {
            $charPool .= 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        }
        if ($settings['require_lowercase']) {
            $charPool .= 'abcdefghijklmnopqrstuvwxyz';
        }
        if ($settings['require_numbers']) {
            $charPool .= '0123456789';
        }
        if ($settings['require_special']) {
            $charPool .= $settings['special_characters'];
        }

        // Fill up to the minimum length with random characters
        while (strlen($password) < $settings['min_length']) {
            $password .= $this->getRandomChar($charPool);
        }

        // Shuffle the password to randomize the order of characters
        return str_shuffle($password);
    }

    /**
     * Get a random character from the provided character set
     */
    private function getRandomChar(string $chars): string
    {
        $length = strlen($chars);
        return $chars[random_int(0, $length - 1)];
    }

    /**
     * Get settings based on whether we're using policy or defaults
     */
    private function getSettings(bool $usePolicy): array
    {
        if ($usePolicy) {
            return [
                'min_length' => $this->configManager->get('security', 'password_policy.min_length'),
                'require_uppercase' => $this->configManager->get('security', 'password_policy.require_uppercase'),
                'require_lowercase' => $this->configManager->get('security', 'password_policy.require_lowercase'),
                'require_numbers' => $this->configManager->get('security', 'password_policy.require_numbers'),
                'require_special' => $this->configManager->get('security', 'password_policy.require_special'),
                'special_characters' => $this->configManager->get('security', 'password_policy.special_characters')
            ];
        }

        return $this->defaultSettings;
    }
}
