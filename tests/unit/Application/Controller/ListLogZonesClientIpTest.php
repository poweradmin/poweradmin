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

use stdClass;
use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\ListLogZonesController;
use Poweradmin\Application\Http\Request;
use Poweradmin\BaseController;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\DbZoneLogger;
use ReflectionClass;
use ReflectionMethod;

/**
 * A zone owner sees who changed the zone, not the address the change came from.
 */
class ListLogZonesClientIpTest extends TestCase
{
    private const EVENT = 'client_ip:203.0.113.9 user:alice operation:add_record zone:example.com';

    private mixed $savedSession = null;

    protected function setUp(): void
    {
        $this->savedSession = $_SESSION ?? null;
        $_SESSION = ['userid' => 5];
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->savedSession ?? [];
    }

    public function testTheOwnerExportDropsTheClientIp(): void
    {
        $controller = $this->controller(new stdClass());

        $rows = (new ReflectionMethod(ListLogZonesController::class, 'parseLogEvents'))
            ->invoke($controller, [['created_at' => '2026-01-01 10:00:00', 'event' => self::EVENT]], true);

        $this->assertArrayNotHasKey('client_ip', $rows[0]);
        $this->assertSame('alice', $rows[0]['user']);
        $this->assertSame('add_record', $rows[0]['operation']);
    }

    public function testTheFullViewExportKeepsTheClientIp(): void
    {
        $controller = $this->controller(new stdClass());

        $rows = (new ReflectionMethod(ListLogZonesController::class, 'parseLogEvents'))
            ->invoke($controller, [['created_at' => '2026-01-01 10:00:00', 'event' => self::EVENT]], false);

        $this->assertSame('203.0.113.9', $rows[0]['client_ip']);
    }

    public function testTheOwnerListAndDetailsModalDropTheClientIp(): void
    {
        $rendered = new stdClass();
        $controller = $this->controller($rendered);

        (new ReflectionMethod(ListLogZonesController::class, 'showListLogZones'))->invoke($controller, true);

        $row = $rendered->params['data'][0];
        $this->assertStringNotContainsString('203.0.113.9', $row['event']);
        $this->assertStringContainsString('user:alice operation:add_record', $row['event']);
    }

    public function testTheFullViewListKeepsTheClientIp(): void
    {
        $rendered = new stdClass();
        $controller = $this->controller($rendered);

        (new ReflectionMethod(ListLogZonesController::class, 'showListLogZones'))->invoke($controller, false);

        $this->assertStringContainsString('client_ip:203.0.113.9', $rendered->params['data'][0]['event']);
    }

    private function controller(stdClass $rendered): ListLogZonesController
    {
        $controller = new class ($rendered) extends ListLogZonesController {
            public function __construct(private readonly stdClass $rendered)
            {
            }

            public function render(string $template, array $params): void
            {
                $this->rendered->params = $params;
            }
        };

        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER NULL, owner INTEGER NOT NULL)');
        $db->exec('CREATE TABLE zones_groups (domain_id INTEGER, group_id INTEGER)');
        $db->exec('CREATE TABLE user_group_members (user_id INTEGER, group_id INTEGER)');

        $logger = $this->createMock(DbZoneLogger::class);
        $logger->method('countFilteredLogs')->willReturn(1);
        $logger->method('getFilteredLogs')->willReturn([['id' => 1, 'created_at' => '2026-01-01 10:00:00', 'event' => self::EVENT]]);
        $logger->method('getDistinctOperations')->willReturn([]);
        $logger->method('getDistinctUsers')->willReturn([]);
        $logger->method('getDistinctUsersForZones')->willReturn([]);

        $request = $this->createMock(Request::class);
        $request->method('getQueryParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => $default);

        $class = new ReflectionClass(ListLogZonesController::class);
        $class->getProperty('dbZoneLogger')->setValue($controller, $logger);
        $class->getProperty('httpRequest')->setValue($controller, $request);
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(static fn (string $group, string $key, mixed $default = null): mixed => $default);

        $base = new ReflectionClass(BaseController::class);
        $base->getProperty('db')->setValue($controller, $db);
        $base->getProperty('config')->setValue($controller, $config);
        $base->getProperty('userContextService')->setValue($controller, new UserContextService());

        return $controller;
    }
}
