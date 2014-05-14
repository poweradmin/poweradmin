<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <http://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2009  Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2014  Poweradmin Development Team
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
 * DNS functions
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2014 Poweradmin Development Team
 * @license     http://opensource.org/licenses/GPL-3.0 GPL
 */

/** Validate DNS record input
 *
 * @param int $rid Record ID
 * @param int $zid Zone ID
 * @param string $type Record Type
 * @param mixed $content content part of record
 * @param mixed $name Name part of record
 * @param mixed $prio Priority
 * @param mixed $ttl TTL
 *
 * @return boolean true on success, false otherwise
 */
function validate_input($rid, $zid, $type, &$content, &$name, &$prio, &$ttl) {

    $zone = get_zone_name_from_id($zid);    // TODO check for return

    if (!(preg_match("/$zone$/i", $name))) {
        if (isset($name) && $name != "") {
            $name = $name . "." . $zone;
        } else {
            $name = $zone;
        }
    }

    switch ($type) {

        case "A":
            if (!is_valid_ipv4($content)) {
                return false;
            }
            if (!is_valid_rr_cname_exists($name, $rid)) {
                return false;
            }
            if (!is_valid_hostname_fqdn($name, 1)) {
                return false;
            }
            break;

        case "AAAA":
            if (!is_valid_ipv6($content)) {
                return false;
            }
            if (!is_valid_rr_cname_exists($name, $rid)) {
                return false;
            }
            if (!is_valid_hostname_fqdn($name, 1)) {
                return false;
            }
            break;

        case "AFSDB": // TODO: implement validation.
            break;

        case "CERT": // TODO: implement validation.
            break;

        case "CNAME":
            if (!is_valid_rr_cname_name($name)) {
                return false;
            }
            if (!is_valid_rr_cname_unique($name, $rid)) {
                return false;
            }
            if (!is_valid_hostname_fqdn($name, 1)) {
                return false;
            }
            if (!is_valid_hostname_fqdn($content, 0)) {
                return false;
            }
            if (!is_not_empty_cname_rr($name, $zone)) {
                return false;
            }
            break;

        case 'DHCID': // TODO: implement validation
            break;

        case 'DLV': // TODO: implement validation
            break;

        case 'DNSKEY': // TODO: implement validation
            break;

        case 'DS': // TODO: implement validation
            break;

        case 'EUI48': // TODO: implement validation
            break;

        case 'EUI64': // TODO: implement validation
            break;

        case "HINFO":
            if (!is_valid_rr_hinfo_content($content)) {
                return false;
            }
            if (!is_valid_hostname_fqdn($name, 1)) {
                return false;
            }
            break;

        case 'IPSECKEY': // TODO: implement validation
            break;

        case 'KEY': // TODO: implement validation
            break;

        case 'KX': // TODO: implement validation
            break;

        case "LOC":
            if (!is_valid_loc($content)) {
                return false;
            }
            if (!is_valid_hostname_fqdn($name, 1)) {
                return false;
            }
            break;

        case 'MINFO': // TODO: implement validation
            break;

        case 'MR': // TODO: implement validation
            break;

        case "MX":
            if (!is_valid_hostname_fqdn($content, 0)) {
                return false;
            }
            if (!is_valid_hostname_fqdn($name, 1)) {
                return false;
            }
            if (!is_valid_non_alias_target($content)) {
                return false;
            }
            break;

        case 'NAPTR': // TODO: implement validation
            break;

        case "NS":
            if (!is_valid_hostname_fqdn($content, 0)) {
                return false;
            }
            if (!is_valid_hostname_fqdn($name, 1)) {
                return false;
            }
            if (!is_valid_non_alias_target($content)) {
                return false;
            }
            break;

        case 'NSEC': // TODO: implement validation
            break;

        case 'NSEC3': // TODO: implement validation
            break;

        case 'NSEC3PARAM': // TODO: implement validation
            break;

        case 'OPT': // TODO: implement validation
            break;

        case "PTR":
            if (!is_valid_hostname_fqdn($content, 0)) {
                return false;
            }
            if (!is_valid_hostname_fqdn($name, 1)) {
                return false;
            }
            break;

        case 'RKEY': // TODO: implement validation
            break;

        case 'RP': // TODO: implement validation
            break;

        case 'RRSIG': // TODO: implement validation
            break;

        case "SOA":
            if (!is_valid_rr_soa_name($name, $zone)) {
                return false;
            }
            if (!is_valid_hostname_fqdn($name, 1)) {
                return false;
            }
            if (!is_valid_rr_soa_content($content)) {
                error(ERR_DNS_CONTENT);
                return false;
            }
            break;

        case "SPF":
            if (!is_valid_spf($content)) {
                return false;
            }
            break;

        case "SRV":
            if (!is_valid_rr_srv_name($name)) {
                return false;
            }
            if (!is_valid_rr_srv_content($content)) {
                return false;
            }
            break;

        case 'SSHFP': // TODO: implement validation
            break;

        case 'TLSA': // TODO: implement validation
            break;

        case 'TSIG': // TODO: implement validation
            break;

        case "TXT":
            if (!is_valid_printable($name)) {
                return false;
            }
            if (!is_valid_printable($content)) {
                return false;
            }
            break;

        case 'WKS': // TODO: implement validation
            break;

        case "CURL":
        case "MBOXFW":
        case "URL":
            // TODO: implement validation?
            // Fancy types are not supported anymore in PowerDNS
            break;

        default:
            error(ERR_DNS_RR_TYPE);
            return false;
    }

    if (!is_valid_rr_prio($prio, $type)) {
        return false;
    }
    if (!is_valid_rr_ttl($ttl)) {
        return false;
    }

    return true;
}

