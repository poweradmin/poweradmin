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
namespace Poweradmin\Domain\Port;

use Poweradmin\Domain\Model\ZoneChangeRequest;

/**
 * Told about change request events so people can be notified. Implementations
 * must not throw; a failed notification never undoes the event.
 */
interface ChangeRequestNotifierInterface
{
    public function requestFiled(ZoneChangeRequest $request): void;

    /**
     * The request was approved (applied or failed to apply) or rejected.
     */
    public function requestDecided(ZoneChangeRequest $request): void;

    public function requestCancelled(ZoneChangeRequest $request): void;
}
