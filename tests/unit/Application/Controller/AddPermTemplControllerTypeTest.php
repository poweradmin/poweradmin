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

namespace Poweradmin\Tests\Unit\Application\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\AddPermTemplController;
use Poweradmin\Application\Service\PermissionTemplateWriteService;
use Poweradmin\Domain\Repository\UserRepository;
use Poweradmin\Domain\Service\SessionKeys;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\DbPermissionTemplateRepository;
use Poweradmin\Infrastructure\Service\MessageService;
use ReflectionClass;

/**
 * An absent template_type passes the request rules and the repository would file
 * the template as a user template, so the controller refuses it before the write.
 */
class AddPermTemplControllerTypeTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION[SessionKeys::USERID] = 1;
    }

    protected function tearDown(): void
    {
        unset($_SESSION[SessionKeys::USERID]);
        parent::tearDown();
    }

    public static function missingOrInvalidTypes(): array
    {
        return [
            'missing' => [[]],
            'empty' => [['template_type' => '']],
            'unknown' => [['template_type' => 'admin']],
        ];
    }

    /**
     * @param array<string, mixed> $type
     */
    #[DataProvider('missingOrInvalidTypes')]
    public function testMissingOrInvalidTemplateTypeIsRefusedBeforeAnyWrite(array $type): void
    {
        $repository = $this->createMock(DbPermissionTemplateRepository::class);
        $repository->expects($this->never())->method('addPermissionTemplate');

        $userRepository = $this->createMock(UserRepository::class);
        $userRepository->method('hasAdminPermission')->willReturn(true);

        $reflection = new ReflectionClass(AddPermTemplController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('permissionTemplate')->setValue($controller, $repository);
        $reflection->getProperty('permissionTemplateWriteService')->setValue(
            $controller,
            new PermissionTemplateWriteService($repository, $userRepository)
        );
        $reflection->getProperty('userContextService')->setValue($controller, new UserContextService());

        $config = ConfigurationManager::getInstance();
        $configReflection = new ReflectionClass(ConfigurationManager::class);
        $configReflection->getProperty('settings')->setValue($config, ['security' => ['global_token_validation' => false]]);
        $configReflection->getProperty('initialized')->setValue($config, true);

        $base = $reflection->getParentClass()->getName();
        foreach (['config' => $config, 'messageService' => new MessageService(), 'requestData' => ['templ_name' => 'Ordinary'] + $type] as $name => $value) {
            (new \ReflectionProperty($base, $name))->setValue($controller, $value);
        }

        try {
            $reflection->getMethod('handleFormSubmission')->invoke($controller);
        } catch (\Throwable) {
            // showForm() needs a rendering stack; refusing before it is what matters here.
        }
    }
}
