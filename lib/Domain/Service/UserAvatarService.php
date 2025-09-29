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

use Poweradmin\Application\Service\AvatarService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Service for handling user-specific avatar functionality
 */
class UserAvatarService
{
    private UserContextService $userContextService;
    private AvatarService $avatarService;

    public function __construct(
        UserContextService $userContextService,
        ConfigurationManager $configManager
    ) {
        $this->userContextService = $userContextService;
        $this->avatarService = new AvatarService($configManager);
    }

    /**
     * Get the current user's avatar URL
     *
     * @param int|null $size Avatar size in pixels (optional)
     * @return string|null The avatar URL or null if not available/enabled
     */
    public function getCurrentUserAvatarUrl(?int $size = null): ?string
    {
        if (!$this->userContextService->isAuthenticated()) {
            return null;
        }

        if (!$this->avatarService->isAvatarEnabled()) {
            return null;
        }

        $userEmail = $this->userContextService->getUserEmail();
        $oauthAvatarUrl = $this->userContextService->getOAuthAvatarUrl();

        return $this->avatarService->getAvatarUrl($userEmail, $oauthAvatarUrl, $size);
    }

    /**
     * Check if avatar functionality is enabled
     *
     * @return bool
     */
    public function isAvatarEnabled(): bool
    {
        return $this->avatarService->isAvatarEnabled();
    }

    /**
     * Check if OAuth avatars are enabled
     *
     * @return bool
     */
    public function isOAuthAvatarEnabled(): bool
    {
        return $this->avatarService->isOAuthAvatarEnabled();
    }

    /**
     * Check if Gravatar is enabled
     *
     * @return bool
     */
    public function isGravatarEnabled(): bool
    {
        return $this->avatarService->isGravatarEnabled();
    }

    /**
     * Get the current user's Gravatar URL (if they have an email)
     *
     * @param int|null $size Avatar size in pixels (optional)
     * @return string|null The Gravatar URL or null if no email/disabled
     */
    public function getCurrentUserGravatarUrl(?int $size = null): ?string
    {
        $userEmail = $this->userContextService->getUserEmail();

        if (!$userEmail) {
            return null;
        }

        return $this->avatarService->getGravatarUrl($userEmail, $size ?? $this->avatarService->getDefaultSize());
    }

    /**
     * Get the current user's OAuth avatar URL (if available)
     *
     * @return string|null The OAuth avatar URL or null if not available
     */
    public function getCurrentUserOAuthAvatarUrl(): ?string
    {
        return $this->userContextService->getOAuthAvatarUrl();
    }
}
