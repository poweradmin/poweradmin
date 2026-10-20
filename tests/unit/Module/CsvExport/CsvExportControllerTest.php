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

namespace Poweradmin\Tests\Unit\Module\CsvExport;

use PHPUnit\Framework\Attributes\CoversClass;
use Poweradmin\Domain\Model\RecordRow;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Module\CsvExport\Controller\CsvExportController;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;
use ReflectionMethod;

/**
 * The Comment column is exported, and its comments loaded, only when record
 * comments are enabled; the other columns stay the same either way.
 */
#[CoversClass(CsvExportController::class)]
class CsvExportControllerTest extends SeamControllerTestCase
{
    private function controller(bool $commentsEnabled): CsvExportController
    {
        $config = $this->configure(['interface' => ['show_record_comments' => $commentsEnabled]]);

        return new CsvExportController($this->requestData(), true, $this->environment($config));
    }

    /** @return list<RecordRow> */
    private function records(): array
    {
        return [
            new RecordRow(1, 7, 'www.example.com', 'A', '192.0.2.1', 3600, 0, false, 'front door'),
            new RecordRow(2, 7, 'off.example.com', 'A', '192.0.2.2', 300, 0, true),
        ];
    }

    /** @return list<list<string>> */
    private function csv(CsvExportController $controller): array
    {
        $output = fopen('php://memory', 'w+');
        (new ReflectionMethod($controller, 'writeCsv'))->invoke($controller, $output, $this->records());
        rewind($output);
        $csv = (string)stream_get_contents($output);
        fclose($output);

        return array_map(
            static fn(string $line): array => str_getcsv($line, ',', '"', ''),
            array_values(array_filter(explode("\n", $csv)))
        );
    }

    public function testCommentColumnCarriesTheRecordCommentWhenEnabled(): void
    {
        $this->assertSame([
            ['Name', 'Type', 'Content', 'Priority', 'TTL', 'Disabled', 'Comment'],
            ['www.example.com', 'A', '192.0.2.1', '0', '3600', 'No', 'front door'],
            ['off.example.com', 'A', '192.0.2.2', '0', '300', 'Yes', ''],
        ], $this->csv($this->controller(true)));
    }

    public function testCommentColumnIsAbsentWhenDisabled(): void
    {
        $this->assertSame([
            ['Name', 'Type', 'Content', 'Priority', 'TTL', 'Disabled'],
            ['www.example.com', 'A', '192.0.2.1', '0', '3600', 'No'],
            ['off.example.com', 'A', '192.0.2.2', '0', '300', 'Yes'],
        ], $this->csv($this->controller(false)));
    }

    public function testCommentsAreRequestedFromTheRepositoryOnlyWhenEnabled(): void
    {
        $seen = [];
        $repository = $this->createMock(RecordRepositoryInterface::class);
        $repository->method('getRecordsFromDomainId')->willReturnCallback(
            function (int $id, int $start, int $amount, string $sort, string $dir, bool $fetchComments) use (&$seen): array {
                $seen[] = $fetchComments;
                return [];
            }
        );
        $this->factory->method('recordRepository')->willReturn($repository);

        foreach ([true, false] as $enabled) {
            $controller = $this->controller($enabled);
            (new ReflectionMethod($controller, 'fetchZoneRecords'))->invoke($controller, 7);
        }

        $this->assertSame([true, false], $seen);
    }
}
