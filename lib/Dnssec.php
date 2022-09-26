<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2009  Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2022  Poweradmin Development Team
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

namespace Poweradmin;

/**
 * DNSSEC functions
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2022  Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class Dnssec
{
    /** Check if it's possible to execute pdnsutil command
     *
     * @return boolean true on success, false on failure
     */
    public static function dnssec_is_pdnssec_callable(): bool
    {
        global $pdnssec_command;

        if (!function_exists('exec')) {
            error(ERR_EXEC_NOT_ALLOWED);
            return false;
        }

        if (!file_exists($pdnssec_command) || !is_executable($pdnssec_command)) {
            error(ERR_EXEC_PDNSSEC);
            return false;
        }

        return true;
    }

    /** Execute dnssec utility
     *
     * @param string $command Command name
     * @param string $args Command arguments
     *
     * @return array Array with output from command execution and error code
     */
    public static function dnssec_call_pdnssec($command, $domain, $args = array()): array
    {
        global $pdnssec_command, $pdnssec_debug;
        $output = '';
        $return_code = -1;

        if (!self::dnssec_is_pdnssec_callable()) {
            return array($output, $return_code);
        }

        if (!is_array($args)) {
            return array('ERROR: internal error, input not Array ()', $return_code);
        } else {
            foreach ($args as $k => $v) {
                $args [$k] = escapeshellarg($v);
            }
            $args = join(' ', $args);
        }

        $full_command = join(' ', array(
                escapeshellcmd($pdnssec_command),
                $command,
                escapeshellarg($domain) . ' ' . $args,
                '2>&1')
        );

        exec($full_command, $output, $return_code);

        if ($pdnssec_debug) {
            echo "<div class=\"container\"><pre>";
            echo sprintf("Command: %s<br>", $full_command);
            echo sprintf("Output: %s", implode("<br>", $output));
            echo "</pre></div>";
        }

        return array($output, $return_code);
    }

    /** Execute pdnsutil rectify-zone command for Domain ID
     *
     * If a Domain is dnssec enabled, or uses features as
     * e.g. ALSO-NOTIFY, ALLOW-AXFR-FROM, TSIG-ALLOW-AXFR
     * following has to be executed
     * pdnsutil rectify-zone $domain
     *
     * @param int $domain_id Domain ID
     *
     * @return boolean true on success, false on failure or unnecessary
     */
    public static function dnssec_rectify_zone($domain_id): bool
    {
        global $db;
        global $pdnssec_command, $pdnssec_debug;

        $output = array();

        /* if pdnssec_command is set we perform ``pdnsutil rectify-zone $domain`` on all zones,
         * as pdns needs the "auth" column for all zones if dnssec is enabled
         *
         * If there is any entry at domainmetadata table for this domain,
         * it is an error if pdnssec_command is not set */
        $query = "SELECT COUNT(id) FROM domainmetadata WHERE domain_id = " . $db->quote($domain_id, 'integer');
        $count = $db->queryOne($query);

        if (isset($pdnssec_command)) {
            $domain = DnsRecord::get_domain_name_by_id($domain_id);
            $full_command = join(' ', array(
                escapeshellcmd($pdnssec_command),
                'rectify-zone',
                escapeshellarg($domain)
            ));

            if (!self::dnssec_is_pdnssec_callable()) {
                return false;
            }

            exec($full_command, $output, $return_code);

            if ($pdnssec_debug) {
                echo "<div class=\"container\"><pre>";
                echo sprintf("Command: %s<br>", $full_command);
                echo sprintf("Output: %s", implode("<br>", $output));
                echo "</pre></div>";
            }

            if ($return_code != 0) {
                error(ERR_EXEC_PDNSSEC_RECTIFY_ZONE);
                return false;
            }

            return true;
        } else if ($count >= 1) {
            error(ERR_EXEC_PDNSSEC);
            return false;
        }

        return false;
    }

    /** Execute pdnsutil secure-zone command for Domain Name
     *
     * @param string $domain_name Domain Name
     *
     * @return boolean true on success, false on failure or unnecessary
     */
    public static function dnssec_secure_zone($domain_name): bool
    {
        $call_result = self::dnssec_call_pdnssec('secure-zone', $domain_name);
        $return_code = $call_result[1];

        if ($return_code != 0) {
            error(ERR_EXEC_PDNSSEC_SECURE_ZONE);
            return false;
        }

        Logger::log_info(sprintf('client_ip:%s user:%s operation:dnssec_secure_zone zone:%s',
            $_SERVER['REMOTE_ADDR'], $_SESSION['userlogin'], $domain_name));

        return true;
    }

    /** Execute pdnsutil disable-dnssec command for Domain Name
     *
     * @param string $domain_name Domain Name
     *
     * @return boolean true on success, false on failure or unnecessary
     */
    public static function dnssec_unsecure_zone($domain_name): bool
    {
        $call_result = self::dnssec_call_pdnssec('disable-dnssec', $domain_name);
        $return_code = $call_result[1];

        if ($return_code != 0) {
            error(ERR_EXEC_PDNSSEC_DISABLE_ZONE);
            return false;
        }

        Logger::log_info(sprintf('client_ip:%s user:%s operation:dnssec_unsecure_zone zone:%s',
            $_SERVER['REMOTE_ADDR'], $_SESSION['userlogin'], $domain_name));

        return true;
    }

    /** Check if zone is secured
     *
     * @param string $domain_name Domain Name
     *
     * @return boolean true on success, false on failure
     */
    public static function dnssec_is_zone_secured($domain_name): bool
    {
        global $db;
        $query = $db->prepare("SELECT
                  COUNT(cryptokeys.id) AS active_keys,
                  COUNT(domainmetadata.id) > 0 AS presigned
                  FROM domains
                  LEFT JOIN cryptokeys ON domains.id = cryptokeys.domain_id
                  LEFT JOIN domainmetadata ON domains.id = domainmetadata.domain_id AND domainmetadata.kind = 'PRESIGNED'
                  WHERE domains.name = ?
                  GROUP BY domains.id
        ");
        $query->execute(array($domain_name));
        $row = $query->fetch();
        return $row['active_keys'] > 0 || $row['presigned'];
    }

    /** Return DS records
     *
     * @param string $domain_name Domain Name
     *
     * @return array|false
     */
    public static function dnssec_get_ds_records($domain_name)
    {
        $call_result = self::dnssec_call_pdnssec('show-zone', $domain_name);
        $output = $call_result[0];
        $return_code = $call_result[1];

        if ($return_code != 0) {
            error(ERR_EXEC_PDNSSEC_SHOW_ZONE);
            return false;
        }

        $ds_records = array();
        $id = 0;
        foreach ($output as $line) {
            if (substr($line, 0, 2) == 'DS') {
                $oldid = $id;
                $items = explode(' ', $line);

                $ds_line = join(" ", array_slice($items, 2));
                $id = $items[5];
                if ($oldid != $id and $oldid != 0) {
                    $ds_records[] = "<br/>" . $ds_line;
                } else {
                    $ds_records[] = $ds_line;
                }
            }
        }

        return $ds_records;
    }

    /** Return algorithm name for given number
     *
     * @param int $algo Algorithm id
     *
     * @return string algorithm name
     */
    public static function dnssec_algorithm_to_name($algo): string
    {
        $name = 'Unallocated/Reserved';

        switch ($algo) {
            case 0:
                $name = 'Reserved';
                break;
            case 1:
                $name = 'RSAMD5';
                break;
            case 2:
                $name = 'DH';
                break;
            case 3:
                $name = 'DSA';
                break;
            case 4:
                $name = 'ECC';
                break;
            case 5:
                $name = 'RSASHA1';
                break;
            case 6:
                $name = 'DSA-NSEC3-SHA1';
                break;
            case 7:
                $name = 'RSASHA1-NSEC3-SHA1';
                break;
            case 8:
                $name = 'RSASHA256';
                break;
            case 9:
                $name = 'Reserved';
                break;
            case 10:
                $name = 'RSASHA512';
                break;
            case 11:
                $name = 'Reserved';
                break;
            case 12:
                $name = 'ECC-GOST';
                break;
            case 13:
                $name = 'ECDSAP256SHA256';
                break;
            case 14:
                $name = 'ECDSAP384SHA384';
                break;
            case 15:
                $name = 'ED25519';
                break;
            case 16:
                $name = 'ED448';
                break;
            case 252:
                $name = 'INDIRECT';
                break;
            case 253:
                $name = 'PRIVATEDNS';
                break;
            case 254:
                $name = 'PRIVATEOID';
                break;
        }

        return $name;
    }

    /** Return algorithm name for given short name
     *
     * @param string $short_name Short algorithm name
     * @return string Algorithm name
     */
    public static function dnssec_shorthand_to_algorithm_name($short_name): string
    {
        $name = 'Unknown';

        switch ($short_name) {
            case "rsamd5":
                $name = self::dnssec_algorithm_to_name(1);
                break;
            case "dh":
                $name = self::dnssec_algorithm_to_name(2);
                break;
            case "dsa":
                $name = self::dnssec_algorithm_to_name(3);
                break;
            case "ecc":
                $name = self::dnssec_algorithm_to_name(4);
                break;
            case "rsasha1":
                $name = self::dnssec_algorithm_to_name(5);
                break;
            case "rsasha1-nsec3":
                $name = self::dnssec_algorithm_to_name(7);
                break;
            case "rsasha256":
                $name = self::dnssec_algorithm_to_name(8);
                break;
            case "rsasha512":
                $name = self::dnssec_algorithm_to_name(10);
                break;
            case "gost":
                $name = self::dnssec_algorithm_to_name(12);
                break;
            case "ecdsa256":
                $name = self::dnssec_algorithm_to_name(13);
                break;
            case "ecdsa384":
                $name = self::dnssec_algorithm_to_name(14);
                break;
            case "ed25519":
                $name = self::dnssec_algorithm_to_name(15);
                break;
            case "ed448":
                $name = self::dnssec_algorithm_to_name(16);
                break;
        }

        return $name;
    }

    /** Check if zone is secured
     *
     * @param string $domain_name Domain Name
     *
     * @return array|false string containing dns key
     */
    public static function dnssec_get_dnskey_record($domain_name)
    {
        $call_result = self::dnssec_call_pdnssec('show-zone', $domain_name);
        $output = $call_result[0];
        $return_code = $call_result[1];

        if ($return_code != 0) {
            error(ERR_EXEC_PDNSSEC_SHOW_ZONE);
            return false;
        }

        $dns_keys = array();
        foreach ($output as $line) {
            if (in_array(substr($line, 0, 3), ['CSK', 'KSK', 'ZSK', 'ID '], true)) {
                $items = explode(' ', $line);
                $dns_key = join(" ", array_slice($items, 3));
                $dns_keys[] = $dns_key;
            }
        }
        return $dns_keys;
    }

    /** Activate zone key
     *
     * @param string $domain_name Domain Name
     * @param $key_id
     *
     * @return bool true on success, false on failure
     */
    public static function dnssec_activate_zone_key($domain_name, $key_id): bool
    {
        $call_result = self::dnssec_call_pdnssec('activate-zone-key', $domain_name, array($key_id));
        $return_code = $call_result[1];

        if ($return_code != 0) {
            error(ERR_EXEC_PDNSSEC_SHOW_ZONE);
            return false;
        }

        Logger::log_info(sprintf('client_ip:%s user:%s operation:dnssec_activate_zone_key zone:%s key_id:%s',
            $_SERVER['REMOTE_ADDR'], $_SESSION['userlogin'], $domain_name, $key_id));

        return true;
    }

    /** Deactivate zone key
     *
     * @param string $domain_name Domain Name
     * @param $key_id
     *
     * @return bool true on success, false on failure
     */
    public static function dnssec_deactivate_zone_key($domain_name, $key_id): bool
    {
        $call_result = self::dnssec_call_pdnssec('deactivate-zone-key', $domain_name, array($key_id));
        $return_code = $call_result[1];

        if ($return_code != 0) {
            error(ERR_EXEC_PDNSSEC_SHOW_ZONE);
            return false;
        }

        Logger::log_info(sprintf('client_ip:%s user:%s operation:dnssec_deactivate_zone_key zone:%s key_id:%s',
            $_SERVER['REMOTE_ADDR'], $_SESSION['userlogin'], $domain_name, $key_id));

        return true;
    }

    /** Get list of existing DNSSEC keys
     *
     * @param string $domain_name Domain Name
     *
     * @return array|false
     */
    public static function dnssec_get_keys($domain_name)
    {
        $call_result = self::dnssec_call_pdnssec('show-zone', $domain_name);
        $output = $call_result[0];
        $return_code = $call_result[1];

        if ($return_code != 0) {
            error(ERR_EXEC_PDNSSEC_SHOW_ZONE);
            return false;
        }

        $keys = array();
        foreach ($output as $line) {
            if (substr($line, 0, 2) == 'ID') {
                $items[0] = explode(' ', (explode('ID = ', $line)[1]))[0];
                $items[1] = substr(explode(' ', (explode('ID = ', $line)[1]))[1], 1, -2);
                $items[2] = substr(explode(' ', (explode('flags = ', $line)[1]))[0], 0, -1);
                $items[3] = substr(explode(' ', (explode('tag = ', $line)[1]))[0], 0, -1);
                $items[4] = substr(explode(' ', (explode('algo = ', $line)[1]))[0], 0, -1);
                $items[5] = preg_replace('/[^0-9]/', '', explode(' ', (explode('bits = ', $line)[1]))[0]);
                if (strpos($line, 'Active') !== false) {
                    $items[6] = 1;
                } else {
                    $items[6] = 0;
                }
                $keys[] = array($items[0], $items[1], $items[3], $items[4], $items[5], $items[6]);
            }
        }

        return $keys;
    }

    /** Create new DNSSEC key
     *
     * @param string $domain_name Domain Name
     * @param string $key_type Key type
     * @param string $bits Bits in length
     * @param string $algorithm Algorithm
     *
     * @return boolean true on success, false on failure
     */
    public static function dnssec_add_zone_key($domain_name, $key_type, $bits, $algorithm): bool
    {
        $call_result = self::dnssec_call_pdnssec('add-zone-key', $domain_name, array($key_type, $bits, "inactive", $algorithm));
        $return_code = $call_result[1];

        if ($return_code != 0) {
            error(ERR_EXEC_PDNSSEC_ADD_ZONE_KEY);
            return false;
        }

        Logger::log_info(sprintf('client_ip:%s user:%s operation:dnssec_add_zone_key zone:%s type:%s bits:%s algorithm:%s',
            $_SERVER['REMOTE_ADDR'], $_SESSION['userlogin'], $domain_name, $key_type, $bits, $algorithm));

        return true;
    }

    /** Remove DNSSEC key
     *
     * @param string $domain_name Domain Name
     * @param int $key_id Key ID
     *
     * @return boolean true on success, false on failure
     */
    public static function dnssec_remove_zone_key($domain_name, $key_id): bool
    {
        $call_result = self::dnssec_call_pdnssec('remove-zone-key', $domain_name, array($key_id));
        $return_code = $call_result[1];

        if ($return_code != 0) {
            error(ERR_EXEC_PDNSSEC_ADD_ZONE_KEY);
            return false;
        }

        Logger::log_info(sprintf('client_ip:%s user:%s operation:dnssec_remove_zone_key zone:%s key_id:%s',
            $_SERVER['REMOTE_ADDR'], $_SESSION['userlogin'], $domain_name, $key_id));

        return true;
    }

    /** Check if given key exists
     *
     * @param string $domain_name Domain Name
     * @param int $key_id Key ID
     *
     * @return boolean true if exists, otherwise false
     */
    public static function dnssec_zone_key_exists($domain_name, $key_id): bool
    {
        $keys = self::dnssec_get_keys($domain_name);

        foreach ($keys as $key) {
            if ($key[0] == $key_id) {
                return true;
            }
        }

        return false;
    }

    /** Return requested key
     *
     * @param string $domain_name Domain Name
     * @param int $key_id Key ID
     *
     * @return array true if exists, otherwise false
     */
    public static function dnssec_get_zone_key($domain_name, $key_id): array
    {
        $keys = self::dnssec_get_keys($domain_name);

        foreach ($keys as $key) {
            if ($key[0] == $key_id) {
                return $key;
            }
        }

        return array();
    }
}
