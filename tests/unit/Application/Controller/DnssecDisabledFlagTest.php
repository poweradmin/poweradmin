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

namespace Poweradmin\Tests\Unit\Application\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\DnssecAddKeyController;
use Poweradmin\Application\Controller\DnssecController;
use Poweradmin\Application\Controller\DnssecDeleteKeyController;
use Poweradmin\Application\Controller\DnssecEditKeyController;
use Poweradmin\Application\Controller\DnssecKeyExportController;
use Poweradmin\Application\Controller\DnssecKeyImportController;
use Poweradmin\Application\Controller\DnssecToggleKeyController;
use Poweradmin\Application\Http\Request;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use ReflectionProperty;

class DnssecDisabledFlagTest extends TestCase
{
    protected function tearDown(): void
    {
        $_POST = [];
    }

    public static function gatedControllers(): array
    {
        return [
            'add key' => [DnssecAddKeyController::class],
            'delete key' => [DnssecDeleteKeyController::class],
            'edit key' => [DnssecEditKeyController::class],
            'export key' => [DnssecKeyExportController::class],
            'import key' => [DnssecKeyImportController::class],
            'toggle key' => [DnssecToggleKeyController::class],
            'unsign zone' => [DnssecController::class],
        ];
    }

    #[DataProvider('gatedControllers')]
    public function testActionsAreRefusedWhileDnssecIsDisabled(string $class): void
    {
        $_POST = ['unsign_zone' => '1'];
        $probe = 'DnssecProbe' . md5($class);
        if (!class_exists($probe, false)) {
            eval('class ' . $probe . ' extends \\' . $class . ' {
                public array $errors = [];
                public function __construct() {}
                public function useConfig($c): void { $this->config = $c; }
                public function showError(string $error, ?string $recordName = null): void { $this->errors[] = $error; }
            }');
        }

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(fn(string $g, string $k, $d = null) => $g === 'dnssec' && $k === 'enabled' ? false : $d);

        $controller = new $probe();
        $controller->useConfig($config);
        if ($class === DnssecController::class) {
            (new ReflectionProperty(DnssecController::class, 'request'))->setValue($controller, new Request());
        }

        $controller->run();

        $this->assertSame(['DNSSEC is not enabled on the server.'], $controller->errors);
    }
}