/** Test if hostname is valid FQDN
 *
 * @param mixed $hostname Hostname string
 * @param string $wildcard Hostname includes wildcard '*'
 *
 * @return boolean true if valid, false otherwise
 */
function is_valid_hostname_fqdn(&$hostname, $wildcard) {
    global $dns_top_level_tld_check;
    global $dns_strict_tld_check;
    global $valid_tlds;

    $hostname = preg_replace("/\.$/", "", $hostname);

    # The full domain name may not exceed a total length of 253 characters.
    if (strlen($hostname) > 253) {
        error(ERR_DNS_HN_TOO_LONG);
        return false;
    }

    $hostname_labels = explode('.', $hostname);
    $label_count = count($hostname_labels);

    if ($dns_top_level_tld_check && $label_count == 1) {
        return false;
    }

    foreach ($hostname_labels as $hostname_label) {
        if ($wildcard == 1 && !isset($first)) {
            if (!preg_match('/^(\*|[\w-\/]+)$/', $hostname_label)) {
                error(ERR_DNS_HN_INV_CHARS);
                return false;
            }
            $first = 1;
        } else {
            if (!preg_match('/^[\w-\/]+$/', $hostname_label)) {
                error(ERR_DNS_HN_INV_CHARS);
                return false;
            }
        }
        if (substr($hostname_label, 0, 1) == "-") {
            error(ERR_DNS_HN_DASH);
            return false;
        }
        if (substr($hostname_label, -1, 1) == "-") {
            error(ERR_DNS_HN_DASH);
            return false;
        }
        if (strlen($hostname_label) < 1 || strlen($hostname_label) > 63) {
            error(ERR_DNS_HN_LENGTH);
            return false;
        }
    }

    if ($hostname_labels[$label_count - 1] == "arpa" && (substr_count($hostname_labels[0], "/") == 1 XOR substr_count($hostname_labels[1], "/") == 1)) {
        if (substr_count($hostname_labels[0], "/") == 1) {
            $array = explode("/", $hostname_labels[0]);
        } else {
            $array = explode("/", $hostname_labels[1]);
        }
        if (count($array) != 2) {
            error(ERR_DNS_HOSTNAME);
            return false;
        }
        if (!is_numeric($array[0]) || $array[0] < 0 || $array[0] > 255) {
            error(ERR_DNS_HOSTNAME);
            return false;
        }
        if (!is_numeric($array[1]) || $array[1] < 25 || $array[1] > 31) {
            error(ERR_DNS_HOSTNAME);
            return false;
        }
    } else {
        if (substr_count($hostname, "/") > 0) {
            error(ERR_DNS_HN_SLASH);
            return false;
        }
    }

    if ($dns_strict_tld_check && !in_array(strtolower($hostname_labels[$label_count - 1]), $valid_tlds)) {
        error(ERR_DNS_INV_TLD);
        return false;
    }

    return true;
}

