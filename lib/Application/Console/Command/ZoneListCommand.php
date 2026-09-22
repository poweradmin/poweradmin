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

namespace Poweradmin\Application\Console\Command;

use Poweradmin\Domain\Model\Constants;
use Poweradmin\Domain\Port\ActorInterface;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;

/**
 * zone:list - the zones the actor may view, one tab-separated row per zone.
 * Visibility follows the web zone list: the actor's view level narrows the
 * rows to owned zones (directly or through a group); an ueberuser sees all.
 */
final class ZoneListCommand
{
    public const NAME = 'zone:list';
    public const HEADER = "ID\tNAME\tTYPE\tRECORDS";

    private PermissionService $permissionService;
    private DomainRepositoryInterface $domainRepository;

    public function __construct(PermissionService $permissionService, DomainRepositoryInterface $domainRepository)
    {
        $this->permissionService = $permissionService;
        $this->domainRepository = $domainRepository;
    }

    /**
     * @param resource $stdout
     * @param resource $stderr
     */
    public function run(ActorInterface $actor, $stdout, $stderr): int
    {
        fwrite($stdout, self::HEADER . "\n");

        $userId = $actor->userId();
        $level = $userId === null ? 'none' : $this->permissionService->getViewPermissionLevel($userId);
        if ($level === 'none') {
            fwrite($stderr, "The acting user may not view any zones; pass --as-user=<id> to act as a user.\n");
            return 0;
        }

        $zones = $this->domainRepository->getZones(
            $level,
            (int) $userId,
            'all',
            0,
            Constants::DEFAULT_MAX_ROWS,
            'name',
            'ASC',
            false,
            false,
            false,
            false,
            true
        );

        foreach ($zones as $zone) {
            fwrite($stdout, sprintf(
                "%d\t%s\t%s\t%d\n",
                (int) $zone['id'],
                (string) $zone['name'],
                (string) $zone['type'],
                (int) ($zone['count_records'] ?? 0)
            ));
        }

        return 0;
    }
}
