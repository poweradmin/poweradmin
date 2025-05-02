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

namespace Poweradmin\Domain\Service\Dns;

/**
 * Interface for SOA record management operations
 */
interface SOARecordManagerInterface
{
    /**
     * Get SOA record content for Zone ID
     *
     * @param int $zone_id Zone ID
     *
     * @return string SOA content
     */
    public function getSOARecord(int $zone_id): string;

    /**
     * Get SOA Serial Number
     *
     * @param string $soa_rec SOA record content
     *
     * @return string|null SOA serial
     */
    public static function getSOASerial(string $soa_rec): ?string;

    /**
     * Set SOA serial in SOA content
     *
     * @param string $soa_rec SOA record content
     * @param string $serial New serial number
     *
     * @return string Updated SOA record
     */
    public static function setSOASerial(string $soa_rec, string $serial): string;

    /**
     * Return SOA record with incremented serial number
     *
     * @param string $soa_rec Current SOA record
     *
     * @return string Updated SOA record
     */
    public function getUpdatedSOARecord(string $soa_rec): string;

    /**
     * Update SOA record
     *
     * @param int $domain_id Domain ID
     * @param string $content SOA content to set
     *
     * @return boolean true if success
     */
    public function updateSOARecord(int $domain_id, string $content): bool;

    /**
     * Update SOA serial
     *
     * Increments SOA serial to next possible number
     *
     * @param int $domain_id Domain ID
     *
     * @return boolean true if success
     */
    public function updateSOASerial(int $domain_id): bool;

    /**
     * Get next serial number
     *
     * @param int|string $curr_serial Current Serial No
     *
     * @return string|int Next serial number
     */
    public function getNextSerial(int|string $curr_serial): int|string;

    /**
     * Get Next Date
     *
     * @param string $curr_date Current date in YYYYMMDD format
     *
     * @return string Date +1 day
     */
    public static function getNextDate(string $curr_date): string;

    /**
     * Set timezone to configured tz or UTC it not set
     */
    public function setTimezone(): void;
}