/** Test if IPv4 address is valid
 *
 * @param string $ipv4 IPv4 address string
 * @param boolean $answer print error if true
 * [default=true]
 *
 * @return boolean true if valid, false otherwise
 */
function is_valid_ipv4($ipv4, $answer = true) {

// 20080424/RZ: The current code may be replaced by the following if()
// statement, but it will raise the required PHP version to ">= 5.2.0".
// Not sure if we want that now.
//
//	if(filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === FALSE) {
//		error(ERR_DNS_IPV4); return false;
//	}

    if (!preg_match("/^[0-9\.]{7,15}$/", $ipv4)) {
        if ($answer) {
            error(ERR_DNS_IPV4);
        }
        return false;
    }

    $quads = explode('.', $ipv4);
    $numquads = count($quads);

    if ($numquads != 4) {
        if ($answer) {
            error(ERR_DNS_IPV4);
        }
        return false;
    }

    for ($i = 0; $i < 4; $i++) {
        if ($quads[$i] > 255) {
            if ($answer) {
                error(ERR_DNS_IPV4);
            }
            return false;
        }
    }

    return true;
}

/** Test if IPv6 address is valid
 *
 * @param string $ipv6 IPv6 address string
 * @param boolean $answer print error if true
 * [default=true]
 *
 * @return boolean true if valid, false otherwise
 */
function is_valid_ipv6($ipv6, $answer = true) {

// 20080424/RZ: The current code may be replaced by the following if()
// statement, but it will raise the required PHP version to ">= 5.2.0".
// Not sure if we want that now.
//
//	if(filter_var($ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === FALSE) {
//		error(ERR_DNS_IPV6); return false;
//	}

    if (!preg_match("/^[0-9a-f]{0,4}:([0-9a-f]{0,4}:){0,6}[0-9a-f]{0,4}$/i", $ipv6)) {
        if ($answer) {
            error(ERR_DNS_IPV6);
        }
        return false;
    }

    $quads = explode(':', $ipv6);
    $numquads = count($quads);

    if ($numquads > 8 || $numquads < 3) {
        if ($answer) {
            error(ERR_DNS_IPV6);
        }
        return false;
    }

    $emptyquads = 0;
    for ($i = 1; $i < $numquads - 1; $i++) {
        if ($quads[$i] == "")
            $emptyquads++;
    }

    if ($emptyquads > 1) {
        if ($answer) {
            error(ERR_DNS_IPV6);
        }
        return false;
    }

    if ($emptyquads == 0 && $numquads != 8) {
        if ($answer) {
            error(ERR_DNS_IPV6);
        }
        return false;
    }

    return true;
}

/** Test if multiple IP addresses are valid
 *
 *  Takes a string of comma seperated IP addresses and tests validity
 *
 *  @param string $ips Comma seperated IP addresses
 *
 *  @return boolean true if valid, false otherwise
 */
function are_multipe_valid_ips($ips) {

// multiple master NS-records are permitted and must be separated by ,
// eg. "192.0.0.1, 192.0.0.2, 2001:1::1"

    $are_valid = false;
    $multiple_ips = explode(",", $ips);
    if (is_array($multiple_ips)) {
        foreach ($multiple_ips as $m_ip) {
            $trimmed_ip = trim($m_ip);
            if (is_valid_ipv4($trimmed_ip, false) || is_valid_ipv6($trimmed_ip, true)) {
                $are_valid = true;
            } else {
                // as soon there is an invalid ip-addr
                // exit and return false
                echo "hin:=$trimmed_ip=";
                return false;
            }
        }
    } elseif (is_valid_ipv4($ips) || is_valid_ipv6($ips)) {
        $are_valid = true;
    }
    if ($are_valid) {
        return true;
    } else {
        return false;
    }
}

/** Test if string is printable
 *
 * @param string $string string
 *
 * @return boolean true if valid, false otherwise
 */
function is_valid_printable($string) {
    if (!preg_match('/^[[:print:]]+$/', trim($string))) {
        error(ERR_DNS_PRINTABLE);
        return false;
    }
    return true;
}

/** Test if CNAME is valid
 *
 * Check if any MX or NS entries exist which invalidated CNAME
 *
 * @param string $name CNAME to lookup
 *
 * @return boolean true if valid, false otherwise
 */
