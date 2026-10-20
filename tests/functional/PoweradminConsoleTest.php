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

declare(strict_types=1);

namespace Poweradmin\Tests\Functional;

use TestHelpers\ConsoleSqliteTestCase;

/**
 * The bin/poweradmin dispatch and zone:list, end to end against the shared
 * SQLite fixture.
 */
class PoweradminConsoleTest extends ConsoleSqliteTestCase
{
    public function testUeberuserSeesEveryZoneWithRecordCounts(): void
    {
        [$exit, $stdout, $stderr] = $this->runConsole(['--as-user=1', 'zone:list']);

        $this->assertSame(0, $exit, $stderr);
        $this->assertSame("ID\tNAME\tTYPE\tRECORDS\n10\texample.com\tMASTER\t2\n11\texample.net\tNATIVE\t1\n", $stdout);
        $this->assertSame('', $stderr);
    }

    public function testViewerSeesOnlyOwnedZonesWithTheCommandLevelOption(): void
    {
        [$exit, $stdout] = $this->runConsole(['zone:list', '--user=2']);

        $this->assertSame(0, $exit);
        $this->assertSame("ID\tNAME\tTYPE\tRECORDS\n10\texample.com\tMASTER\t2\n", $stdout);
    }

    public function testJsonFormatListsTheSameZonesAsObjects(): void
    {
        [$exit, $stdout, $stderr] = $this->runConsole(['--as-user=1', 'zone:list', '--format=json']);

        $this->assertSame(0, $exit, $stderr);
        $this->assertSame(
            '[{"id":10,"name":"example.com","type":"MASTER","records":2},{"id":11,"name":"example.net","type":"NATIVE","records":1}]' . "\n",
            $stdout
        );
    }

    public function testExplicitTsvFormatMatchesTheDefault(): void
    {
        [, $default] = $this->runConsole(['--as-user=1', 'zone:list']);
        [$exit, $tsv] = $this->runConsole(['--as-user=1', 'zone:list', '--format=tsv']);

        $this->assertSame(0, $exit);
        $this->assertSame($default, $tsv);
    }

    public function testGuestAndSystemActorSeeOnlyTheHeader(): void
    {
        foreach ([['zone:list'], ['--as-user=3', 'zone:list']] as $argv) {
            [$exit, $stdout, $stderr] = $this->runConsole($argv);

            $this->assertSame(0, $exit);
            $this->assertSame("ID\tNAME\tTYPE\tRECORDS\n", $stdout);
            $this->assertStringContainsString('may not view any zones', $stderr);
        }
    }

    public function testUsageErrorsExitWithOne(): void
    {
        $cases = [
            'no command' => [[], 'No command given'],
            'unknown command' => [['zone:delete'], 'Unknown command "zone:delete"'],
            'unknown option' => [['zone:list', '--verbose'], 'Unknown option "--verbose" for zone:list'],
            'unknown format' => [['--as-user=1', 'zone:list', '--format=xml'], 'Option --format expects one of tsv, json'],
            'non-numeric user' => [['--as-user=root', 'zone:list'], 'expects a positive integer'],
            'stray argument' => [['zone:list', 'example.com'], 'takes no arguments'],
            'missing user' => [['--as-user=99', 'zone:list'], 'User 99 does not exist'],
        ];

        foreach ($cases as $label => [$argv, $message]) {
            [$exit, $stdout, $stderr] = $this->runConsole($argv);

            $this->assertSame(1, $exit, $label);
            $this->assertStringContainsString($message, $stderr, $label);
            $this->assertStringContainsString('Usage: bin/poweradmin', $stderr, $label);
            $this->assertSame('', $stdout, $label);
        }
    }

    public function testHelpListsEveryCommandAndExitsZero(): void
    {
        [$exit, $stdout] = $this->runConsole(['--help']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('zone:list', $stdout);
        $this->assertStringContainsString('zone:show', $stdout);
        $this->assertStringContainsString('--format=tsv|json', $stdout);
    }

    public function testUnreachableDatabaseExitsWithTwo(): void
    {
        $settings = self::writeSettings('broken-settings.php', '/nonexistent/poweradmin.sqlite');

        [$exit, $stdout, $stderr] = $this->runConsole(['zone:list'], $settings);

        $this->assertSame(2, $exit);
        $this->assertStringContainsString('Database connection failed', $stderr);
        $this->assertSame('', $stdout);
    }

    public function testAQueryFailureAfterBootExitsWithTwo(): void
    {
        $dbFile = self::$fixtureDir . '/empty.sqlite';
        touch($dbFile);
        $settings = self::writeSettings('empty-settings.php', $dbFile);

        [$exit, $stdout, $stderr] = $this->runConsole(['--as-user=1', 'zone:list'], $settings);

        $this->assertSame(2, $exit);
        $this->assertStringStartsWith('Error: ', $stderr);
        $this->assertStringNotContainsString('Stack trace', $stderr);
        $this->assertSame('', $stdout);
    }
}
