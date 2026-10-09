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

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Service\Validator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

class UserManagerEmailFormatTest extends TestCase
{
    private Validator $validator;
    private bool $strictTld = false;

    protected function setUp(): void
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            fn (string $group, string $key, mixed $default = null): mixed =>
                $group === 'dns' && $key === 'strict_tld_check' ? $this->strictTld : $default
        );
        $this->validator = new Validator($this->createMock(PDO::class), $config);
    }

    public static function acceptedAddresses(): array
    {
        return [
            'plus addressing (#1674)' => ['user+tag@example.com'],
            'dotted local part' => ['user.name@example.com'],
            'subdomain' => ['user@sub.example.com'],
            'dotless host the original check allowed' => ['admin@localhost'],
        ];
    }

    #[DataProvider('acceptedAddresses')]
    public function testAcceptsAddress(string $email): void
    {
        $this->assertTrue(UserManager::isAcceptableEmail($this->validator, $email));
    }

    public static function rejectedAddresses(): array
    {
        return [
            'no at sign' => ['user.example.com'],
            'empty domain' => ['user@'],
            'empty local part' => ['@example.com'],
            'space in local part' => ['us er@example.com'],
        ];
    }

    #[DataProvider('rejectedAddresses')]
    public function testRejectsAddress(string $email): void
    {
        $this->assertFalse(UserManager::isAcceptableEmail($this->validator, $email));
    }

    public function testPlusAddressStillHonoursStrictTldCheck(): void
    {
        $this->strictTld = true;

        $this->assertTrue(UserManager::isAcceptableEmail($this->validator, 'user+tag@example.com'));
        $this->assertFalse(UserManager::isAcceptableEmail($this->validator, 'user+tag@example.notarealtldzz'));
    }
}
