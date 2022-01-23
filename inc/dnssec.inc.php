<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <http://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2009  Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2022  Poweradmin Development Team
 *      <http://www.poweradmin.org/credits.html>
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
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * DNSSEC functions
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2022  Poweradmin Development Team
 * @license     http://opensource.org/licenses/GPL-3.0 GPL
 */

/** Check if it's possible to execute pdnsutil command
 *
 * @return boolean true on success, false on failure
 */
function dnssec_is_pdnssec_callable() {
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
 * @return mixed[] Array with output from command execution and error code
 */
function dnssec_call_pdnssec($command, $args) {
    global $pdnssec_command, $pdnssec_debug;
    $output = '';
    $return_code = -1;

    if (!dnssec_is_pdnssec_callable()) {
        return array($output, $return_code);
    }

    $full_command = join(' ', array(
        escapeshellcmd($pdnssec_command),
        $command,
        escapeshellarg($args),
        '2>&1')
    );

    exec($full_command, $output, $return_code);

    if ($pdnssec_debug) {
        echo "<div class=\"debug\"><pre>";
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
function dnssec_rectify_zone($domain_id) {
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

    if (PEAR::isError($count)) {
        error($count->getMessage());
        return false;
    }

    if (isset($pdnssec_command)) {
        $domain = get_domain_name_by_id($domain_id);
        $full_command = join(' ',array(
            escapeshellcmd($pdnssec_command),
            'rectify-zone',
            escapeshellarg($domain)
        ));

        if (!dnssec_is_pdnssec_callable()) {
            return false;
        }

        exec($full_command, $output, $return_code);

        if ($pdnssec_debug) {
            echo "<div class=\"debug\"><pre>";
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
function dnssec_secure_zone($domain_name) {
    $call_result = dnssec_call_pdnssec('secure-zone', $domain_name);
    $return_code = $call_result[1];

    if ($return_code != 0) {
        error(ERR_EXEC_PDNSSEC_SECURE_ZONE);
        return false;
    }

    log_info(sprintf('client_ip:%s user:%s operation:dnssec_secure_zone zone:%s',
        $_SERVER['REMOTE_ADDR'], $_SESSION['userlogin'], $domain_name));

    return true;
}

/** Execute pdnsutil disable-dnssec command for Domain Name
 *
 * @param string $domain_name Domain Name
 *
 * @return boolean true on success, false on failure or unnecessary
 */
function dnssec_unsecure_zone($domain_name) {
    $call_result = dnssec_call_pdnssec('disable-dnssec', $domain_name);
    $return_code = $call_result[1];

    if ($return_code != 0) {
        error(ERR_EXEC_PDNSSEC_DISABLE_ZONE);
        return false;
    }

    log_info(sprintf('client_ip:%s user:%s operation:dnssec_unsecure_zone zone:%s',
        $_SERVER['REMOTE_ADDR'], $_SESSION['userlogin'], $domain_name));

    return true;
}

/** Check if zone is secured
 *
 * @param string $domain_name Domain Name
 *
 * @return boolean true on success, false on failure
 */
function dnssec_is_zone_secured($domain_name) {
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

/** Use presigned RRSIGs from storage
 *
 * @param string $domain_name Domain Name
 */
function dnssec_set_nsec3($domain_name) {
    dnssec_call_pdnssec('set-nsec3', $domain_name);
    log_info(sprintf('client_ip:%s user:%s operation:dnssec_set_nsec3 zone:%s',
        $_SERVER['REMOTE_ADDR'], $_SESSION['userlogin'], $domain_name));
}

/** Switch back to NSEC
 *
 * @param string $domain_name Domain Name
 */
function dnssec_unset_nsec3($domain_name) {
    dnssec_call_pdnssec('unset-nsec3', $domain_name);
    log_info(sprintf('client_ip:%s user:%s operation:dnssec_unset_nsec3 zone:%s',
        $_SERVER['REMOTE_ADDR'], $_SESSION['userlogin'], $domain_name));
}

/** Return nsec type
 *
 * @param string $domain_name Domain Name
 *
 * @return string nsec or nsec3
 */
function dnssec_get_nsec_type($domain_name) {
    $call_result = dnssec_call_pdnssec('show-zone', $domain_name);
    $output = $call_result[0];

    return ($output[0] == 'Zone has NSEC semantics' ? 'nsec' : 'nsec3');
}

/** Use presigned RRSIGs from storage
 *
 * @param string $domain_name Domain Name
 */
function dnssec_set_presigned($domain_name) {
    dnssec_call_pdnssec('set-presigned', $domain_name);
    log_info(sprintf('client_ip:%s user:%s operation:dnssec_set_presigned zone:%s',
        $_SERVER['REMOTE_ADDR'], $_SESSION['userlogin'], $domain_name));
}

/** No longer use presigned RRSIGs
 *
 * @param string $domain_name Domain Name
 */
function dnssec_unset_presigned($domain_name) {
    dnssec_call_pdnssec('unset-presigned', $domain_name);
    log_info(sprintf('client_ip:%s user:%s operation:unset-presigned zone:%s',
        $_SERVER['REMOTE_ADDR'], $_SESSION['userlogin'], $domain_name));
}

/** Return presigned status
 *
 * @param string $domain_name Domain Name
 *
 * @return boolean true if zone is presigned, otherwise false
 */
function dnssec_get_presigned_status($domain_name) {
    $call_result = dnssec_call_pdnssec('show-zone', $domain_name);
    $output = $call_result[0];

    return ($output[1] == 'Zone is presigned' ? true : false);
}

/** Rectify all zones.
 */
function dnssec_rectify_all_zones() {
    dnssec_call_pdnssec('rectify-all-zones', '');
    log_info(sprintf('client_ip:%s user:%s operation:dnssec_rectify_all_zones',
        $_SERVER['REMOTE_ADDR'], $_SESSION['userlogin']));
}

/** Return DS records
 *
 * @param string $domain_name Domain Name
 *
 * @return mixed[] DS records
 */
function dnssec_get_ds_records($domain_name) {
    $call_result = dnssec_call_pdnssec('show-zone', $domain_name);
    $output = $call_result[0];
    $return_code = $call_result[1];

    if ($return_code != 0) {
        error(ERR_EXEC_PDNSSEC_SHOW_ZONE);
        return false;
    }

    $ds_records = array();
    $oldid = $id = 0;
    foreach ($output as $line) {
        if (substr($line, 0, 2) == 'DS') {
	    $oldid = $id;
            $items = explode(' ', $line);

            $ds_line = join(" ", array_slice($items, 2));
	    $id = $items[5];
            if ($oldid != $id and $oldid !=0) {
		$ds_records[] = "<br/>".$ds_line;
	    }
	    else {
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
function dnssec_algorithm_to_name($algo) {
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
function dnssec_shorthand_to_algorithm_name($short_name) {
    $name = 'Unknown';

    switch ($short_name) {
        case "rsamd5":
            $name = dnssec_algorithm_to_name(1);
            break;
        case "dh":
            $name = dnssec_algorithm_to_name(2);
            break;
        case "dsa":
            $name = dnssec_algorithm_to_name(3);
            break;
        case "ecc":
            $name = dnssec_algorithm_to_name(4);
            break;
        case "rsasha1":
            $name = dnssec_algorithm_to_name(5);
            break;
        case "rsasha256":
            $name = dnssec_algorithm_to_name(8);
            break;
        case "rsasha512":
            $name = dnssec_algorithm_to_name(10);
            break;
        case "gost":
            $name = dnssec_algorithm_to_name(12);
            break;
        case "ecdsa256":
            $name = dnssec_algorithm_to_name(13);
            break;
        case "ecdsa384":
            $name = dnssec_algorithm_to_name(14);
            break;
        case "ed25519":
            $name = dnssec_algorithm_to_name(250);
            break;
    }

    return $name;
}

/** Get name of digest type
 *
 * @param int $type Digest type id
 *
 * @return string digest name
 */
function dnssec_get_digest_name($type) {
    $name = 'Unknown';

    switch ($type) {
        case 1:
            $name = 'SHA-1';
            break;
        case 2:
            $name = 'SHA-256 ';
            break;
    }

    return $name;
}

/** Check if zone is secured
 *
 * @param string $domain_name Domain Name
 *
 * @return string string containing dns key
 */
function dnssec_get_dnskey_record($domain_name) {
    $call_result = dnssec_call_pdnssec('show-zone', $domain_name);
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
function dnssec_activate_zone_key($domain_name, $key_id) {
    $call_result = dnssec_call_pdnssec('activate-zone-key', join(" ", array($domain_name, $key_id)));
    $return_code = $call_result[1];

    if ($return_code != 0) {
        error(ERR_EXEC_PDNSSEC_SHOW_ZONE);
        return false;
    }

    log_info(sprintf('client_ip:%s user:%s operation:dnssec_activate_zone_key zone:%s key_id:%s',
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
function dnssec_deactivate_zone_key($domain_name, $key_id) {
    $call_result = dnssec_call_pdnssec('deactivate-zone-key', join(" ", array($domain_name, $key_id)));
    $return_code = $call_result[1];

    if ($return_code != 0) {
        error(ERR_EXEC_PDNSSEC_SHOW_ZONE);
        return false;
    }

    log_info(sprintf('client_ip:%s user:%s operation:dnssec_deactivate_zone_key zone:%s key_id:%s',
        $_SERVER['REMOTE_ADDR'], $_SESSION['userlogin'], $domain_name, $key_id));

    return true;
}

/** Get list of existing DNSSEC keys
 *
 * @param string $domain_name Domain Name
 *
 * @return mixed[] array with DNSSEC keys
 */
function dnssec_get_keys($domain_name) {
    $call_result = dnssec_call_pdnssec('show-zone', $domain_name);
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
function dnssec_add_zone_key($domain_name, $key_type, $bits, $algorithm) {
    $call_result = dnssec_call_pdnssec('add-zone-key', join(" ", array($domain_name, $key_type, $bits, "inactive", $algorithm)));
    $return_code = $call_result[1];

    if ($return_code != 0) {
        error(ERR_EXEC_PDNSSEC_ADD_ZONE_KEY);
        return false;
    }

    log_info(sprintf('client_ip:%s user:%s operation:dnssec_add_zone_key zone:%s type:%s bits:%s algorithm:%s',
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
function dnssec_remove_zone_key($domain_name, $key_id) {
    $call_result = dnssec_call_pdnssec('remove-zone-key', join(" ", array($domain_name, $key_id)));
    $return_code = $call_result[1];

    if ($return_code != 0) {
        error(ERR_EXEC_PDNSSEC_ADD_ZONE_KEY);
        return false;
    }

    log_info(sprintf('client_ip:%s user:%s operation:dnssec_remove_zone_key zone:%s key_id:%s',
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
function dnssec_zone_key_exists($domain_name, $key_id) {
    $keys = dnssec_get_keys($domain_name);

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
 * @return mixed[] true if exists, otherwise false
 */
function dnssec_get_zone_key($domain_name, $key_id) {
    $keys = dnssec_get_keys($domain_name);

    foreach ($keys as $key) {
        if ($key[0] == $key_id) {
            return $key;
        }
    }

    return array();
}
