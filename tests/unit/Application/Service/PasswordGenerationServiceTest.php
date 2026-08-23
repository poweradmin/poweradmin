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
use Poweradmin\Application\Service\PasswordGenerationService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

class PasswordGenerationServiceTest extends TestCase
{
    private const SPECIAL = '!@#$%^&*()-_=+[]{}|;:,.<>?';

    public function testDefaultPasswordSatisfiesEveryRequiredClass(): void
    {
        $password = $this->serviceWithDefaults()->generatePassword();

        $this->assertMatchesRegularExpression('/[A-Z]/', $password);
        $this->assertMatchesRegularExpression('/[a-z]/', $password);
        $this->assertMatchesRegularExpression('/[0-9]/', $password);
        $this->assertTrue(
            strpbrk($password, self::SPECIAL) !== false,
            'Generated password contains no special character'
        );
    }

    public function testDefaultPasswordUsesTheDefaultMinimumLength(): void
    {
        $this->assertSame(12, strlen($this->serviceWithDefaults()->generatePassword()));
    }

    public function testExplicitLengthOverridesThePolicyLength(): void
    {
        $this->assertSame(24, strlen($this->serviceWithDefaults()->generatePassword(24)));
    }

    public function testLengthIsFlooredAtSixCharacters(): void
    {
        $this->assertSame(6, strlen($this->serviceWithDefaults()->generatePassword(3)));
    }

    public function testPolicySettingsDropTheClassesTheyDisable(): void
    {
        $service = $this->serviceWithPolicy([
            'password_policy.min_length' => 16,
            'password_policy.require_uppercase' => false,
            'password_policy.require_lowercase' => true,
            'password_policy.require_numbers' => true,
            'password_policy.require_special' => false,
            'password_policy.special_characters' => self::SPECIAL,
        ]);

        $password = $service->generatePassword();

        $this->assertSame(16, strlen($password));
        $this->assertMatchesRegularExpression('/^[a-z0-9]+$/', $password);
    }

    /**
     * The required-class characters are appended in a fixed order, so without a
     * shuffle every password would start uppercase, lowercase, digit, special.
     */
    public function testRequiredClassesDoNotStayInTheirAppendOrder(): void
    {
        $service = $this->serviceWithDefaults();

        $inAppendOrder = 0;
        for ($i = 0; $i < 200; $i++) {
            $password = $service->generatePassword();
            if (preg_match('/^[A-Z][a-z][0-9]/', $password) === 1) {
                $inAppendOrder++;
            }
        }

        $this->assertLessThan(20, $inAppendOrder, 'Generated passwords keep their append order');
    }

    public function testConsecutiveCallsDoNotRepeat(): void
    {
        $service = $this->serviceWithDefaults();

        $passwords = [];
        for ($i = 0; $i < 50; $i++) {
            $passwords[] = $service->generatePassword();
        }

        $this->assertCount(50, array_unique($passwords));
    }

    private function serviceWithDefaults(): PasswordGenerationService
    {
        return $this->serviceWithPolicy([]);
    }

    /**
     * @param array<string, mixed> $policy Empty leaves the policy disabled, so
     *                                     the service falls back to its defaults.
     */
    private function serviceWithPolicy(array $policy): PasswordGenerationService
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            static function (string $group, string $key, mixed $default = null) use ($policy): mixed {
                if ($key === 'password_policy.enable_password_rules') {
                    return $policy !== [];
                }

                return $policy[$key] ?? $default;
            }
        );

        return new PasswordGenerationService($config);
    }
}
