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

namespace Poweradmin\Application\Console;

use InvalidArgumentException;
use Poweradmin\Application\Boot\BootOptions;
use Poweradmin\Application\Boot\Kernel;
use Poweradmin\Application\Service\ControllerServiceFactory;
use Throwable;

/**
 * The bin/poweradmin entry point: parses the command line, boots the same
 * service graph the web controllers use (without a session) and runs one
 * registered command as an explicitly named actor.
 */
final class ConsoleApplication
{
    public const EXIT_OK = 0;
    public const EXIT_USAGE = 1;
    public const EXIT_CONFIG = 2;

    private const GLOBAL_OPTIONS = ['as-user', 'help'];

    /** How each command-level option is spelled in the usage text */
    private const OPTION_USAGE = [
        'user' => '--user=<id>',
        TableWriter::OPTION => '--' . TableWriter::OPTION . '=tsv|json',
    ];

    private CommandRegistry $registry;

    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    /**
     * @param resource $stdout
     * @param resource $stderr
     */
    public function __construct(CommandRegistry $registry, $stdout, $stderr)
    {
        $this->registry = $registry;
        $this->stdout = $stdout;
        $this->stderr = $stderr;
    }

    /**
     * @param list<string> $argv The arguments without the script name
     */
    public function run(array $argv): int
    {
        try {
            $arguments = Arguments::parse($argv);
            if ($arguments->has('help')) {
                fwrite($this->stdout, $this->usage());
                return self::EXIT_OK;
            }

            $command = $arguments->command();
            if ($command === null) {
                throw new InvalidArgumentException('No command given');
            }
            if (!$this->registry->has($command)) {
                throw new InvalidArgumentException(sprintf('Unknown command "%s"', $command));
            }
            $unknown = $arguments->unknownOptions([...self::GLOBAL_OPTIONS, ...$this->registry->options($command)]);
            if ($unknown !== []) {
                throw new InvalidArgumentException(sprintf('Unknown option "--%s" for %s', $unknown[0], $command));
            }
            // --user is the command-level spelling of the global --as-user
            $userId = $arguments->positiveInt('as-user') ?? $arguments->positiveInt('user');
        } catch (InvalidArgumentException $e) {
            return $this->usageError($e->getMessage());
        }

        // One error boundary for boot and for queries that fail later (schema gaps,
        // dropped connections), so the exit code stays 2 instead of a stack trace
        try {
            $services = $this->bootServices();

            if ($userId !== null) {
                $user = $services->userRepository()->getUserById($userId);
                if ($user === null) {
                    return $this->usageError(sprintf('User %d does not exist', $userId));
                }
                $services->bindActor(new CommandLineActor($userId, (string) $user['username']));
            }

            try {
                return $this->registry->create($command, $services)
                    ->run($arguments, $services->actor(), $this->stdout, $this->stderr);
            } catch (InvalidArgumentException $e) {
                return $this->usageError($e->getMessage());
            }
        } catch (Throwable $e) {
            fwrite($this->stderr, 'Error: ' . $e->getMessage() . "\n");
            return self::EXIT_CONFIG;
        }
    }

    public function usage(): string
    {
        $commands = '';
        foreach ($this->registry->names() as $name) {
            $options = array_map(
                static fn(string $option): string => '[' . (self::OPTION_USAGE[$option] ?? '--' . $option) . ']',
                $this->registry->options($name)
            );
            $commands .= sprintf("  %s %s\n      %s\n", $name, implode(' ', $options), $this->registry->description($name));
        }
        $commands = rtrim($commands);

        return <<<TEXT
            Usage: bin/poweradmin [--as-user=<id>] <command> [options]

            Commands:
            {$commands}

            Options:
              --as-user=<id>      Act as this Poweradmin user; without it nobody is acting and nothing is visible
              --format=tsv|json   Tabular output as tab-separated lines (default) or a JSON list
              --help, -h          Show this help

            Exit codes: 0 ok, 1 usage error or refusal, 2 configuration or database error
            The configuration file is config/settings.php or the file named by PA_CONFIG_PATH.

            TEXT;
    }

    private function usageError(string $message): int
    {
        fwrite($this->stderr, 'Error: ' . $message . "\n\n" . $this->usage());
        return self::EXIT_USAGE;
    }

    /**
     * The same boot as dynamic_update.php: configuration and database, then the
     * per-request service graph, here bound to the system actor until a user is named.
     */
    private function bootServices(): ControllerServiceFactory
    {
        return Kernel::boot(BootOptions::Script)->services(CommandLineActor::system());
    }
}
