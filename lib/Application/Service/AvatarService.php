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

use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

class AvatarService
{
    private ConfigurationManager $configManager;
    private array $cache = [];

    public function __construct(ConfigurationManager $configManager)
    {
        $this->configManager = $configManager;
    }

    public function getAvatarUrl(?string $email, ?string $oauthAvatarUrl = null, int $size = null): ?string
    {
        $oauthEnabled = $this->configManager->get('interface', 'avatar_oauth_enabled', false);
        $gravatarEnabled = $this->configManager->get('interface', 'avatar_gravatar_enabled', false);
        $priority = $this->configManager->get('interface', 'avatar_priority', 'oauth');
        $defaultSize = $this->configManager->get('interface', 'avatar_size', 40);

        $size = $size ?? $defaultSize;

        if (!$oauthEnabled && !$gravatarEnabled) {
            return null;
        }

        if ($priority === 'oauth') {
            // Try OAuth first if enabled
            if ($oauthEnabled && $oauthAvatarUrl && $this->isValidAvatarUrl($oauthAvatarUrl)) {
                return $this->processAvatarUrl($oauthAvatarUrl, $size);
            }
            // Fallback to Gravatar if enabled
            if ($gravatarEnabled && $email) {
                return $this->getGravatarUrl($email, $size);
            }
        } elseif ($priority === 'gravatar') {
            // Try Gravatar first if enabled
            if ($gravatarEnabled && $email) {
                $gravatarUrl = $this->getGravatarUrl($email, $size);
                if ($gravatarUrl) {
                    return $gravatarUrl;
                }
            }
            // Fallback to OAuth if enabled
            if ($oauthEnabled && $oauthAvatarUrl && $this->isValidAvatarUrl($oauthAvatarUrl)) {
                return $this->processAvatarUrl($oauthAvatarUrl, $size);
            }
        }

        return null;
    }

    public function getGravatarUrl(string $email, int $size = 40): ?string
    {
        if (!$this->configManager->get('interface', 'avatar_gravatar_enabled', false)) {
            return null;
        }

        $hash = md5(strtolower(trim($email)));
        $defaultImage = 'mp'; // mystery person default

        return "https://www.gravatar.com/avatar/{$hash}?s={$size}&d={$defaultImage}";
    }

    public function isOAuthAvatarEnabled(): bool
    {
        return $this->configManager->get('interface', 'avatar_oauth_enabled', false);
    }

    public function isGravatarEnabled(): bool
    {
        return $this->configManager->get('interface', 'avatar_gravatar_enabled', false);
    }

    public function getDefaultSize(): int
    {
        return $this->configManager->get('interface', 'avatar_size', 40);
    }

    public function isAvatarEnabled(): bool
    {
        return $this->isOAuthAvatarEnabled() || $this->isGravatarEnabled();
    }

    private function isValidAvatarUrl(?string $url): bool
    {
        if (empty($url)) {
            return false;
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parsedUrl = parse_url($url);
        if (!$parsedUrl || !isset($parsedUrl['scheme'], $parsedUrl['host'])) {
            return false;
        }

        if (!in_array(strtolower($parsedUrl['scheme']), ['http', 'https'])) {
            return false;
        }

        // Microsoft Graph photo URLs require authentication and can't be used as direct image sources
        // Skip OAuth avatars for Microsoft Graph and fall back to Gravatar
        if (strpos($url, 'graph.microsoft.com') !== false && strpos($url, '/photo') !== false) {
            return false;
        }

        return true;
    }

    private function processAvatarUrl(string $url, int $size): string
    {
        if (
            strpos($url, 'graph.microsoft.com') !== false ||
            strpos($url, 'accounts.google.com') !== false
        ) {
            return $this->adjustSizeParameter($url, $size);
        }

        return $url;
    }

    private function adjustSizeParameter(string $url, int $size): string
    {
        $parsedUrl = parse_url($url);
        if (!$parsedUrl) {
            return $url;
        }

        if (strpos($url, 'graph.microsoft.com') !== false) {
            // For Microsoft Graph photo URLs, don't add query parameters to /photo/$value endpoints
            // Instead, use size-specific endpoints if we want to resize
            if (strpos($url, '/photo/$value') !== false) {
                // Option 1: Use size-specific endpoint (e.g., /photos/48x48/$value)
                // Replace /photo/$value with /photos/{size}x{size}/$value for common sizes
                $sizeMap = [
                    20 => '48x48',   // Smallest available
                    24 => '48x48',
                    40 => '48x48',
                    48 => '48x48',
                    64 => '64x64',
                    96 => '96x96',
                    120 => '120x120',
                    240 => '240x240',
                    360 => '360x360',
                    432 => '432x432',
                    504 => '504x504',
                    648 => '648x648'
                ];

                // Find the best matching size
                $targetSize = $sizeMap[$size] ?? '48x48';
                foreach ($sizeMap as $threshold => $graphSize) {
                    if ($size <= $threshold) {
                        $targetSize = $graphSize;
                        break;
                    }
                }

                // Replace /photo/$value with /photos/{size}/$value
                $url = str_replace('/photo/$value', "/photos/{$targetSize}/\$value", $url);
            }
            // For other Microsoft Graph URLs (not photo endpoints), return as-is
            return $url;
        } elseif (strpos($url, 'accounts.google.com') !== false) {
            // For Google, we can safely add size parameter
            parse_str($parsedUrl['query'] ?? '', $queryParams);
            $queryParams['sz'] = $size;
            $url = $parsedUrl['scheme'] . '://' . $parsedUrl['host'] . $parsedUrl['path'] . '?' . http_build_query($queryParams);
        }

        return $url;
    }

    public function clearCache(): void
    {
        $this->cache = [];
    }
}
