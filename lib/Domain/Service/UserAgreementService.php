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

use Poweradmin\Domain\Repository\UserAgreementRepositoryInterface;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

class UserAgreementService
{
    private UserAgreementRepositoryInterface $repository;
    private ConfigurationManager $config;

    public function __construct(
        UserAgreementRepositoryInterface $repository,
        ConfigurationManager $config
    ) {
        $this->repository = $repository;
        $this->config = $config;
    }

    /**
     * Check if user agreement is required for the given user
     *
     * @param int $userId
     * @return bool
     */
    public function isAgreementRequired(int $userId): bool
    {
        if (!$this->config->get('user_agreement', 'enabled', false)) {
            return false;
        }

        $currentVersion = $this->config->get('user_agreement', 'current_version', '1.0');
        $requireOnVersionChange = $this->config->get('user_agreement', 'require_on_version_change', true);

        // Check if user has accepted the current version
        if ($this->repository->hasUserAcceptedAgreement($userId, $currentVersion)) {
            return false; // User has accepted current version
        }

        // If version change requirement is disabled, check if user has accepted ANY version
        if (!$requireOnVersionChange) {
            $userAgreements = $this->repository->getUserAgreements($userId);
            return empty($userAgreements); // Only require if user has never accepted any version
        }

        // Version change requirement is enabled - user must accept current version
        return true;
    }

    /**
     * Record user agreement acceptance
     *
     * @param int $userId
     * @param string $ipAddress
     * @param string $userAgent
     * @return bool
     */
    public function recordAgreementAcceptance(
        int $userId,
        string $ipAddress,
        string $userAgent
    ): bool {
        $currentVersion = $this->config->get('user_agreement', 'current_version', '1.0');

        return $this->repository->recordAcceptance($userId, $currentVersion, $ipAddress, $userAgent);
    }

    /**
     * Get the template path for the agreement page
     * Always returns the default template since customization is done via content inclusion
     *
     * @param string $theme Current theme (e.g., 'default')
     * @return string
     */
    public function getAgreementTemplate(string $theme = 'default'): string
    {
        return 'user_agreement.html';
    }

    /**
     * Check if custom agreement content exists
     *
     * @param string $theme Current theme (e.g., 'default')
     * @return bool
     */
    public function hasCustomContent(string $theme = 'default'): bool
    {
        $customContentPath = "templates/{$theme}/custom/user_agreement_content.html";
        return file_exists($customContentPath);
    }

    /**
     * Get current agreement version
     *
     * @return string
     */
    public function getCurrentVersion(): string
    {
        return $this->config->get('user_agreement', 'current_version', '1.0');
    }

    /**
     * Check if agreement system is enabled
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->config->get('user_agreement', 'enabled', false);
    }
}
