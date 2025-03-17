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

namespace Poweradmin\Application\Service;

use Poweradmin\Domain\Repository\UserRepository;
use Poweradmin\Domain\Service\UserContextService;

class PasswordChangeService
{
    private const ERROR_MESSAGES = [
        'user_not_found' => 'User not found',
        'ldap_user' => 'You can not change your password as LDAP user.',
        'invalid_password' => 'You did not enter the correct current password.',
        'password_changed' => 'Password has been changed, please login.',
    ];

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly UserAuthenticationService $authService,
        private readonly UserContextService $userContextService,
    ) {}

    public function changePassword(string $oldPassword, string $newPassword): array
    {
        $username = $this->userContextService->getLoggedInUsername();
        $user = $this->userRepository->findByUsername($username);

        if ($user === null) {
            return [false, _(self::ERROR_MESSAGES['user_not_found'])];
        }

        if ($user->isLdapUser()) {
            return [false, _(self::ERROR_MESSAGES['ldap_user'])];
        }

        if (!$this->authService->verifyPassword($oldPassword, $user->getPassword())) {
            return [false, _(self::ERROR_MESSAGES['invalid_password'])];
        }

        $hashedPassword = $this->authService->hashPassword($newPassword);
        $updated = $this->userRepository->updatePassword($user->getId(), $hashedPassword);

        return $updated
            ? [true, _(self::ERROR_MESSAGES['password_changed'])]
            : [false, _('Failed to update password')];
    }
}
