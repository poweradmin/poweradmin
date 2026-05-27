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

namespace Poweradmin\Domain\Service;

use Poweradmin\Application\Service\LoginAttemptService;
use Poweradmin\Application\Service\UserAuthenticationService;
use Poweradmin\Domain\Model\User;
use Poweradmin\Domain\Repository\DynamicDnsRepositoryInterface;
use Poweradmin\Domain\ValueObject\DynamicDnsRequest;

class DynamicDnsAuthenticationService
{
    public function __construct(
        private readonly DynamicDnsRepositoryInterface $repository,
        private readonly UserAuthenticationService $userAuthService,
        private readonly ?LoginAttemptService $loginAttemptService = null
    ) {
    }

    /**
     * Authenticate a DDNS update request.
     *
     * @param string $clientIp client IP for lockout tracking; pass '' to disable
     *                         per-IP throttle for callers that don't have a stable
     *                         peer address (e.g. CLI bootstrap).
     */
    public function authenticateUser(DynamicDnsRequest $request, string $clientIp = ''): ?User
    {
        if (!$request->hasUsername()) {
            return null;
        }

        $username = $request->getUsername();

        if ($this->loginAttemptService !== null && $this->loginAttemptService->isAccountLocked($username, $clientIp)) {
            return null;
        }

        $user = $this->repository->findUserByUsernameWithDynamicDnsPermissions($username);
        if (!$user) {
            return null;
        }

        $passwordValid = $this->userAuthService->verifyPassword($request->getPassword(), $user->getPassword());
        $this->loginAttemptService?->recordAttempt($username, $clientIp, $passwordValid);

        return $passwordValid ? $user : null;
    }

    public function getUserZones(User $user): array
    {
        return $this->repository->getUserZones($user);
    }

    public function userCanUpdateZone(User $user, int $zoneId): bool
    {
        return array_key_exists($zoneId, $this->getUserZones($user));
    }
}
