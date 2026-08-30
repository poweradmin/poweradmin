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

namespace Poweradmin\Application\Http;

/**
 * Static helpers classifying the current request from server globals:
 * API route detection and JSON response negotiation.
 *
 * All detection keys off the real routed path, never requestData['page'],
 * so a spoofed ?page=api/... query cannot flip the result.
 */
final class RequestContext
{
    /**
     * Paths of the unauthenticated monitoring probes, as declared in routes.yaml.
     *
     * Shared so the session skip below and HeadlessRouteFilter cannot drift apart
     * from each other or from the routes themselves.
     */
    public const HEALTH_PROBE_PATHS = ['/api/health', '/ping'];

    private function __construct()
    {
    }

    /**
     * Path portion of the current request, with the query string stripped.
     */
    private static function path(): string
    {
        return parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    }

    /**
     * Checks if the current request is any API route
     *
     * @param bool $requireTrailingSlash Require a path segment after the API
     *                                   root (e.g. /api/v2/zones but not /api/v2);
     *                                   used where a bare root should fall
     *                                   through to web handling
     * @return bool True if this is an API request, false otherwise
     */
    public static function isApiRequest(bool $requireTrailingSlash = false): bool
    {
        $suffix = $requireTrailingSlash ? '/' : '(/|$)';
        return preg_match('#/api/(internal|v\d+)' . $suffix . '#', self::path()) === 1;
    }

    /**
     * Checks if the current request is an internal API route (api/internal/*)
     *
     * @return bool True if this is an internal API route, false otherwise
     */
    public static function isInternalApiRoute(): bool
    {
        return preg_match('#/api/internal(/|$)#', self::path()) === 1;
    }

    /**
     * Whether the current request targets the v2 public API. Matches on the URL path
     * only (query string ignored) so the v2 error-wrapper decision is not swayed by an
     * unrelated URL that merely mentions /api/v2/ in its query string.
     */
    public static function isV2ApiRequest(): bool
    {
        return preg_match('#/api/v2(/|$)#', self::path()) === 1;
    }

    /**
     * Whether the request targets one of the API families declared in routes.yaml.
     * Wider than isApiRequest(), which covers only internal and versioned routes,
     * but still matched per segment: a plain `/api/` substring test also caught the
     * web page /settings/api/logs and answered its permission denial as raw JSON.
     * Keyed off the path so /zones/1/edit?next=/api/v2/x is not mistaken for an API
     * call, and left unanchored so a base_url_prefix deployment still matches.
     */
    public static function isApiPath(): bool
    {
        return preg_match('#/api/(internal|v\d+|docs|health)(/|$)#', self::path()) === 1;
    }

    /**
     * Whether the request targets one of the monitoring probes declared in
     * routes.yaml, which are served without a session so a monitor scraping every
     * few seconds does not leave a session file behind per request.
     *
     * Matched exactly rather than by pattern: an unanchored regex would also catch
     * paths such as /zones/ping and silently deny them a session.
     */
    public static function isHealthProbeRequest(string $baseUrlPrefix = ''): bool
    {
        $prefix = rtrim($baseUrlPrefix, '/');
        $paths = array_map(static fn(string $path): string => $prefix . $path, self::HEALTH_PROBE_PATHS);

        return in_array(self::path(), $paths, true);
    }

    /**
     * Accept header of the current request.
     */
    private static function acceptHeader(): string
    {
        return $_SERVER['HTTP_ACCEPT'] ?? '';
    }

    /**
     * Whether the Accept header mentions JSON at all.
     */
    public static function acceptsJson(): bool
    {
        return str_contains(self::acceptHeader(), 'application/json');
    }

    /**
     * Whether the Accept header asks for JSON without also accepting HTML.
     */
    public static function acceptsJsonOnly(): bool
    {
        return self::acceptsJson() && !str_contains(self::acceptHeader(), 'text/html');
    }

    /**
     * Whether the request was made via XMLHttpRequest.
     */
    public static function isAjax(): bool
    {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }

    /**
     * Whether the request body is declared as JSON.
     */
    public static function hasJsonContentType(): bool
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
        return str_contains($contentType, 'application/json');
    }

    /**
     * Checks if the current request expects a JSON response
     * This is more comprehensive than just checking the route
     *
     * @return bool True if this request expects JSON, false otherwise
     */
    public static function expectsJson(): bool
    {
        return self::isApiPath()
            || self::acceptsJsonOnly()
            || self::isAjax()
            || self::hasJsonContentType();
    }

    /**
     * Negotiation used when shaping an unhandled throwable. Deliberately looser
     * than expectsJson(): no Content-Type signal, so a JSON body posted to a web
     * form still gets the HTML error page it can render, and no text/html
     * exclusion on the Accept check, so an API-ish client that also accepts HTML
     * never receives an HTML stack page.
     *
     * @return bool True if a fatal error should be shaped as JSON
     */
    public static function expectsJsonOnError(): bool
    {
        return self::isApiPath()
            || self::acceptsJson()
            || self::isAjax();
    }
}
