<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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

namespace Poweradmin\Domain\Service;

use Poweradmin\Application\Service\UserAuthenticationService;
use Poweradmin\Domain\Model\User;
use Poweradmin\Domain\Repository\DynamicDnsRepositoryInterface;
use Poweradmin\Domain\ValueObject\DynamicDnsRequest;

class DynamicDnsAuthenticationService
{
    private DynamicDnsRepositoryInterface $repository;
    private UserAuthenticationService $userAuthService;

    public function __construct(DynamicDnsRepositoryInterface $repository, UserAuthenticationService $userAuthService)
    {
        $this->repository = $repository;
        $this->userAuthService = $userAuthService;
    }

    public function authenticateUser(DynamicDnsRequest $request): ?User
    {
        if (!$request->hasUsername()) {
            return null;
        }

        $user = $this->repository->findUserByUsernameWithDynamicDnsPermissions($request->getUsername());
        if (!$user) {
            return null;
        }

        if (!$this->userAuthService->verifyPassword($request->getPassword(), $user->getPassword())) {
            return null;
        }

        return $user;
    }


    public function getUserZones(User $user): array
    {
        return $this->repository->getUserZones($user);
    }

    public function userCanUpdateZone(User $user, int $zoneId): bool
    {
        $userZones = $this->getUserZones($user);
        return in_array($zoneId, $userZones, true);
    }
}
