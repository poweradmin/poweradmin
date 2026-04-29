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

namespace Poweradmin\Infrastructure\Network;

/**
 * Resolves HTTP(S)_PROXY / NO_PROXY environment variables into stream-context
 * options usable by PHP's HTTP wrapper. PHP's stream wrapper does not pick up
 * these variables on its own, so each call site needs to opt in explicitly.
 */
final class ProxyContext
{
    /**
     * Build the proxy-related http context options for a target URL, or an
     * empty array when no proxy applies.
     *
     * @return array{proxy?: string, request_fulluri?: bool}
     */
    public static function httpOptionsFor(string $url): array
    {
        $parsed = parse_url($url);
        if (!is_array($parsed) || empty($parsed['scheme']) || empty($parsed['host'])) {
            return [];
        }

        $scheme = strtolower($parsed['scheme']);
        $host = strtolower($parsed['host']);

        if (self::matchesNoProxy($host, self::env('NO_PROXY', 'no_proxy'))) {
            return [];
        }

        // For plain HTTP we only honor the lowercase `http_proxy` variable.
        // Uppercase HTTP_PROXY is unsafe in CGI/FastCGI because it can be
        // populated from a request's `Proxy:` header (CVE-2016-5385, "httpoxy").
        // HTTPS_PROXY has no equivalent header-injection vector, so either
        // case is safe to honor.
        $proxyRaw = $scheme === 'https'
            ? self::env('HTTPS_PROXY', 'https_proxy')
            : self::env(null, 'http_proxy');

        if ($proxyRaw === null) {
            return [];
        }

        $proxy = self::toStreamProxy($proxyRaw);
        if ($proxy === null) {
            return [];
        }

        return [
            'proxy' => $proxy,
            'request_fulluri' => true,
        ];
    }

    /**
     * Merge proxy options into an existing stream-context options array under
     * the `http` sub-key. Returns the array unchanged when no proxy applies.
     */
    public static function applyTo(array $options, string $url): array
    {
        $proxyOpts = self::httpOptionsFor($url);
        if ($proxyOpts === []) {
            return $options;
        }

        $http = isset($options['http']) && is_array($options['http']) ? $options['http'] : [];
        $options['http'] = array_merge($http, $proxyOpts);
        return $options;
    }

    private static function env(?string $upper, string $lower): ?string
    {
        if ($upper !== null) {
            $value = getenv($upper);
            if ($value !== false && $value !== '') {
                return $value;
            }
        }

        $value = getenv($lower);
        if ($value === false || $value === '') {
            return null;
        }
        return $value;
    }

    private static function toStreamProxy(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (!preg_match('#^[a-z][a-z0-9+\-.]*://#i', $raw)) {
            $raw = 'tcp://' . $raw;
        }

        $parts = parse_url($raw);
        if (!is_array($parts) || empty($parts['host'])) {
            return null;
        }

        $port = $parts['port'] ?? (
            isset($parts['scheme']) && strtolower($parts['scheme']) === 'https' ? 443 : 80
        );

        return 'tcp://' . $parts['host'] . ':' . $port;
    }

    private static function matchesNoProxy(string $host, ?string $noProxy): bool
    {
        if ($noProxy === null) {
            return false;
        }

        foreach (preg_split('/\s*,\s*/', trim($noProxy)) ?: [] as $entry) {
            if ($entry === '') {
                continue;
            }
            if ($entry === '*') {
                return true;
            }

            $entry = strtolower($entry);
            if ($entry[0] === '.') {
                $bare = substr($entry, 1);
                if ($host === $bare || str_ends_with($host, $entry)) {
                    return true;
                }
            } else {
                if ($host === $entry || str_ends_with($host, '.' . $entry)) {
                    return true;
                }
            }
        }

        return false;
    }
}
