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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\User\UserCommandFactory;
use Poweradmin\Domain\Service\User\CreateUserCommand;
use Poweradmin\Domain\Service\User\UpdateUserCommand;
use Poweradmin\Domain\Service\User\UserManagementService;
use Poweradmin\Domain\Service\Validation\Refusal;

/**
 * The API body and the web forms reach UserManagementService through this
 * mapping, so the values it produces are what the repository binds.
 */
#[CoversClass(UserCommandFactory::class)]
class UserCommandFactoryTest extends TestCase
{
    #[Test]
    public function createMapsEveryApiField(): void
    {
        $command = UserCommandFactory::create([
            'username' => 'newuser',
            'password' => 'Secret123!',
            'fullname' => 'New User',
            'email' => 'new@example.com',
            'description' => 'desc',
            'active' => false,
            'perm_templ' => '3',
            'use_ldap' => 'false',
        ]);

        $this->assertEquals(
            new CreateUserCommand('newuser', 'Secret123!', 'New User', 'new@example.com', 'desc', false, 3, false),
            $command
        );
    }

    #[Test]
    public function createDefaultsOmittedFieldsAsTheRepositoryDid(): void
    {
        $command = UserCommandFactory::create(['username' => 'ldapuser', 'use_ldap' => true, 'perm_templ' => 3]);

        $this->assertEquals(new CreateUserCommand('ldapuser', null, '', '', '', true, 3, true), $command);
    }

    #[Test]
    public function createTreatsAnEmptyPasswordAsNoneAndNullActiveAsActive(): void
    {
        $command = UserCommandFactory::create(['username' => 'u', 'password' => '', 'active' => null]);

        $this->assertInstanceOf(CreateUserCommand::class, $command);
        $this->assertNull($command->password);
        $this->assertFalse($command->passwordGiven());
        $this->assertTrue($command->active);
        $this->assertNull($command->permissionTemplateId);
    }

    #[Test]
    public function createRefusesANonBooleanLdapFlag(): void
    {
        $result = UserCommandFactory::create(['username' => 'u', 'password' => 'x', 'use_ldap' => 'maybe']);

        $this->assertSame([
            'success' => false,
            'message' => 'use_ldap must be a boolean',
            'refusal' => Refusal::INVALID_INPUT,
            'code' => UserManagementService::ERR_INVALID_LDAP,
        ], $result);
    }

    public static function malformedTemplateIds(): array
    {
        return [
            'zero' => [0],
            'negative' => [-3],
            'word' => ['admin'],
            'trailing junk' => ['2foo'],
            'empty string' => [''],
            'float' => [2.5],
        ];
    }

    #[Test]
    #[DataProvider('malformedTemplateIds')]
    public function createRefusesAMalformedTemplateId(mixed $permTempl): void
    {
        $result = UserCommandFactory::create(['username' => 'u', 'password' => 'x', 'perm_templ' => $permTempl]);

        $this->assertSame([
            'success' => false,
            'message' => 'Permission template not found',
            'refusal' => Refusal::INVALID_INPUT,
            'code' => UserManagementService::ERR_TEMPLATE_NOT_FOUND,
        ], $result);
    }

    #[Test]
    public function updateLeavesOmittedFieldsNull(): void
    {
        $command = UserCommandFactory::update(['fullname' => 'Renamed', 'active' => 1]);

        $this->assertEquals(new UpdateUserCommand(fullname: 'Renamed', active: true), $command);
    }

    #[Test]
    public function updateMapsEveryApiField(): void
    {
        $command = UserCommandFactory::update([
            'username' => 'renamed',
            'password' => 'Secret123!',
            'fullname' => 'Full',
            'email' => 'renamed@example.com',
            'description' => 'd',
            'active' => 0,
            'perm_templ' => '2',
            'use_ldap' => 'false',
        ]);

        $this->assertEquals(new UpdateUserCommand('renamed', 'Secret123!', 'Full', 'renamed@example.com', 'd', false, 2, false), $command);
    }

    #[Test]
    public function updateKeepsAnEmptyUsernameSoTheServiceCanRefuseIt(): void
    {
        $command = UserCommandFactory::update(['username' => '', 'password' => '']);

        $this->assertEquals(new UpdateUserCommand(username: ''), $command);
    }

    #[Test]
    #[DataProvider('malformedTemplateIds')]
    public function updateRefusesAMalformedTemplateId(mixed $permTempl): void
    {
        $result = UserCommandFactory::update(['perm_templ' => $permTempl]);

        $this->assertIsArray($result);
        $this->assertSame('Permission template not found', $result['message']);
        $this->assertSame(Refusal::INVALID_INPUT, $result['refusal']);
    }

    #[Test]
    public function updateRefusesANullTemplateIdButCreateDoesNot(): void
    {
        $refused = UserCommandFactory::update(['perm_templ' => null]);
        $this->assertIsArray($refused);
        $this->assertSame(UserManagementService::ERR_TEMPLATE_NOT_FOUND, $refused['code']);

        $command = UserCommandFactory::create(['username' => 'u', 'password' => 'x', 'perm_templ' => null]);
        $this->assertInstanceOf(CreateUserCommand::class, $command);
        $this->assertNull($command->permissionTemplateId);
    }

    #[Test]
    public function updateRefusesANonBooleanLdapFlag(): void
    {
        $result = UserCommandFactory::update(['use_ldap' => [1]]);

        $this->assertIsArray($result);
        $this->assertSame('use_ldap must be a boolean', $result['message']);
    }

    #[Test]
    public function passwordGivenTreatsZeroAsAPassword(): void
    {
        $this->assertTrue(UserCommandFactory::passwordGiven(['password' => '0']));
        $this->assertFalse(UserCommandFactory::passwordGiven(['password' => '']));
        $this->assertFalse(UserCommandFactory::passwordGiven(['password' => null]));
        $this->assertFalse(UserCommandFactory::passwordGiven([]));
    }

    #[Test]
    public function anArrayPasswordIsRefusedInsteadOfBeingTreatedAsAbsent(): void
    {
        $update = UserCommandFactory::update(['fullname' => 'x', 'password' => ['a']]);
        $this->assertIsArray($update);
        $this->assertSame(Refusal::INVALID_INPUT, $update['refusal']);
        $this->assertSame('Invalid field types in request body', $update['message']);

        $create = UserCommandFactory::create(['username' => 'u', 'password' => ['a']]);
        $this->assertIsArray($create);
        $this->assertSame(Refusal::INVALID_INPUT, $create['refusal']);
    }
}
