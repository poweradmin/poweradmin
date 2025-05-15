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

namespace Poweradmin\Domain\Error;

use RuntimeException;
use Throwable;

class ApiErrorException extends RuntimeException
{
    /**
     * Additional details about the API error
     *
     * @var array
     */
    private array $details;

    /**
     * @param string $message The error message
     * @param int $code The error code
     * @param Throwable|null $previous The previous throwable
     * @param array $details Additional error details
     */
    public function __construct(string $message = "", int $code = 0, ?Throwable $previous = null, array $details = [])
    {
        parent::__construct($message, $code, $previous);
        $this->details = $details;
    }

    /**
     * Get additional error details
     *
     * @return array
     */
    public function getDetails(): array
    {
        return $this->details;
    }

    /**
     * Get a specific detail by key
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getDetail(string $key, $default = null)
    {
        return $this->details[$key] ?? $default;
    }

    /**
     * Check if a specific detail exists
     *
     * @param string $key
     * @return bool
     */
    public function hasDetail(string $key): bool
    {
        return isset($this->details[$key]);
    }
}
