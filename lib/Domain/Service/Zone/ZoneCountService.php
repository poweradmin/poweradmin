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

namespace Poweradmin\Domain\Service\Zone;

use Poweradmin\Domain\Port\ActorInterface;
use Poweradmin\Domain\Repository\ZoneReadRepositoryInterface;

/**
 * Counts the zones a user may see; the backend-specific query lives in the zone repository.
 */
class ZoneCountService
{
    private ZoneReadRepositoryInterface $zoneRepository;
    private ActorInterface $actor;

    public function __construct(ZoneReadRepositoryInterface $zoneRepository, ActorInterface $actor)
    {
        $this->zoneRepository = $zoneRepository;
        $this->actor = $actor;
    }

    /**
     * Count zones with filtering options
     *
     * @param string $perm 'all', or 'own' for the acting user's zones
     * @param string $letterstart Starting letters to match (single letter or '1' for numbers) [default='all' for no filtering]
     * @param string $zone_type Type of zones to count ['all', 'forward', 'reverse'] [default='forward']
     *
     * @return int Count of zones matched
     */
    public function countZones(string $perm, string $letterstart = 'all', string $zone_type = 'forward'): int
    {
        $userId = $perm === 'own' ? $this->actor->userId() : null;

        return $this->zoneRepository->countZones($perm, $userId, $letterstart, $zone_type);
    }
}
