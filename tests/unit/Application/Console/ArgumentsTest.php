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

#[CoversClass(Arguments::class)]
class ArgumentsTest extends TestCase
{
    public function testEmptyArgvHasNoCommand(): void
    {
        $arguments = Arguments::parse([]);

        $this->assertNull($arguments->command());
        $this->assertSame([], $arguments->positionals());
        $this->assertFalse($arguments->has('help'));
    }

    public function testFirstBareWordIsTheCommandAndTheRestArePositional(): void
    {
        $arguments = Arguments::parse(['zone:list', 'extra', 'more']);

        $this->assertSame('zone:list', $arguments->command());
        $this->assertSame(['extra', 'more'], $arguments->positionals());
    }

    public function testOptionsMayPrecedeOrFollowTheCommand(): void
    {
        $before = Arguments::parse(['--as-user=7', 'zone:list']);
        $after = Arguments::parse(['zone:list', '--as-user=7']);

        $this->assertSame('zone:list', $before->command());
        $this->assertSame('zone:list', $after->command());
        $this->assertSame('7', $before->value('as-user'));
        $this->assertSame('7', $after->value('as-user'));
    }

    public function testBareOptionIsPresentWithoutAValue(): void
    {
        $arguments = Arguments::parse(['--help']);

        $this->assertTrue($arguments->has('help'));
        $this->assertNull($arguments->value('help'));
    }

    public function testShortHelpFlagMapsToHelp(): void
    {
        $this->assertTrue(Arguments::parse(['-h'])->has('help'));
    }

    public function testValueMayContainAnEqualsSign(): void
    {
        $this->assertSame('a=b', Arguments::parse(['--name=a=b'])->value('name'));
    }

    public function testDoubleDashEndsOptionParsing(): void
    {
        $arguments = Arguments::parse(['zone:list', '--', '--not-an-option']);

        $this->assertSame(['--not-an-option'], $arguments->positionals());
        $this->assertFalse($arguments->has('not-an-option'));
    }

    public function testPositiveIntReadsANumericOption(): void
    {
        $this->assertSame(12, Arguments::parse(['--user=12'])->positiveInt('user'));
        $this->assertNull(Arguments::parse([])->positiveInt('user'));
    }

    #[DataProvider('nonPositiveIntOptions')]
    public function testPositiveIntRejectsAnythingElse(string $option): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('--user expects a positive integer');

        Arguments::parse([$option])->positiveInt('user');
    }

    /** @return iterable<string, array{string}> */
    public static function nonPositiveIntOptions(): iterable
    {
        yield 'bare' => ['--user'];
        yield 'empty' => ['--user='];
        yield 'letters' => ['--user=abc'];
        yield 'zero' => ['--user=0'];
        yield 'negative' => ['--user=-3'];
        yield 'float' => ['--user=1.5'];
    }

    public function testUnknownOptionsListsWhatIsNotAllowed(): void
    {
        $arguments = Arguments::parse(['--as-user=1', '--verbose', '--help']);

        $this->assertSame(['verbose'], $arguments->unknownOptions(['as-user', 'help']));
    }

    #[DataProvider('malformedOptions')]
    public function testMalformedOptionsAreRejected(string $option): void
    {
        $this->expectException(InvalidArgumentException::class);

        Arguments::parse([$option]);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedOptions(): iterable
    {
        yield 'short option' => ['-x'];
        yield 'empty name' => ['--=value'];
        yield 'uppercase name' => ['--As-User=1'];
        yield 'leading digit' => ['--1st'];
    }
}
