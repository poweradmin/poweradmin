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

/**
 * Interface for DNS record validators
 */
interface DnsRecordValidatorInterface
{
    /**
     * Get the list of record types this validator supports
     *
     * @return array<string> Array of DNS record type names
     */
    public function getSupportedTypes(): array;

    /**
     * Validate a DNS record
     *
     * @param string $type The DNS record type
     * @param string $content The record content to validate
     * @param bool $answer Whether to present errors to the user
     * @return bool True if valid, false otherwise
     */
    public function validate(string $type, string $content, bool $answer = true): bool;
}
