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
use Poweradmin\Application\Controller\DeleteDomainsController;
use Poweradmin\Application\Controller\UnlinkZonesTemplController;
use Poweradmin\Application\Http\Request;
use ReflectionProperty;
use RuntimeException;

/**
 * A bulk zone form posted with the wrong shape is refused as an empty selection, not a crash.
 */
class BulkZonePostShapeTest extends TestCase
{
    private array $postBackup = [];
    private mixed $methodBackup = null;

    protected function setUp(): void
    {
        $this->postBackup = $_POST;
        $this->methodBackup = $_SERVER['REQUEST_METHOD'] ?? null;
    }

    protected function tearDown(): void
    {
        $_POST = $this->postBackup;
        if ($this->methodBackup === null) {
            unset($_SERVER['REQUEST_METHOD']);
        } else {
            $_SERVER['REQUEST_METHOD'] = $this->methodBackup;
        }
    }

    public function testUnlinkWithScalarZoneIdsAnswersNoValidZones(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['zone_ids' => 'abc', 'template_id' => '1'];

        $controller = new class ([]) extends UnlinkZonesTemplController {
            use ControllerTestStubs;
        };
        $controller->permissions = ['user_is_ueberuser'];
        (new ReflectionProperty(UnlinkZonesTemplController::class, 'request'))->setValue($controller, new Request());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No valid zones selected.');
        $controller->run();
    }

    public function testDeleteWithNoUsableZoneIdAnswersNoZoneSelected(): void
    {
        $_POST = ['zone_id' => ['0', 'abc', ['5'], '-3']];

        $controller = new class ([]) extends DeleteDomainsController {
            use ControllerTestStubs;

            public array $messages = [];

            public function redirect(string $url, array $args = []): void
            {
            }

            public function setMessage(string $script, string $type, string $content): void
            {
                $this->messages[] = $content;
            }
        };
        (new ReflectionProperty(DeleteDomainsController::class, 'request'))->setValue($controller, new Request());

        $controller->run();

        $this->assertSame(['No zone selected for deletion.'], $controller->messages);
    }
}
