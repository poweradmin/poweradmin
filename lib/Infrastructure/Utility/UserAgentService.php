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

namespace Poweradmin\Infrastructure\Utility;

/**
 * Service for safely handling and sanitizing User-Agent strings
 *
 * This service provides methods to safely retrieve and sanitize User-Agent
 * strings for logging purposes, preventing potential security issues such as
 * log injection attacks.
 */
class UserAgentService
{
    private const MAX_LENGTH = 512;
    private const DEFAULT_USER_AGENT = 'unknown';

    private array $server;

    public function __construct(array $server)
    {
        $this->server = $server;
    }

    /**
     * Get the User-Agent string safely sanitized for logging
     *
     * @return string Sanitized User-Agent string
     */
    public function getUserAgent(): string
    {
        $userAgent = $this->server['HTTP_USER_AGENT'] ?? self::DEFAULT_USER_AGENT;

        if ($userAgent === self::DEFAULT_USER_AGENT) {
            return $userAgent;
        }

        return $this->sanitize($userAgent);
    }

    /**
     * Get a shortened version of the User-Agent string
     *
     * @param int $maxLength Maximum length for the shortened string
     * @return string Shortened and sanitized User-Agent string
     */
    public function getShortUserAgent(int $maxLength = 100): string
    {
        $userAgent = $this->getUserAgent();

        if (strlen($userAgent) <= $maxLength) {
            return $userAgent;
        }

        return substr($userAgent, 0, $maxLength - 3) . '...';
    }

    /**
     * Get browser name and version from User-Agent
     *
     * @return string Browser identification (e.g., "Chrome/120.0", "Firefox/121.0", "Unknown")
     */
    public function getBrowserInfo(): string
    {
        $userAgent = $this->server['HTTP_USER_AGENT'] ?? '';

        if (empty($userAgent)) {
            return 'Unknown';
        }

        // Common browser patterns (order matters!)
        $patterns = [
            '/OPR\/([0-9.]+)/' => 'Opera',  // Check Opera before Chrome
            '/Edg\/([0-9.]+)/' => 'Edge',   // Check Edge before Chrome
            '/Edge\/([0-9.]+)/' => 'Edge',
            '/Trident\/.*rv:([0-9.]+)/' => 'IE',
            '/Firefox\/([0-9.]+)/' => 'Firefox',
            '/Version\/([0-9.]+).*Safari/' => 'Safari',  // Check Safari before Chrome
            '/Chrome\/([0-9.]+)/' => 'Chrome',  // Chrome must be last as many browsers include it
        ];

        foreach ($patterns as $pattern => $browser) {
            if (preg_match($pattern, $userAgent, $matches)) {
                $version = $matches[1] ?? 'Unknown';
                return $this->sanitize("$browser/$version");
            }
        }

        return 'Unknown';
    }

    /**
     * Check if the request is from a bot/crawler
     *
     * @return bool True if User-Agent appears to be a bot
     */
    public function isBot(): bool
    {
        $userAgent = strtolower($this->server['HTTP_USER_AGENT'] ?? '');

        if (empty($userAgent)) {
            return false;
        }

        $botPatterns = [
            'bot', 'crawler', 'spider', 'scraper', 'curl', 'wget',
            'python', 'java', 'ruby', 'go-http', 'postman', 'insomnia'
        ];

        foreach ($botPatterns as $pattern) {
            if (str_contains($userAgent, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sanitize User-Agent string for safe logging
     *
     * @param string $userAgent Raw User-Agent string
     * @return string Sanitized User-Agent string
     */
    private function sanitize(string $userAgent): string
    {
        // Remove null bytes
        $userAgent = str_replace("\0", '', $userAgent);

        // Replace control characters with spaces
        $userAgent = preg_replace('/[\x00-\x1F\x7F]/', ' ', $userAgent);

        // Replace multiple spaces with single space
        $userAgent = preg_replace('/\s+/', ' ', $userAgent);

        // Trim whitespace
        $userAgent = trim($userAgent);

        // Limit length to prevent log bloat
        if (strlen($userAgent) > self::MAX_LENGTH) {
            $userAgent = substr($userAgent, 0, self::MAX_LENGTH - 3) . '...';
        }

        // Escape special characters that might interfere with log parsing
        $userAgent = addslashes($userAgent);

        return $userAgent ?: self::DEFAULT_USER_AGENT;
    }
}
