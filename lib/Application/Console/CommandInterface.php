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
use Poweradmin\Domain\Port\ActorInterface;

/**
 * One bin/poweradmin command. The metadata is static so the registry can
 * print usage and validate options before the service graph is booted.
 */
interface CommandInterface
{
    public static function name(): string;

    public static function description(): string;

    /**
     * The command-level option names accepted next to the global ones.
     *
     * @return list<string>
     */
    public static function options(): array;

    /**
     * @param resource $stdout
     * @param resource $stderr
     * @throws InvalidArgumentException on a usage error; the application prints it and exits 1
     */
    public function run(Arguments $arguments, ActorInterface $actor, $stdout, $stderr): int;
}
