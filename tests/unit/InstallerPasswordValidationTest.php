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

namespace Poweradmin\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PoweradminInstall\Validators\UserPasswordValidationTrait;
use Symfony\Component\Validator\Validation;

class InstallerPasswordValidationTest extends TestCase
{
    /** @return list<string> */
    private function violations(string $password): array
    {
        $validator = new class {
            use UserPasswordValidationTrait;

            public array $config = ['password_policy' => [
                'min_length' => 6,
                'require_uppercase' => false,
                'require_lowercase' => false,
                'require_numbers' => false,
                'require_special' => false,
                'special_characters' => '!@#',
            ]];
        };
        $constraints = $validator->getPasswordValidationConstraints()['pa_pass'];
        $messages = [];
        foreach (Validation::createValidator()->validate($password, $constraints) as $violation) {
            $messages[] = (string)$violation->getMessage();
        }

        return $messages;
    }

    /** bcrypt, the default hash, would silently drop everything past byte 72 */
    public function testAnAdminPasswordOverSeventyTwoBytesIsRefused(): void
    {
        $this->assertSame([], $this->violations(str_repeat('a', 72)));
        $this->assertSame(['Password must not be longer than 72 bytes.'], $this->violations(str_repeat('a', 73)));
    }

    public function testAnAdminPasswordWithANulByteIsRefused(): void
    {
        $this->assertSame(['Password must not contain a NUL character.'], $this->violations("secret\0pass"));
    }
}
