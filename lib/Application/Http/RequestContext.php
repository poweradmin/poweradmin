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
     *                                   root (e.g. /api/v1/zones but not /api/v1);
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
     * Whether the request path mentions an /api/ segment. Looser than
     * isApiRequest(): any /api/ occurrence counts, matching the historical
     * JSON-negotiation behavior. Keyed off the path so a web URL such as
     * /zones/1/edit?next=/api/v1/x is not mistaken for an API call.
     */
    public static function isApiPath(): bool
    {
        return str_contains(self::path(), '/api/');
    }

    /**
     * Whether the Accept header mentions JSON at all.
     */
    public static function acceptsJson(): bool
    {
        return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    }

    /**
     * Whether the Accept header asks for JSON without also accepting HTML.
     */
    public static function acceptsJsonOnly(): bool
    {
        return self::acceptsJson() && !str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'text/html');
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
}
