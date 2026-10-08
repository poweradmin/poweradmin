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

namespace Poweradmin\Tests\Unit\Application\Controller\Log;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\Log\ListLogZonesController;
use Poweradmin\Application\Http\Request as HttpRequest;
use Poweradmin\Application\Module\ModuleRegistry;
use Poweradmin\Application\Service\ControllerEnvironment;
use Poweradmin\Domain\Service\Auth\UserContextService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * Zone log events carry different fields per operation, so the CSV header must
 * cover every row's fields and each value must sit under its own column.
 */
#[CoversClass(ListLogZonesController::class)]
class ListLogZonesCsvExportTest extends TestCase
{
    private function environment(): ControllerEnvironment
    {
        return new ControllerEnvironment(
            ConfigurationManager::getInstance(),
            $this->createMock(PDO::class),
            new NullLogger(),
            new ModuleRegistry(ConfigurationManager::getInstance()),
            null,
            new HttpRequest(),
            null,
            null,
            $this->createMock(UserContextService::class)
        );
    }

    public function testRowsWithDifferentFieldsStayUnderTheirOwnColumns(): void
    {
        $controller = new ListLogZonesController([], true, $this->environment());

        $output = fopen('php://memory', 'w+');
        (new ReflectionMethod($controller, 'writeCsvRows'))->invoke($controller, $output, [
            ['timestamp' => 't1', 'operation' => 'add_record', 'record' => 'a.example.com'],
            ['timestamp' => 't2', 'operation' => 'add_zone', 'zone' => 'example.com'],
        ]);
        rewind($output);
        $lines = array_map('str_getcsv', array_filter(explode("\n", (string)stream_get_contents($output))));
        fclose($output);

        $this->assertSame([
            ['timestamp', 'operation', 'record', 'zone'],
            ['t1', 'add_record', 'a.example.com', ''],
            ['t2', 'add_zone', '', 'example.com'],
        ], array_values($lines));
    }

    public function testRecordContentWithSpacesStaysInOneColumn(): void
    {
        $controller = new ListLogZonesController([], true, $this->environment());

        $parsed = (new ReflectionMethod($controller, 'parseLogEvents'))->invoke($controller, [[
            'created_at' => 't1',
            'event' => 'client_ip:192.0.2.10 user:alice operation:add_record record_type:TXT record:t.example.com'
                . ' content:"foo key1:v hello" ttl:3600 priority:0',
        ]]);

        $this->assertSame('"foo key1:v hello"', $parsed[0]['content']);
        $this->assertSame('3600', $parsed[0]['ttl']);
        $this->assertArrayNotHasKey('key1', $parsed[0]);
    }

    public function testQuotesAreDoubledAndBackslashesKeptLiteral(): void
    {
        $controller = new ListLogZonesController([], true, $this->environment());

        $output = fopen('php://memory', 'w+');
        (new ReflectionMethod($controller, 'writeCsvRows'))->invoke($controller, $output, [
            ['content' => 'say \\"hi\\"', 'path' => 'C:\\zones\\', 'note' => '=1+1'],
        ]);
        rewind($output);
        $csv = (string)stream_get_contents($output);
        fclose($output);

        $this->assertStringContainsString('"say \\""hi\\"""', $csv);
        $rows = array_map(
            static fn(string $line): array => str_getcsv($line, ',', '"', ''),
            array_values(array_filter(explode("\n", $csv)))
        );
        $this->assertSame([
            ['content', 'path', 'note'],
            ['say \\"hi\\"', 'C:\\zones\\', "'=1+1"],
        ], $rows);
    }
}
