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

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\EditPermTemplController;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\DbPermissionTemplateRepository;
use Poweradmin\Infrastructure\Service\MessageService;
use ReflectionClass;
use RuntimeException;

/**
 * The edit form posts its own `templ_id` alongside the route `{id}`, and the repository
 * keys every statement off the posted one. Without pinning it to the route,
 * POST /permissions/templates/5/edit with templ_id=1 rewrote template 1 (Administrator).
 */
class EditPermTemplControllerTargetTest extends TestCase
{
    private ReflectionClass $reflection;

    protected function setUp(): void
    {
        $this->reflection = new ReflectionClass(EditPermTemplController::class);
    }

    public function testPostedTemplateIdCannotRedirectTheWrite(): void
    {
        $details = $this->captureWrite([
            'id' => '5',
            'templ_id' => '1',
            'templ_name' => 'Ordinary',
            'template_type' => 'user',
            'perm_id' => ['7'],
        ]);

        $this->assertSame(5, $details['templ_id'], 'The route id must win over the posted templ_id');
    }

    public function testRouteIdIsUsedWhenNoTemplateIdIsPosted(): void
    {
        $details = $this->captureWrite([
            'id' => '5',
            'templ_name' => 'Ordinary',
            'template_type' => 'user',
        ]);

        $this->assertSame(5, $details['templ_id']);
    }

    public function testScalarPermIdIsNormalisedSoTheWriterNeverIteratesAString(): void
    {
        $details = $this->captureWrite([
            'id' => '5',
            'templ_name' => 'Ordinary',
            'template_type' => 'user',
            'perm_id' => '7',
        ]);

        $this->assertSame([], $details['perm_id']);
    }

    public function testCheckedPermissionsSurviveUnchanged(): void
    {
        $details = $this->captureWrite([
            'id' => '5',
            'templ_name' => 'Ordinary',
            'template_type' => 'user',
            'perm_id' => ['7', '9'],
        ]);

        $this->assertSame(['7', '9'], $details['perm_id']);
    }

    /**
     * Invoke handleFormSubmission() and return the payload the repository was handed.
     * The repository throws so the method stops before redirect(), which would exit().
     *
     * @param array<string, mixed> $requestData
     * @return array<string, mixed>
     */
    private function captureWrite(array $requestData): array
    {
        $captured = [];

        $repository = $this->createMock(DbPermissionTemplateRepository::class);
        $repository->expects($this->once())
            ->method('updatePermissionTemplateDetails')
            ->with($this->callback(function (array $details) use (&$captured): bool {
                $captured = $details;
                return true;
            }))
            ->willThrowException(new RuntimeException('stop before redirect'));

        $controller = $this->reflection->newInstanceWithoutConstructor();
        $this->setProperty($controller, 'permissionTemplate', $repository);
        $this->setBaseProperty($controller, 'config', $this->primeConfig());
        $this->setBaseProperty($controller, 'messageService', new MessageService());
        $this->setBaseProperty($controller, 'requestData', $requestData);

        $method = $this->reflection->getMethod('handleFormSubmission');
        $method->setAccessible(true);

        try {
            $method->invoke($controller);
            $this->fail('Expected the repository stub to short-circuit the redirect');
        } catch (RuntimeException $e) {
            $this->assertSame('stop before redirect', $e->getMessage());
        }

        return $captured;
    }

    private function primeConfig(): ConfigurationManager
    {
        $config = ConfigurationManager::getInstance();
        $reflection = new ReflectionClass(ConfigurationManager::class);

        $settings = $reflection->getProperty('settings');
        $settings->setAccessible(true);
        $settings->setValue($config, ['security' => ['global_token_validation' => false]]);

        $initialized = $reflection->getProperty('initialized');
        $initialized->setAccessible(true);
        $initialized->setValue($config, true);

        return $config;
    }

    private function setProperty(object $target, string $name, mixed $value): void
    {
        $property = $this->reflection->getProperty($name);
        $property->setAccessible(true);
        $property->setValue($target, $value);
    }

    private function setBaseProperty(object $target, string $name, mixed $value): void
    {
        $property = new \ReflectionProperty($this->reflection->getParentClass()->getName(), $name);
        $property->setAccessible(true);
        $property->setValue($target, $value);
    }
}
