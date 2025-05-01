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

use Poweradmin\Infrastructure\Service\MessageService;

/**
 * DNS TTL validation service
 *
 * @package Poweradmin
 * @copyright 2010-2025 Poweradmin Development Team
 * @license https://opensource.org/licenses/GPL-3.0 GPL
 */
class TTLValidator
{
    private MessageService $messageService;

    public function __construct()
    {
        $this->messageService = new MessageService();
    }

    /**
     * Check if TTL is valid and within range
     *
     * @param mixed $ttl TTL value to validate
     * @param mixed $defaultTtl Default TTL to use if ttl is not provided
     *
     * @return int|bool Validated TTL value if valid, false otherwise
     */
    public function isValidTTL(mixed $ttl, mixed $defaultTtl): int|bool
    {
        if (!isset($ttl) || $ttl === "") {
            return $defaultTtl;
        }

        if (!is_numeric($ttl) || $ttl < 0 || $ttl > 2147483647) {
            $this->messageService->addSystemError(_('Invalid value for TTL field. It should be numeric.'));
            return false;
        }

        return (int)$ttl;
    }
}
