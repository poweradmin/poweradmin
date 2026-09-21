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

namespace Poweradmin\Application\Service\Auth;

use Poweradmin\Domain\Port\ActorInterface;

/**
 * The actor every service of one request shares. It starts as whatever the
 * bootstrap knows (the session user); an API controller rebinds it to the key
 * owner once authentication succeeds, so services built before and after that
 * point agree on who is acting.
 */
final class RequestActor implements ActorInterface
{
    private ActorInterface $actor;

    public function __construct(ActorInterface $actor)
    {
        $this->actor = $actor;
    }

    public function bind(ActorInterface $actor): void
    {
        $this->actor = $actor;
    }

    public function userId(): ?int
    {
        return $this->actor->userId();
    }

    public function username(): ?string
    {
        return $this->actor->username();
    }
}
