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

use Poweradmin\Domain\Port\ActorInterface;

/**
 * The actor of a console run: nobody (the system itself) unless the caller
 * named a user with --as-user, in which case that user's permissions apply.
 */
final class CommandLineActor implements ActorInterface
{
    private ?int $userId;
    private ?string $username;

    public function __construct(?int $userId = null, ?string $username = null)
    {
        $this->userId = $userId !== null && $userId > 0 ? $userId : null;
        $this->username = $username !== null && $username !== '' ? $username : null;
    }

    public static function system(): self
    {
        return new self();
    }

    public function userId(): ?int
    {
        return $this->userId;
    }

    public function username(): ?string
    {
        return $this->username;
    }
}