function is_valid_rr_cname_name($name) {
    global $db;

    $query = "SELECT id FROM records
			WHERE content = " . $db->quote($name, 'text') . "
			AND (type = " . $db->quote('MX', 'text') . " OR type = " . $db->quote('NS', 'text') . ")";

    $response = $db->queryOne($query);

    if (!empty($response)) {
        error(ERR_DNS_CNAME);
        return false;
    }

    return true;
}

/** Check if CNAME already exists
 *
 * @param string $name CNAME
 * @param int $rid Record ID
 *
 * @return boolean true if non-existant, false if exists
 */
function is_valid_rr_cname_exists($name, $rid) {
    global $db;

    $where = ($rid > 0 ? " AND id != " . $db->quote($rid, 'integer') : '');
    $query = "SELECT id FROM records
                        WHERE name = " . $db->quote($name, 'text') . $where . "
                        AND TYPE = 'CNAME'";

    $response = $db->queryOne($query);
    if ($response) {
        error(ERR_DNS_CNAME_EXISTS);
        return false;
    }
    return true;
}

/** Check if CNAME is unique (doesn't overlap A/AAAA)
 *
 * @param string $name CNAME
 * @param string $rid Record ID
 *
 * @return boolean true if unique, false if duplicate
 */
function is_valid_rr_cname_unique($name, $rid) {
    global $db;

    $where = ($rid > 0 ? " AND id != " . $db->quote($rid, 'integer') : '');
    $query = "SELECT id FROM records
                        WHERE name = " . $db->quote($name, 'text') . $where . "
                        AND TYPE IN ('A', 'AAAA', 'CNAME')";

    $response = $db->queryOne($query);
    if ($response) {
        error(ERR_DNS_CNAME_UNIQUE);
        return false;
    }
    return true;
}

/**
 * Check that the zone does not have a empty CNAME RR
 *
 * @param string $name
 * @param string $zone
 */
function is_not_empty_cname_rr($name, $zone) {

    if ($name == $zone) {
        error(ERR_DNS_CNAME_EMPTY);
        return false;
    }
    return true;
}

/** Check if target is not a CNAME
 *
 * @param string $target target to check
 *
 * @return boolean true if not alias, false if CNAME exists
 */
function is_valid_non_alias_target($target) {
    global $db;

    $query = "SELECT id FROM records
			WHERE name = " . $db->quote($target, 'text') . "
			AND TYPE = " . $db->quote('CNAME', 'text');

    $response = $db->queryOne($query);
    if ($response) {
        error(ERR_DNS_NON_ALIAS_TARGET);
        return false;
    }
    return true;
}

/** Check if HINFO content is valid
 *
 * @param string $content HINFO record content
 *
 * @return boolean true if valid, false otherwise
 */
function is_valid_rr_hinfo_content($content) {

    if ($content[0] == "\"") {
        $fields = preg_split('/(?<=") /', $content, 2);
    } else {
        $fields = preg_split('/ /', $content, 2);
    }

    for ($i = 0; ($i < 2); $i++) {
        if (!preg_match("/^([^\s]{1,1000})|\"([^\"]{1,998}\")$/i", $fields[$i])) {
            error(ERR_DNS_HINFO_INV_CONTENT);
            return false;
        }
    }

    return true;
}

/** Check if SOA content is valid
 *
 * @param mixed $content SOA record content
 *
 * @return boolean true if valid, false otherwise
 */
