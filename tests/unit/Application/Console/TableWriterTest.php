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

namespace Poweradmin\Tests\Unit\Application\Console;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Console\Arguments;
use Poweradmin\Application\Console\TableWriter;

#[CoversClass(TableWriter::class)]
class TableWriterTest extends TestCase
{
    /** @var resource */
    private $stdout;

    protected function setUp(): void
    {
        $this->stdout = fopen('php://memory', 'w+');
    }

    public function testTsvIsTheDefaultAndWritesAHeaderThenOneLinePerRow(): void
    {
        $writer = TableWriter::fromArguments(Arguments::parse(['zone:list']), $this->stdout);

        $writer->write(['ID', 'NAME', 'DISABLED'], [[10, 'example.com', false], [11, 'example.net', true]]);

        $this->assertSame("ID\tNAME\tDISABLED\n10\texample.com\t0\n11\texample.net\t1\n", $this->read());
    }

    public function testExplicitTsvWritesOnlyTheHeaderForNoRows(): void
    {
        $writer = TableWriter::fromArguments(Arguments::parse(['--format=tsv']), $this->stdout);

        $writer->write(['ID', 'NAME'], []);

        $this->assertSame("ID\tNAME\n", $this->read());
    }

    public function testJsonWritesAListOfObjectsKeyedByLowerCasedColumns(): void
    {
        $writer = TableWriter::fromArguments(Arguments::parse(['--format=json']), $this->stdout);

        $writer->write(['ID', 'NAME', 'DISABLED'], [[10, 'a/b', false]]);

        $this->assertSame('[{"id":10,"name":"a/b","disabled":false}]' . "\n", $this->read());
    }

    public function testJsonWritesAnEmptyListForNoRows(): void
    {
        $writer = TableWriter::fromArguments(Arguments::parse(['--format=json']), $this->stdout);

        $writer->write(['ID'], []);

        $this->assertSame("[]\n", $this->read());
    }

    #[DataProvider('unsupportedFormats')]
    public function testAnythingElseIsRejected(string $option): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Option --format expects one of tsv, json');

        TableWriter::fromArguments(Arguments::parse([$option]), $this->stdout);
    }

    /** @return iterable<string, array{string}> */
    public static function unsupportedFormats(): iterable
    {
        yield 'bare' => ['--format'];
        yield 'empty' => ['--format='];
        yield 'xml' => ['--format=xml'];
        yield 'uppercase' => ['--format=JSON'];
    }

    private function read(): string
    {
        rewind($this->stdout);

        return (string) stream_get_contents($this->stdout);
    }
}
