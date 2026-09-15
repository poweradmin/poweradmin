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
 * Snapshot of the $_GET, $_POST and $_SERVER superglobals with typed accessors for controllers.
 */
class Request
{
    protected array $queryParams;
    protected array $postParams;
    protected array $serverParams;

    public function __construct()
    {
        $this->refresh();
    }

    /**
     * Refreshes the request data from the global variables
     */
    public function refresh(): void
    {
        $this->queryParams = $_GET;
        $this->postParams = $_POST;
        $this->serverParams = $_SERVER;
    }

    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    public function getPostParams(): array
    {
        return $this->postParams;
    }

    public function getQueryParam(string $key, $default = null)
    {
        return $this->queryParams[$key] ?? $default;
    }

    public function getPostParam(string $key, $default = null)
    {
        return $this->postParams[$key] ?? $default;
    }

    /**
     * 1-based page number from the query string, never below 1.
     */
    public function getPage(string $key = 'start'): int
    {
        return max(1, (int)filter_var($this->queryParams[$key] ?? 1, FILTER_SANITIZE_NUMBER_INT));
    }

    /**
     * Requested page size from the query string, or null when absent or not a positive integer.
     */
    public function getRowsPerPage(string $key = 'rows_per_page'): ?int
    {
        $value = filter_var($this->queryParams[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $value === false ? null : $value;
    }

    /**
     * Returns a parameter from the POST body on POST requests, from the
     * query string otherwise. For endpoints that accept both methods.
     */
    public function getParam(string $key, $default = null)
    {
        if ($this->getMethod() === 'POST') {
            return $this->postParams[$key] ?? $default;
        }

        return $this->queryParams[$key] ?? $default;
    }

    public function getServerParam(string $key, $default = null)
    {
        return $this->serverParams[$key] ?? $default;
    }

    public function getMethod(): string
    {
        return $this->serverParams['REQUEST_METHOD'] ?? 'GET';
    }

    public function getUri(): string
    {
        return $this->serverParams['REQUEST_URI'] ?? '/';
    }
}
