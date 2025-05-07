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

namespace Poweradmin\Domain\Service;

use Poweradmin\Domain\Model\RecordType;
use Poweradmin\Domain\Service\DnsValidation\IPAddressValidator;

/**
 * Helper functions for dynamic DNS updates
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class DynamicDnsHelper
{
    /**
     * Make sql query safe
     *
     * @param $db
     * @param $db_type
     * @param mixed $value Unsafe Value
     *
     * @return string $value Safe Value
     */
    public static function safe($db, $db_type, mixed $value): string
    {
        if ($db_type == 'mysql' || $db_type == 'sqlite' || $db_type == 'pgsql') {
            $value = $db->quote($value, 'text');
            $value = substr($value, 1, -1); // remove quotes
        } else {
            return self::statusExit('baddbtype');
        }

        return $value;
    }

    /**
     * Get exit status message
     *
     * Print verbose status message for request
     *
     * @param string $status Short status message
     *
     * @return boolean false
     */
    public static function statusExit(string $status): bool
    {
        $verbose_codes = array(
            'badagent' => 'Your user agent is not valid.',
            'badauth' => 'No username available.',
            'badauth2' => 'Invalid username or password.  Authentication failed.',
            'notfqdn' => 'The hostname you specified was not valid.',
            'dnserr' => 'A DNS error has occurred on our end.  We apologize for any inconvenience.',
            '!yours' => 'The specified hostname does not belong to you.',
            'nohost' => 'The specified hostname does not exist.',
            'good' => 'Your hostname has been updated.',
            '911' => 'A critical error has occurred on our end.  We apologize for any inconvenience.',
            'nochg' => 'This update was identical to your last update, so no changes were made to your hostname configuration.',
            'baddbtype' => 'Unsupported database type',
        );

        if (isset($_REQUEST['verbose'])) {
            $pieces = preg_split('/\s/', $status);
            $status = $verbose_codes[$pieces[0]];
        }
        echo "$status\n";
        return false;
    }

    /**
     * Check whether the given address is an IP address
     *
     * @param string $ip Given IP address
     *
     * @return int|string A if IPv4, AAAA if IPv6 or 0 if invalid
     */
    public static function validIpAddress(string $ip): int|string
    {
        static $ipValidator = null;
        if ($ipValidator === null) {
            $ipValidator = new IPAddressValidator();
        }

        if ($ipValidator->isValidIPv4($ip)) {
            $value = RecordType::A;
        } elseif ($ipValidator->isValidIPv6($ip)) {
            $value = RecordType::AAAA;
        } else {
            $value = 0;
        }
        return $value;
    }

    /**
     * Parse, trim and validate a comma-separated list of IPs by type.
     *
     * @param string $raw_ip_input Comma-separated IP string
     * @param string $type 'A' or 'AAAA'
     *
     * @return array Filtered list of valid IPs
     */
    public static function extractValidIps(string $raw_ip_input, string $type): array
    {
        $ip_array = array_map('trim', explode(',', $raw_ip_input));
        return array_filter($ip_array, function ($ip) use ($type) {
            return self::validIpAddress($ip) === $type;
        });
    }

    /**
     * Synchronize A or AAAA DNS records with a new set of IP addresses
     *
     * @param object $db PDO database connection
     * @param string $records_table Name of the records table
     * @param int $domain_id ID of the domain/zone
     * @param string $hostname Fully-qualified domain name to update
     * @param string $type Record type ('A' or 'AAAA')
     * @param array $new_ips List of new IP addresses to apply
     *
     * @return bool True if any changes were made, false otherwise
     */
    public static function syncDnsRecords($db, $records_table, int $domain_id, string $hostname, string $type, array $new_ips): bool
    {
        $zone_updated = false;

        $existing = [];
        $query = $db->prepare("SELECT id, content FROM $records_table WHERE domain_id = :domain_id AND name = :hostname AND type = :type");
        $query->execute([':domain_id' => $domain_id, ':hostname' => $hostname, ':type' => $type]);
        while ($row = $query->fetch()) {
            $existing[$row['content']] = $row['id'];
        }

        foreach ($new_ips as $ip) {
            if (isset($existing[$ip])) {
                unset($existing[$ip]);
            } else {
                $insert = $db->prepare("INSERT INTO $records_table (domain_id, name, type, content, ttl, prio, change_date)
                    VALUES (:domain_id, :hostname, :type, :ip, 60, NULL, UNIX_TIMESTAMP())");
                $insert->execute([
                    ':domain_id' => $domain_id,
                    ':hostname' => $hostname,
                    ':type' => $type,
                    ':ip' => $ip
                ]);
                $zone_updated = true;
            }
        }

        foreach ($existing as $ip => $record_id) {
            $delete = $db->prepare("DELETE FROM $records_table WHERE id = :id");
            $delete->execute([':id' => $record_id]);
            $zone_updated = true;
        }

        return $zone_updated;
    }
}
