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

namespace Poweradmin\Domain\Service\DnsValidation;

use Poweradmin\Domain\Service\Validation\ValidationResult;

/**
 * DNS TTL validation service
 *
 * @package Poweradmin
 * @copyright 2010-2025 Poweradmin Development Team
 * @license https://opensource.org/licenses/GPL-3.0 GPL
 */
class TTLValidator
{
    /**
     * Validate TTL value
     *
     * @param mixed $ttl TTL value to validate
     * @param mixed $defaultTtl Default TTL to use if ttl is not provided
     *
     * @return ValidationResult<array> Validation result with TTL value or error
     */
    public function validate(mixed $ttl, mixed $defaultTtl): ValidationResult
    {
        if (!isset($ttl) || $ttl === "") {
            return ValidationResult::success(['ttl' => (int)$defaultTtl]);
        }

        if (!is_numeric($ttl) || $ttl < 0 || $ttl > 2147483647) {
            return ValidationResult::failure(_('Invalid value for TTL field. It should be numeric.'));
        }

        return ValidationResult::success(['ttl' => (int)$ttl]);
    }
}
