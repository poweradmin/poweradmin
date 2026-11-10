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

use DomainException;
use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\BulkRegistrationController;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use ReflectionMethod;
use Throwable;

/**
 * Bulk zone registration refuses an oversized list before it creates or looks up anything.
 */
class BulkRegistrationControllerLineCapTest extends TestCase
{
    private array $savedPost = [];

    protected function setUp(): void
    {
        $this->savedPost = $_POST;
    }

    protected function tearDown(): void
    {
        $_POST = $this->savedPost;
    }

    public function testAListAboveTheCapIsRefusedBeforeAnyZoneIsCreated(): void
    {
        $this->assertSame(
            sprintf('At most %d rows can be added at a time.', BulkRegistrationController::MAX_LINES),
            $this->refusalFor(BulkRegistrationController::MAX_LINES + 1)
        );
    }

    public function testAListAtTheCapIsNotRefused(): void
    {
        $this->assertNull($this->refusalFor(BulkRegistrationController::MAX_LINES));
    }

    private function refusalFor(int $count): ?string
    {
        $_POST = [
            'dom_type' => 'MASTER',
            'zone_template' => '1',
            'domains' => implode("\n", array_map(static fn(int $i): string => "z$i.example", range(1, $count))),
        ];

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(fn(string $group, string $key, mixed $default = null): mixed => $default);

        $controller = new class ([], new PDO('sqlite::memory:'), $config) extends BulkRegistrationController {
            use ControllerTestStubs;

            public function setMessage(string $script, string $type, string $content): void
            {
                throw new DomainException($content);
            }
        };

        try {
            (new ReflectionMethod(BulkRegistrationController::class, 'doBulkRegistration'))->invoke($controller);
        } catch (DomainException $refused) {
            return $refused->getMessage();
        } catch (Throwable) {
            // Past the cap the unwired controller fails on its own services
            return null;
        }

        return null;
    }
}
