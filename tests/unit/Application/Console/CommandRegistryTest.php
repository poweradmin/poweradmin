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
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Console\Arguments;
use Poweradmin\Application\Console\Command\ZoneListCommand;
use Poweradmin\Application\Console\Command\ZoneShowCommand;
use Poweradmin\Application\Console\CommandInterface;
use Poweradmin\Application\Console\CommandRegistry;
use Poweradmin\Application\Console\ConsoleApplication;
use Poweradmin\Application\Service\ControllerServiceFactory;
use Poweradmin\Domain\Port\ActorInterface;

/**
 * The registry and the part of ConsoleApplication::run() that dispatches on
 * it before the service graph boots: command lookup, per-command options and
 * the usage text.
 */
#[CoversClass(CommandRegistry::class)]
#[CoversClass(ConsoleApplication::class)]
class CommandRegistryTest extends TestCase
{
    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    protected function setUp(): void
    {
        $this->stdout = fopen('php://memory', 'w+');
        $this->stderr = fopen('php://memory', 'w+');
    }

    public function testDefaultRegistryKnowsEveryShippedCommand(): void
    {
        $registry = CommandRegistry::default();

        $this->assertSame([ZoneListCommand::NAME, ZoneShowCommand::NAME], $registry->names());
        $this->assertTrue($registry->has(ZoneShowCommand::NAME));
        $this->assertFalse($registry->has('zone:delete'));
        $this->assertSame(ZoneShowCommand::options(), $registry->options(ZoneShowCommand::NAME));
        $this->assertSame(ZoneListCommand::description(), $registry->description(ZoneListCommand::NAME));
    }

    public function testMetadataOfAnUnknownCommandThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown command "zone:delete"');

        CommandRegistry::default()->options('zone:delete');
    }

    public function testCreateRunsTheFactoryWithTheServiceGraph(): void
    {
        $services = $this->createMock(ControllerServiceFactory::class);
        $built = self::echoCommand();
        $registry = new CommandRegistry([
            get_class($built) => function (ControllerServiceFactory $given) use ($services, $built): CommandInterface {
                $this->assertSame($services, $given);
                return $built;
            },
        ]);

        $this->assertSame($built, $registry->create('test:echo', $services));
    }

    public function testUnknownCommandIsAUsageErrorBeforeBoot(): void
    {
        $exit = $this->application()->run(['zone:delete']);

        $this->assertSame(ConsoleApplication::EXIT_USAGE, $exit);
        $this->assertStringContainsString('Unknown command "zone:delete"', $this->read($this->stderr));
        $this->assertSame('', $this->read($this->stdout));
    }

    public function testOptionUnknownToTheCommandIsRejectedByName(): void
    {
        $exit = $this->application()->run(['test:echo', '--format=json']);

        $this->assertSame(ConsoleApplication::EXIT_USAGE, $exit);
        $this->assertStringContainsString('Unknown option "--format" for test:echo', $this->read($this->stderr));
    }

    public function testGlobalOptionsAreAcceptedByEveryCommand(): void
    {
        $exit = $this->application()->run(['--as-user=x', 'test:echo', '--loud']);

        // --loud and --as-user pass the option check; the bad user id is the next error
        $this->assertSame(ConsoleApplication::EXIT_USAGE, $exit);
        $this->assertStringContainsString('--as-user expects a positive integer', $this->read($this->stderr));
    }

    public function testUsageListsEveryRegisteredCommandWithItsOptions(): void
    {
        $exit = $this->application()->run(['--help']);
        $usage = $this->read($this->stdout);

        $this->assertSame(ConsoleApplication::EXIT_OK, $exit);
        $this->assertStringContainsString("  test:echo [--loud] [--user=<id>]\n      Echoes the arguments back", $usage);
        $this->assertStringContainsString("  test:noop \n      Does nothing", $usage);
    }

    public function testDefaultUsageListsEveryShippedCommand(): void
    {
        $usage = (new ConsoleApplication(CommandRegistry::default(), $this->stdout, $this->stderr))->usage();

        foreach (CommandRegistry::default()->names() as $name) {
            $this->assertStringContainsString("\n  " . $name . ' ', $usage);
        }
        $this->assertStringContainsString('[--format=tsv|json]', $usage);
    }

    private function application(): ConsoleApplication
    {
        $echo = self::echoCommand();
        $noop = self::noopCommand();
        $registry = new CommandRegistry([
            get_class($echo) => static fn(ControllerServiceFactory $services): CommandInterface => $echo,
            get_class($noop) => static fn(ControllerServiceFactory $services): CommandInterface => $noop,
        ]);

        return new ConsoleApplication($registry, $this->stdout, $this->stderr);
    }

    /** @param resource $stream */
    private function read($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    private static function echoCommand(): CommandInterface
    {
        return new class implements CommandInterface {
            public static function name(): string
            {
                return 'test:echo';
            }

            public static function description(): string
            {
                return 'Echoes the arguments back';
            }

            public static function options(): array
            {
                return ['loud', 'user'];
            }

            public function run(Arguments $arguments, ActorInterface $actor, $stdout, $stderr): int
            {
                fwrite($stdout, implode(' ', $arguments->positionals()) . "\n");

                return 0;
            }
        };
    }

    private static function noopCommand(): CommandInterface
    {
        return new class implements CommandInterface {
            public static function name(): string
            {
                return 'test:noop';
            }

            public static function description(): string
            {
                return 'Does nothing';
            }

            public static function options(): array
            {
                return [];
            }

            public function run(Arguments $arguments, ActorInterface $actor, $stdout, $stderr): int
            {
                return 0;
            }
        };
    }
}
