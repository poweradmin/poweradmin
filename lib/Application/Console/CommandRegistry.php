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

use Closure;
use InvalidArgumentException;
use Poweradmin\Application\Console\Command\ZoneListCommand;
use Poweradmin\Application\Console\Command\ZoneShowCommand;
use Poweradmin\Application\Service\ControllerServiceFactory;

/**
 * The commands bin/poweradmin knows, keyed by name. Each entry is a factory
 * that builds the command from the booted service graph.
 */
final class CommandRegistry
{
    /** @var array<string, class-string<CommandInterface>> */
    private array $classes = [];

    /** @var array<string, Closure(ControllerServiceFactory): CommandInterface> */
    private array $factories = [];

    /**
     * @param array<class-string<CommandInterface>, Closure(ControllerServiceFactory): CommandInterface> $commands
     */
    public function __construct(array $commands)
    {
        foreach ($commands as $class => $factory) {
            $name = $class::name();
            $this->classes[$name] = $class;
            $this->factories[$name] = $factory;
        }
    }

    public static function default(): self
    {
        return new self([
            ZoneListCommand::class => static fn(ControllerServiceFactory $services): CommandInterface => new ZoneListCommand(
                $services->permissionService(),
                $services->repositoryFactory()->createDomainRepository()
            ),
            ZoneShowCommand::class => static fn(ControllerServiceFactory $services): CommandInterface => new ZoneShowCommand(
                $services->permissionService(),
                $services->repositoryFactory()->createDomainRepository(),
                $services->repositoryFactory()->createRecordRepository()
            ),
        ]);
    }

    public function has(string $name): bool
    {
        return isset($this->classes[$name]);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->classes);
    }

    public function description(string $name): string
    {
        return $this->classOf($name)::description();
    }

    /** @return list<string> */
    public function options(string $name): array
    {
        return $this->classOf($name)::options();
    }

    public function create(string $name, ControllerServiceFactory $services): CommandInterface
    {
        $this->classOf($name);

        return ($this->factories[$name])($services);
    }

    /**
     * @return class-string<CommandInterface>
     * @throws InvalidArgumentException for a name not in the registry
     */
    private function classOf(string $name): string
    {
        if (!isset($this->classes[$name])) {
            throw new InvalidArgumentException(sprintf('Unknown command "%s"', $name));
        }

        return $this->classes[$name];
    }
}
