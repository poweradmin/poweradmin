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
use Poweradmin\Application\Bootstrap;
use Poweradmin\Application\Console\Command\ZoneListCommand;
use Poweradmin\Application\Service\ControllerServiceFactory;
use Poweradmin\Application\Service\DatabaseService;
use Poweradmin\Infrastructure\Database\DatabaseCredentialMapper;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDODatabaseConnection;
use Psr\Log\NullLogger;
use Throwable;

/**
 * The bin/poweradmin entry point: parses the command line, boots the same
 * service graph the web controllers use (without a session) and runs one
 * command as an explicitly named actor.
 */
final class ConsoleApplication
{
    public const EXIT_OK = 0;
    public const EXIT_USAGE = 1;
    public const EXIT_CONFIG = 2;

    private const OPTIONS = ['as-user', 'user', 'help'];

    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    /**
     * @param resource $stdout
     * @param resource $stderr
     */
    public function __construct($stdout, $stderr)
    {
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
            $unknown = $arguments->unknownOptions(self::OPTIONS);
            if ($unknown !== []) {
                throw new InvalidArgumentException(sprintf('Unknown option "--%s"', $unknown[0]));
            }
            // --user is the command-level spelling of the global --as-user
            $userId = $arguments->positiveInt('as-user') ?? $arguments->positiveInt('user');
        } catch (InvalidArgumentException $e) {
            return $this->usageError($e->getMessage());
        }

        if ($arguments->has('help')) {
            fwrite($this->stdout, self::usage());
            return self::EXIT_OK;
        }

        $command = $arguments->command();
        if ($command === null) {
            return $this->usageError('No command given');
        }
        if ($command !== ZoneListCommand::NAME) {
            return $this->usageError(sprintf('Unknown command "%s"', $command));
        }
        if ($arguments->positionals() !== []) {
            return $this->usageError(sprintf('%s takes no arguments', $command));
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

            $zoneList = new ZoneListCommand(
                $services->permissionService(),
                $services->repositoryFactory()->createDomainRepository()
            );

            return $zoneList->run($services->actor(), $this->stdout, $this->stderr);
        } catch (Throwable $e) {
            fwrite($this->stderr, 'Error: ' . $e->getMessage() . "\n");
            return self::EXIT_CONFIG;
        }
    }

    public static function usage(): string
    {
        return <<<TEXT
            Usage: bin/poweradmin [--as-user=<id>] <command> [options]

            Commands:
              zone:list [--user=<id>]   List the zones the acting user may view (id, name, type, record count)

            Options:
              --as-user=<id>   Act as this Poweradmin user; without it nobody is acting and nothing is visible
              --help, -h       Show this help

            Exit codes: 0 ok, 1 usage error, 2 configuration or database error
            The configuration file is config/settings.php or the file named by PA_CONFIG_PATH.

            TEXT;
    }

    private function usageError(string $message): int
    {
        fwrite($this->stderr, 'Error: ' . $message . "\n\n" . self::usage());
        return self::EXIT_USAGE;
    }

    /**
     * The same wiring as dynamic_update.php: configuration, database, then the
     * per-request service graph, here bound to the system actor until a user is named.
     */
    private function bootServices(): ControllerServiceFactory
    {
        $config = ConfigurationManager::getInstance();
        $config->initialize();
        Bootstrap::initializeTimezone($config);

        $db = (new DatabaseService(new PDODatabaseConnection()))->connect(DatabaseCredentialMapper::mapCredentials($config));

        return new ControllerServiceFactory($db, $config, new NullLogger(), CommandLineActor::system());
    }
}