function is_valid_rr_soa_content(&$content) {

    $fields = preg_split("/\s+/", trim($content));
    $field_count = count($fields);

    if ($field_count == 0 || $field_count > 7) {
        return false;
    } else {
        if (!is_valid_hostname_fqdn($fields[0], 0) || preg_match('/\.arpa\.?$/', $fields[0])) {
            return false;
        }
        $final_soa = $fields[0];

        if (isset($fields[1])) {
            $addr_input = $fields[1];
        } else {
            global $dns_hostmaster;
            $addr_input = $dns_hostmaster;
        }
        if (!preg_match("/@/", $addr_input)) {
            $addr_input = preg_split('/(?<!\\\)\./', $addr_input, 2);
            $addr_to_check = str_replace("\\", "", $addr_input[0]) . "@" . $addr_input[1];
        } else {
            $addr_to_check = $addr_input;
        }

        if (!is_valid_email($addr_to_check)) {
            return false;
        } else {
            $addr_final = explode('@', $addr_to_check, 2);
            $final_soa .= " " . str_replace(".", "\\.", $addr_final[0]) . "." . $addr_final[1];
        }

        if (isset($fields[2])) {
            if (!is_numeric($fields[2])) {
                return false;
            }
            $final_soa .= " " . $fields[2];
        } else {
            $final_soa .= " 0";
        }

        if ($field_count != 7) {
            return false;
        } else {
            for ($i = 3; ($i < 7); $i++) {
                if (!is_numeric($fields[$i])) {
                    return false;
                } else {
                    $final_soa .= " " . $fields[$i];
                }
            }
        }
    }
    $content = $final_soa;
    return true;
}

/** Check if SOA name is valid
 *
 * Checks if SOA name = zone name
 *
 * @param string $name SOA name
 * @param string $zone Zone name
 *
 * @return boolean true if valid, false otherwise
 */
function is_valid_rr_soa_name($name, $zone) {
    if ($name != $zone) {
        error(ERR_DNS_SOA_NAME);
        return false;
    }
    return true;
}

/** Check if Priority is valid
 *
 * Check if MX or SRV priority is within range, otherwise set to 0
 *
 * @param mixed $prio Priority
 * @param string $type Record type
 *
 * @return boolean true if valid, false otherwise
 */
function is_valid_rr_prio(&$prio, $type) {
    if ($type == "MX" || $type == "SRV") {
        if (!is_numeric($prio) || $prio < 0 || $prio > 65535) {
            error(ERR_DNS_INV_PRIO);
            return false;
        }
    } else {
        $prio = 0;
    }

    return true;
}

/** Check if SRV name is valid
 *
 * @param mixed $name SRV name
 *
 * @return boolean true if valid, false otherwise
 */
function is_valid_rr_srv_name(&$name) {

    if (strlen($name) > 255) {
        error(ERR_DNS_HN_TOO_LONG);
        return false;
    }

    $fields = explode('.', $name, 3);
    if (!preg_match('/^_[\w-]+$/i', $fields[0])) {
        error(ERR_DNS_SRV_NAME);
        return false;
    }
    if (!preg_match('/^_[\w]+$/i', $fields[1])) {
        error(ERR_DNS_SRV_NAME);
        return false;
    }
    if (!is_valid_hostname_fqdn($fields[2], 0)) {
        error(ERR_DNS_SRV_NAME);
        return false;
    }
    $name = join('.', $fields);
    return true;
}

/** Check if SRV content is valid
 *
 * @param mixed $content SRV content
 *
 * @return boolean true if valid, false otherwise
 */
function is_valid_rr_srv_content(&$content) {
    $fields = preg_split("/\s+/", trim($content), 3);
    if (!is_numeric($fields[0]) || $fields[0] < 0 || $fields[0] > 65535) {
        error(ERR_DNS_SRV_WGHT);
        return false;
    }
    if (!is_numeric($fields[1]) || $fields[1] < 0 || $fields[1] > 65535) {
        error(ERR_DNS_SRV_PORT);
        return false;
    }
    if ($fields[2] == "" || ($fields[2] != "." && !is_valid_hostname_fqdn($fields[2], 0))) {
        error(ERR_DNS_SRV_TRGT);
        return false;
    }
    $content = join(' ', $fields);
    return true;
}

/** Check if TTL is valid and within range
 *
 * @param int $ttl TTL
 *
 * @return boolean true if valid,false otherwise
 */
function is_valid_rr_ttl(&$ttl) {

    if (!isset($ttl) || $ttl == "") {
        global $dns_ttl;
        $ttl = $dns_ttl;
    }

    if (!is_numeric($ttl) || $ttl < 0 || $ttl > 2147483647) {
        error(ERR_DNS_INV_TTL);
        return false;
    }

    return true;
}

/** Check if search string is valid
 *
 * @param string $search_string search string
 *
 * @return boolean true if valid, false otherwise
 */
function is_valid_search($search_string) {

    // Only allow for alphanumeric, numeric, dot, dash, underscore and
    // percent in search string. The last two are wildcards for SQL.
    // Needs extension probably for more usual record types.

    return preg_match('/^[a-z0-9.\-%_]+$/i', $search_string);
}

