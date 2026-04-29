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
     * @return array{proxy?: string, request_fulluri?: bool, header?: string}
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

        $options = [
            'proxy' => $proxy,
            'request_fulluri' => true,
        ];

        // PHP's stream wrapper proxy URL must be plain `tcp://host:port`. Any
        // credentials embedded in HTTPS_PROXY/http_proxy have to be sent via a
        // `Proxy-Authorization` header instead.
        $auth = self::extractProxyAuth($proxyRaw);
        if ($auth !== null) {
            $options['header'] = 'Proxy-Authorization: Basic ' . base64_encode($auth);
        }

        return $options;
    }

    /**
     * Build a Guzzle-shaped proxy config from the environment, suitable for
     * passing as the `proxy` option to a Guzzle client (or any consumer that
     * accepts Guzzle's request options, e.g. `league/oauth2-client`).
     *
     * Returns null when no proxy is configured.
     *
     * @return array{http?: string, https?: string, no?: list<string>}|null
     */
    public static function guzzleProxyConfig(): ?array
    {
        $config = [];

        $http = self::env(null, 'http_proxy');
        if ($http !== null) {
            $normalized = self::toGuzzleProxy($http);
            if ($normalized !== null) {
                $config['http'] = $normalized;
            }
        }

        $https = self::env('HTTPS_PROXY', 'https_proxy');
        if ($https !== null) {
            $normalized = self::toGuzzleProxy($https);
            if ($normalized !== null) {
                $config['https'] = $normalized;
            }
        }

        $noProxy = self::env('NO_PROXY', 'no_proxy');
        if ($noProxy !== null) {
            $entries = preg_split('/\s*,\s*/', trim($noProxy)) ?: [];
            $entries = array_values(array_filter($entries, static fn(string $entry): bool => $entry !== ''));
            if ($entries !== []) {
                $config['no'] = $entries;
            }
        }

        return $config === [] ? null : $config;
    }

    /**
     * Merge proxy options into an existing stream-context options array under
     * the `http` sub-key. Returns the array unchanged when no proxy applies.
     * The Proxy-Authorization header is appended to any existing `header`
     * value rather than overwriting it, so call-site headers (X-API-Key,
     * Authorization, etc.) are preserved.
     */
    public static function applyTo(array $options, string $url): array
    {
        $proxyOpts = self::httpOptionsFor($url);
        if ($proxyOpts === []) {
            return $options;
        }

        $http = isset($options['http']) && is_array($options['http']) ? $options['http'] : [];

        $proxyHeader = $proxyOpts['header'] ?? null;
        unset($proxyOpts['header']);

        $http = array_merge($http, $proxyOpts);

        if ($proxyHeader !== null) {
            $http['header'] = self::mergeHeader($http['header'] ?? null, $proxyHeader);
        }

        $options['http'] = $http;
        return $options;
    }

    /**
     * @param string|array<string>|null $existing
     * @return string|array<string>
     */
    private static function mergeHeader(string|array|null $existing, string $additional): string|array
    {
        if ($existing === null || $existing === '') {
            return $additional;
        }
        if (is_array($existing)) {
            $existing[] = $additional;
            return $existing;
        }
        return rtrim($existing, "\r\n") . "\r\n" . $additional;
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
        $parts = self::parseProxy($raw);
        if ($parts === null) {
            return null;
        }
        return 'tcp://' . $parts['host'] . ':' . $parts['port'];
    }

    /**
     * Build a proxy URL suitable for Guzzle (and curl underneath), preserving
     * any embedded credentials. curl's CURLOPT_PROXY only recognizes
     * http/https/socks* schemes - tcp:// is silently ignored - so we always
     * emit http:// unless the original explicitly used https://.
     */
    private static function toGuzzleProxy(string $raw): ?string
    {
        $parts = self::parseProxy($raw);
        if ($parts === null) {
            return null;
        }

        $userInfo = '';
        if ($parts['user'] !== null) {
            $userInfo = rawurlencode($parts['user']);
            if ($parts['pass'] !== null) {
                $userInfo .= ':' . rawurlencode($parts['pass']);
            }
            $userInfo .= '@';
        }

        $scheme = $parts['scheme'] === 'https' ? 'https' : 'http';
        return $scheme . '://' . $userInfo . $parts['host'] . ':' . $parts['port'];
    }

    /**
     * Decode credentials from a proxy URL into a `user:pass` string, or null
     * when the URL has no auth component.
     */
    private static function extractProxyAuth(string $raw): ?string
    {
        $parts = self::parseProxy($raw);
        if ($parts === null || $parts['user'] === null) {
            return null;
        }
        $auth = $parts['user'];
        if ($parts['pass'] !== null) {
            $auth .= ':' . $parts['pass'];
        }
        return $auth;
    }

    /**
     * @return array{scheme: string, host: string, port: int, user: ?string, pass: ?string}|null
     */
    private static function parseProxy(string $raw): ?array
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

        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : 'tcp';
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        return [
            'scheme' => $scheme,
            'host' => $parts['host'],
            'port' => (int) $port,
            'user' => isset($parts['user']) ? rawurldecode($parts['user']) : null,
            'pass' => isset($parts['pass']) ? rawurldecode($parts['pass']) : null,
        ];
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
