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

namespace Poweradmin\Application\Service\User;

use Poweradmin\Domain\Model\User;
use Poweradmin\Domain\Repository\UserCredentialWriterInterface;
use Poweradmin\Domain\Repository\UserLookupInterface;
use Poweradmin\Domain\Service\Auth\UserContextService;
use Poweradmin\Application\Service\Auth\LoginAttemptService;
use Poweradmin\Application\Service\Auth\UserAuthenticationService;

/**
 * Changes the logged-in user's own password after verifying the current one; LDAP users are refused.
 */
readonly class PasswordChangeService
{
    private const ERROR_MESSAGES = [
        'user_not_found' => 'User not found',
        'ldap_user' => 'You can not change your password as LDAP user.',
        'invalid_password' => 'You did not enter the correct current password.',
        'password_changed' => 'Password has been changed, please login.',
    ];

    public function __construct(
        private UserLookupInterface&UserCredentialWriterInterface $userRepository,
        private UserAuthenticationService $authService,
        private UserContextService $userContextService,
        private ?LoginAttemptService $loginAttempts = null,
        private string $clientIp = '',
    ) {
    }

    /**
     * Proves the signed-in user knows their current password, counted on the reauth
     * stage; for changes to the account that must not ride on a borrowed session.
     *
     * @return array{0: bool, 1: string} success flag and, on failure, the message to show
     */
    public function confirmCurrentPassword(#[\SensitiveParameter] string $password): array
    {
        [$user, $error] = $this->reauthenticate($password);

        return $user === null ? [false, (string)$error] : [true, ''];
    }

    public function changePassword(#[\SensitiveParameter] string $oldPassword, #[\SensitiveParameter] string $newPassword): array
    {
        [$user, $error] = $this->reauthenticate($oldPassword);
        if ($user === null) {
            return [false, (string)$error];
        }

        $hashedPassword = $this->authService->hashPassword($newPassword);
        $updated = $this->userRepository->updatePassword($user->getId(), $hashedPassword);

        return $updated
            ? [true, _(self::ERROR_MESSAGES['password_changed'])]
            : [false, _('Failed to update password')];
    }

    /**
     * @return array{0: ?User, 1: ?string} the verified account, or null with the message to show
     */
    private function reauthenticate(#[\SensitiveParameter] string $password): array
    {
        // The signed-in account, not the login name: a session past the password but still
        // waiting for its second factor carries the name without a user id
        $userId = $this->userContextService->getLoggedInUserId();
        $username = $this->userContextService->getLoggedInUsername();
        $user = $userId !== null && $username !== null ? $this->userRepository->findByUsername($username) : null;

        if ($user === null || $user->getId() !== $userId) {
            return [null, _(self::ERROR_MESSAGES['user_not_found'])];
        }

        if ($user->isLdapUser()) {
            return [null, _(self::ERROR_MESSAGES['ldap_user'])];
        }

        // Counted apart from login, and before the check so parallel guesses cannot all read an empty counter
        $stage = LoginAttemptService::STAGE_REAUTH;
        if ($this->loginAttempts?->isAccountLocked($username, $this->clientIp, $stage, $userId)) {
            return [null, _('Too many failed attempts. Please try again later.')];
        }
        if ($this->loginAttempts !== null && !$this->loginAttempts->reserveAttempt($username, $this->clientIp, $stage, $userId)) {
            return [null, _('Too many failed attempts. Please try again later.')];
        }

        if (!$this->authService->verifyPassword($password, $user->getPassword())) {
            return [null, _(self::ERROR_MESSAGES['invalid_password'])];
        }
        $this->loginAttempts?->recordAttempt($username, $this->clientIp, true, $stage, $userId);

        return [$user, null];
    }
}