/** Check if SPF content is valid
 *
 * @param string $content SPF content
 *
 * @return boolean true if valid, false otherwise
 */
function is_valid_spf($content) {
    //Regex from http://www.schlitt.net/spf/tests/spf_record_regexp-03.txt
    $regex = "^[Vv]=[Ss][Pp][Ff]1( +([-+?~]?([Aa][Ll][Ll]|[Ii][Nn][Cc][Ll][Uu][Dd][Ee]:(%\{[CDHILOPR-Tcdhilopr-t]([1-9][0-9]?|10[0-9]|11[0-9]|12[0-8])?[Rr]?[+-/=_]*\}|%%|%_|%-|[!-$&-~])*(\.([A-Za-z]|[A-Za-z]([-0-9A-Za-z]?)*[0-9A-Za-z])|%\{[CDHILOPR-Tcdhilopr-t]([1-9][0-9]?|10[0-9]|11[0-9]|12[0-8])?[Rr]?[+-/=_]*\})|[Aa](:(%\{[CDHILOPR-Tcdhilopr-t]([1-9][0-9]?|10[0-9]|11[0-9]|12[0-8])?[Rr]?[+-/=_]*\}|%%|%_|%-|[!-$&-~])*(\.([A-Za-z]|[A-Za-z]([-0-9A-Za-z]?)*[0-9A-Za-z])|%\{[CDHILOPR-Tcdhilopr-t]([1-9][0-9]?|10[0-9]|11[0-9]|12[0-8])?[Rr]?[+-/=_]*\}))?((/([1-9]|1[0-9]|2[0-9]|3[0-2]))?(//([1-9][0-9]?|10[0-9]|11[0-9]|12[0-8]))?)?|[Mm][Xx](:(%\{[CDHILOPR-Tcdhilopr-t]([1-9][0-9]?|10[0-9]|11[0-9]|12[0-8])?[Rr]?[+-/=_]*\}|%%|%_|%-|[!-$&-~])*(\.([A-Za-z]|[A-Za-z]([-0-9A-Za-z]?)*[0-9A-Za-z])|%\{[CDHILOPR-Tcdhilopr-t]([1-9][0-9]?|10[0-9]|11[0-9]|12[0-8])?[Rr]?[+-/=_]*\}))?((/([1-9]|1[0-9]|2[0-9]|3[0-2]))?(//([1-9][0-9]?|10[0-9]|11[0-9]|12[0-8]))?)?|[Pp][Tt][Rr](:(%\{[CDHILOPR-Tcdhilopr-t]([1-9][0-9]?|10[0-9]|11[0-9]|12[0-8])?[Rr]?[+-/=_]*\}|%%|%_|%-|[!-$&-~])*(\.([A-Za-z]|[A-Za-z]([-0-9A-Za-z]?)*[0-9A-Za-z])|%\{[CDHILOPR-Tcdhilopr-t]([1-9][0-9]?|10[0-9]|11[0-9]|12[0-8])?[Rr]?[+-/=_]*\}))?|[Ii][Pp]4:([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])(/([1-9]|1[0-9]|2[0-9]|3[0-2]))?|[Ii][Pp]6:(::|([0-9A-Fa-f]{1,4}:){7}[0-9A-Fa-f]{1,4}|([0-9A-Fa-f]{1,4}:){1,8}:|([0-9A-Fa-f]{1,4}:){7}:[0-9A-Fa-f]{1,4}|([0-9A-Fa-f]{1,4}:){6}(:[0-9A-Fa-f]{1,4}){1,2}|([0-9A-Fa-f]{1,4}:){5}(:[0-9A-Fa-f]{1,4}){1,3}|([0-9A-Fa-f]{1,4}:){4}(:[0-9A-Fa-f]{1,4}){1,4}|([0-9A-Fa-f]{1,4}:){3}(:[0-9A-Fa-f]{1,4}){1,5}|([0-9A-Fa-f]{1,4}:){2}(:[0-9A-Fa-f]{1,4}){1,6}|[0-9A-Fa-f]{1,4}:(:[0-9A-Fa-f]{1,4}){1,7}|:(:[0-9A-Fa-f]{1,4}){1,8}|([0-9A-Fa-f]{1,4}:){6}([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])|([0-9A-Fa-f]{1,4}:){6}:([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])|([0-9A-Fa-f]{1,4}:){5}:([0-9A-Fa-f]{1,4}:)?([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])|([0-9A-Fa-f]{1,4}:){4}:([0-9A-Fa-f]{1,4}:){0,2}([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])|([0-9A-Fa-f]{1,4}:){3}:([0-9A-Fa-f]{1,4}:){0,3}([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])|([0-9A-Fa-f]{1,4}:){2}:([0-9A-Fa-f]{1,4}:){0,4}([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])|[0-9A-Fa-f]{1,4}::([0-9A-Fa-f]{1,4}:){0,5}([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])|::([0-9A-Fa-f]{1,4}:){0,6}([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5]))(/([1-9][0-9]?|10[0-9]|11[0-9]|12[0-8]))?|[Ee][Xx][Ii][Ss][Tt][Ss]:(%\{[CDHILOPR-Tcdhilopr-t]([1-9][0-9]?|10[0-9]|11[0-9]|12[0-8])?[Rr]?[+-/=_]*\}|%%|%_|%-|[!-$&-~])*(\.([A-Za-z]|[A-Za-z]([-0-9A-Za-z]?)*[0-9A-Za-z])|%\{[CDHILOPR-Tcdhilopr-t]([1-9][0-9]?|10[0-9]|11[0-9]|12[0-8])?[Rr]?[+-/=_]*\}))|[Rr][Ee][Dd][Ii][Rr][Ee][Cc][Tt]=(%\{[CDHILOPR-Tcdhilopr-t]([1-9][0-9]?|10[0-9]|11[0-9]|12[0-8])?[Rr]?[+-/=_]*\}|%%|%_|%-|[!-$&-~])*(\.([A-Za-z]|[A-Za-z]([-0-9A-Za-z]?)*[0-9A-Za-z])|%\{[CDHILOPR-Tcdhilopr-t]([1-9][0-9]?|10[0-9]|11[0-9]|12[0-8])?[Rr]?[+-/=_]*\})|[Ee][Xx][Pp]=(%\{[CDHILOPR-Tcdhilopr-t]([1-9][0-9]?|10[0-9]|11[0-9]|12[0-8])?[Rr]?[+-/=_]*\}|%%|%_|%-|[!-$&-~])*(\.([A-Za-z]|[A-Za-z]([-0-9A-Za-z]?)*[0-9A-Za-z])|%\{[CDHILOPR-Tcdhilopr-t]([1-9][0-9]?|10[0-9]|11[0-9]|12[0-8])?[Rr]?[+-/=_]*\})|[A-Za-z][-.0-9A-Z_a-z]*=(%\{[CDHILOPR-Tcdhilopr-t]([1-9][0-9]?|10[0-9]|11[0-9]|12[0-8])?[Rr]?[+-/=_]*\}|%%|%_|%-|[!-$&-~])*))* *$^";
    if (!preg_match($regex, $content)) {
        return false;
    } else {
        return true;
    }
}

/** Check if LOC content is valid
 *
 * @param string $content LOC content
 *
 * @return boolean true if valid, false otherwise
 */
function is_valid_loc($content) {
    $regex = "^(90|[1-8]\d|0?\d)( ([1-5]\d|0?\d)( ([1-5]\d|0?\d)(\.\d{1,3})?)?)? [NS] (180|1[0-7]\d|[1-9]\d|0?\d)( ([1-5]\d|0?\d)( ([1-5]\d|0?\d)(\.\d{1,3})?)?)? [EW] (-(100000(\.00)?|\d{1,5}(\.\d\d)?)|([1-3]?\d{1,7}(\.\d\d)?|4([01][0-9]{6}|2([0-7][0-9]{5}|8([0-3][0-9]{4}|4([0-8][0-9]{3}|9([0-5][0-9]{2}|6([0-6][0-9]|7[01]))))))(\.\d\d)?|42849672(\.([0-8]\d|9[0-5]))?))[m]?( (\d{1,7}|[1-8]\d{7})(\.\d\d)?[m]?){0,3}$^";
    if (!preg_match($regex, $content)) {
        return false;
    } else {
        return true;
    }
}
