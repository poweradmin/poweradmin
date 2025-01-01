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

use Poweradmin\AppConfiguration;
use Poweradmin\Application\Presenter\ErrorPresenter;
use Poweradmin\Domain\Error\ErrorMessage;
use Poweradmin\Domain\Model\TopLevelDomain;
use Poweradmin\Infrastructure\Database\PDOLayer;

/**
 * DNS functions
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class Dns
{
    private AppConfiguration $config;
    private PDOLayer $db;

    public function __construct(PDOLayer $db, AppConfiguration $config)
    {
        $this->db = $db;
        $this->config = $config;
    }

    /** Matches end of string
     *
     * Matches end of string (haystack) against another string (needle)
     *
     * @param string $needle
     * @param string $haystack
     *
     * @return true if ends with specified string, otherwise false
     */
    public static function endsWith(string $needle, string $haystack): bool
    {
        $length = strlen($haystack);
        $nLength = strlen($needle);
        return $nLength <= $length && strncmp(substr($haystack, -$nLength), $needle, $nLength) === 0;
    }

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
    public function validate_input(int $rid, int $zid, string $type, mixed &$content, mixed &$name, mixed &$prio, mixed &$ttl, $dns_hostmaster, $dns_ttl): bool
    {
        $dnsRecord = new DnsRecord($this->db, $this->config);
        $zone = $dnsRecord->get_domain_name_by_id($zid);    // TODO check for return

        if (!self::endsWith(strtolower($zone), strtolower($name))) {
            if (isset($name) && $name != "") {
                $name = $name . "." . $zone;
            } else {
                $name = $zone;
            }
        }

        if ($type != "CNAME") {
            if (!$this->is_valid_rr_cname_exists($name, $rid)) {
                return false;
            }
        }

        switch ($type) {

            case "A":
                if (!self::is_valid_ipv4($content)) {
                    return false;
                }
                if (!$this->is_valid_hostname_fqdn($name, 1)) {
                    return false;
                }
                break;

            case "AFSDB":
            case "ALIAS":
            case "APL":
            case "CAA":
            case "CDNSKEY":
            case "CDS":
            case "CERT":
            case "DNAME":
            case "L32":
            case "L64":
            case "LUA":
            case "LP":
            case "MAILA":
            case "MAILB":
            case "OPENPGPKEY":
            case "SIG":
            case "SMIMEA":
            case "TKEY":
            case "URI":
            case "A6": // TODO: implement validation.
                break;

            case "AAAA":
                if (!self::is_valid_ipv6($content)) {
                    return false;
                }
                if (!$this->is_valid_hostname_fqdn($name, 1)) {
                    return false;
                }
                break;

            case "CNAME":
                if (!$this->is_valid_rr_cname_name($name)) {
                    return false;
                }
                if (!$this->is_valid_rr_cname_unique($name, $rid)) {
                    return false;
                }
                if (!$this->is_valid_hostname_fqdn($name, 1)) {
                    return false;
                }
                if (!$this->is_valid_hostname_fqdn($content, 0)) {
                    return false;
                }
                if (!self::is_not_empty_cname_rr($name, $zone)) {
                    return false;
                }
                break;

            case 'DHCID':
            case 'DLV':
            case 'DNSKEY':
            case 'EUI48':
            case 'EUI64':
            case 'HTTPS':
            case 'IPSECKEY':
            case 'KEY':
            case 'KX':
            case 'MINFO':
            case 'MR':
            case 'NAPTR':
            case 'NID':
            case 'NSEC':
            case 'NSEC3':
            case 'NSEC3PARAM':
            case 'RKEY':
            case 'RP':
            case 'RRSIG':
            case 'SSHFP':
            case 'SVCB':
            case 'TLSA':
            case 'TSIG':
            case 'WKS':
            case 'CSYNC': // TODO: implement validation
                break;

            case 'DS':
                if (!self::is_valid_ds($content)) {
                    return false;
                }
                break;

            case "HINFO":
                if (!self::is_valid_rr_hinfo_content($content)) {
                    return false;
                }
                if (!$this->is_valid_hostname_fqdn($name, 1)) {
                    return false;
                }
                break;

            case "LOC":
                if (!self::is_valid_loc($content)) {
                    return false;
                }
                if (!$this->is_valid_hostname_fqdn($name, 1)) {
                    return false;
                }
                break;

            case "NS":
            case "MX":
                if (!$this->is_valid_hostname_fqdn($content, 0)) {
                    return false;
                }
                if (!$this->is_valid_hostname_fqdn($name, 1)) {
                    return false;
                }
                if (!$this->is_valid_non_alias_target($content)) {
                    return false;
                }
                break;

            case "PTR":
                if (!$this->is_valid_hostname_fqdn($content, 0)) {
                    return false;
                }
                if (!$this->is_valid_hostname_fqdn($name, 1)) {
                    return false;
                }
                break;

            case "SOA":
                if (!self::is_valid_rr_soa_name($name, $zone)) {
                    return false;
                }
                if (!$this->is_valid_hostname_fqdn($name, 1)) {
                    return false;
                }
                if (!$this->is_valid_rr_soa_content($content, $dns_hostmaster)) {
                    $error = new ErrorMessage(_('Your content field doesnt have a legit value.'));
                    $errorPresenter = new ErrorPresenter();
                    $errorPresenter->present($error);

                    return false;
                }
                break;

            case "SPF":
                if (!self::is_valid_spf($content)) {
                    $error = new ErrorMessage(_('The content of the SPF record is invalid'));
                    $errorPresenter = new ErrorPresenter();
                    $errorPresenter->present($error);

                    return false;
                }
                if (!self::has_quotes_around($content)) {
                    return false;
                }
                break;

            case "SRV":
                if (!$this->is_valid_rr_srv_name($name)) {
                    return false;
                }
                if (!$this->is_valid_rr_srv_content($content, $name)) {
                    return false;
                }
                break;

            case "TXT":
                if (!self::is_valid_printable($name)) {
                    return false;
                }
                if (!self::is_valid_printable($content) || self::has_html_tags($content)) {
                    return false;
                }
                if (!self::is_properly_quoted($content)) {
                    return false;
                }

                break;

            default:
                $error = new ErrorMessage(_('Unknown record type.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);

                return false;
        }

        if (!self::is_valid_rr_prio($prio, $type)) {
            $error = new ErrorMessage(_('Invalid value for prio field.'));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            return false;
        }

        if (!self::is_valid_rr_ttl($ttl, $dns_ttl)) {
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
    public function is_valid_hostname_fqdn(mixed &$hostname, string $wildcard): bool
    {
        $dns_top_level_tld_check = $this->config->get('dns_top_level_tld_check');
        $dns_strict_tld_check = $this->config->get('dns_strict_tld_check');

        if ($hostname == ".") {
            return true;
        }

        $hostname = preg_replace("/\.$/", "", $hostname);

        # The full domain name may not exceed a total length of 253 characters.
        if (strlen($hostname) > 253) {
            $error = new ErrorMessage(_('The hostname is too long.'));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            return false;
        }

        $hostname_labels = explode('.', $hostname);
        $label_count = count($hostname_labels);

        if ($dns_top_level_tld_check && $label_count == 1) {
            return false;
        }

        foreach ($hostname_labels as $hostname_label) {
            if ($wildcard == 1 && !isset($first)) {
                if (!preg_match('/^(\*|[\w\-\/]+)$/', $hostname_label)) {
                    $error = new ErrorMessage(_('You have invalid characters in your hostname.'));
                    $errorPresenter = new ErrorPresenter();
                    $errorPresenter->present($error);

                    return false;
                }
                $first = 1;
            } else {
                if (!preg_match('/^[\w\-\/]+$/', $hostname_label)) {
                    $error = new ErrorMessage(_('You have invalid characters in your hostname.'));
                    $errorPresenter = new ErrorPresenter();
                    $errorPresenter->present($error);

                    return false;
                }
            }
            if (str_starts_with($hostname_label, "-")) {
                $error = new ErrorMessage(_('A hostname can not start or end with a dash.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);

                return false;
            }
            if (str_ends_with($hostname_label, "-")) {
                $error = new ErrorMessage(_('A hostname can not start or end with a dash.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);

                return false;
            }
            if (strlen($hostname_label) < 1 || strlen($hostname_label) > 63) {
                $error = new ErrorMessage(_('Given hostname or one of the labels is too short or too long.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);

                return false;
            }
        }

        if ($hostname_labels[$label_count - 1] == "arpa" && (substr_count($hostname_labels[0], "/") == 1 xor substr_count($hostname_labels[1], "/") == 1)) {
            if (substr_count($hostname_labels[0], "/") == 1) {
                $array = explode("/", $hostname_labels[0]);
            } else {
                $array = explode("/", $hostname_labels[1]);
            }
            if (count($array) != 2) {
                $error = new ErrorMessage(_('Invalid hostname.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);

                return false;
            }
            if (!is_numeric($array[0]) || $array[0] < 0 || $array[0] > 255) {
                $error = new ErrorMessage(_('Invalid hostname.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);

                return false;
            }
            if (!is_numeric($array[1]) || $array[1] < 25 || $array[1] > 31) {
                $error = new ErrorMessage(_('Invalid hostname.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);

                return false;
            }
        } else {
            if (substr_count($hostname, "/") > 0) {
                $error = new ErrorMessage(_('Given hostname has too many slashes.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);

                return false;
            }
        }

        if ($dns_strict_tld_check && !TopLevelDomain::isValidTopLevelDomain($hostname)) {
            $error = new ErrorMessage(_('You are using an invalid top level domain.'));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

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
    public static function is_valid_ipv4(string $ipv4, bool $answer = true): bool
    {

        if (filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === FALSE) {
            if ($answer) {
                $error = new ErrorMessage(_('This is not a valid IPv4 address.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
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
    public static function is_valid_ipv6(string $ipv6, bool $answer = false): bool
    {

        if (filter_var($ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === FALSE) {
            if ($answer) {
                $error = new ErrorMessage(_('This is not a valid IPv6 address.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        return true;
    }

    /** Test if multiple IP addresses are valid
     *
     *  Takes a string of comma separated IP addresses and tests validity
     *
     * @param string $ips Comma separated IP addresses
     *
     * @return boolean true if valid, false otherwise
     */
    public static function are_multiple_valid_ips(string $ips): bool
    {

// multiple master NS-records are permitted and must be separated by ,
// e.g. "192.0.0.1, 192.0.0.2, 2001:1::1"

        $are_valid = false;
        $multiple_ips = explode(",", $ips);
        if (is_array($multiple_ips)) {
            foreach ($multiple_ips as $m_ip) {
                $trimmed_ip = trim($m_ip);
                if (self::is_valid_ipv4($trimmed_ip, false) || self::is_valid_ipv6($trimmed_ip)) {
                    $are_valid = true;
                } else {
                    return false;
                }
            }
        } elseif (self::is_valid_ipv4($ips) || self::is_valid_ipv6($ips)) {
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
    public static function is_valid_printable(string $string): bool
    {
        if (!preg_match('/^[[:print:]]+$/', trim($string))) {
            $error = new ErrorMessage(_('Invalid characters have been used in this record.'));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            return false;
        }
        return true;
    }

    /** Test if string has html opening and closing tags
     *
     * @param string $string Input string
     * @return bool true if valid, false otherwise
     */
    public static function has_html_tags(string $string): bool
    {
        if (preg_match('/[<>]/', trim($string))) {
            $error = new ErrorMessage(_('You cannot use html tags for this type of record.'));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            return true;
        }
        return false;
    }

    /** Verify that the content is properly quoted
     *
     * @param string $content
     * @return bool
     */
    public static function is_properly_quoted(string $content): bool
    {
        $startsWithQuote = isset($content[0]) && $content[0] === '"';
        $endsWithQuote = isset($content[strlen($content) - 1]) && $content[strlen($content) - 1] === '"';

        if ($startsWithQuote && $endsWithQuote) {
            $subContent = substr($content, 1, -1);
        } else {
            $subContent = $content;
        }

        $pattern = '/(?<!\\\\)"/';

        if (preg_match($pattern, $subContent)) {
            $error = new ErrorMessage(_('Backslashes must precede all quotes (") in TXT content'));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);
            return false;
        }

        return true;
    }

    /** Verify that the string is enclosed in quotes
     *
     * @param string $string Input string
     * @return bool true if valid, false otherwise
     */
    public static function has_quotes_around(string $string): bool
    {
        // Ignore empty line
        if (strlen($string) === 0) {
            return true;
        }

        if (!str_starts_with($string, '"') || !str_ends_with($string, '"')) {
            $error = new ErrorMessage(_('Add quotes around TXT record content.'));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

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
    public function is_valid_rr_cname_name(string $name): bool
    {
        $pdns_db_name = $this->config->get('pdns_db_name');
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

        $query = "SELECT id FROM $records_table
			WHERE content = " . $this->db->quote($name, 'text') . "
			AND (type = " . $this->db->quote('MX', 'text') . " OR type = " . $this->db->quote('NS', 'text') . ")";

        $response = $this->db->queryOne($query);

        if (!empty($response)) {
            $error = new ErrorMessage(_('This is not a valid CNAME. Did you assign an MX or NS record to the record?'));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

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
    public function is_valid_rr_cname_exists(string $name, int $rid): bool
    {
        $pdns_db_name = $this->config->get('pdns_db_name');
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

        $where = ($rid > 0 ? " AND id != " . $this->db->quote($rid, 'integer') : '');
        $query = "SELECT id FROM $records_table
                        WHERE name = " . $this->db->quote($name, 'text') . $where . "
                        AND TYPE = 'CNAME'";

        $response = $this->db->queryOne($query);
        if ($response) {
            $error = new ErrorMessage(_('This is not a valid record. There is already exists a CNAME with this name.'));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

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
    public function is_valid_rr_cname_unique(string $name, string $rid): bool
    {
        $pdns_db_name = $this->config->get('pdns_db_name');
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

        $where = ($rid > 0 ? " AND id != " . $this->db->quote($rid, 'integer') : '');
        $query = "SELECT id FROM $records_table
                        WHERE name = " . $this->db->quote($name, 'text') . $where;

        $response = $this->db->queryOne($query);
        if ($response) {
            $error = new ErrorMessage(_('This is not a valid CNAME. There already exists a record with this name.'));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            return false;
        }
        return true;
    }

    /**
     * Check that the zone does not have an empty CNAME RR
     *
     * @param string $name
     * @param string $zone
     * @return bool
     */
    public static function is_not_empty_cname_rr(string $name, string $zone): bool
    {

        if ($name == $zone) {
            $error = new ErrorMessage(_('Empty CNAME records are not allowed.'));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

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
    public function is_valid_non_alias_target(string $target): bool
    {
        $pdns_db_name = $this->config->get('pdns_db_name');
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

        $query = "SELECT id FROM $records_table
			WHERE name = " . $this->db->quote($target, 'text') . "
			AND TYPE = " . $this->db->quote('CNAME', 'text');

        $response = $this->db->queryOne($query);
        if ($response) {
            $error = new ErrorMessage(_('You can not point a NS or MX record to a CNAME record. Remove or rename the CNAME record first, or take another name.'));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

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
    public static function is_valid_rr_hinfo_content(string $content): bool
    {

        if ($content[0] == "\"") {
            $fields = preg_split('/(?<=") /', $content, 2);
        } else {
            $fields = explode(' ', $content, 2);
        }

        for ($i = 0; ($i < 2); $i++) {
            if (!preg_match("/^([^\s]{1,1000})|\"([^\"]{1,998}\")$/i", $fields[$i])) {
                $error = new ErrorMessage(_('Invalid value for content field of HINFO record.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);

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
    public function is_valid_rr_soa_content(mixed &$content, $dns_hostmaster): bool
    {
        $fields = preg_split("/\s+/", trim($content));
        $field_count = count($fields);

        if ($field_count == 0 || $field_count > 7) {
            return false;
        } else {
            if (!$this->is_valid_hostname_fqdn($fields[0], 0) || preg_match('/\.arpa\.?$/', $fields[0])) {
                return false;
            }
            $final_soa = $fields[0];

            $addr_input = $fields[1] ?? $dns_hostmaster;
            if (!str_contains($addr_input, "@")) {
                $addr_input = preg_split('/(?<!\\\)\./', $addr_input, 2);
                if (count($addr_input) == 2) {
                    $addr_to_check = str_replace("\\", "", $addr_input[0]) . "@" . $addr_input[1];
                } else {
                    $addr_to_check = str_replace("\\", "", $addr_input[0]);
                }
            } else {
                $addr_to_check = $addr_input;
            }

            $validation = new Validator($this->db, $this->config);
            if (!$validation->is_valid_email($addr_to_check)) {
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
    public static function is_valid_rr_soa_name(string $name, string $zone): bool
    {
        if ($name != $zone) {
            $error = new ErrorMessage(_('Invalid value for name field of SOA record. It should be the name of the zone.'));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            return false;
        }
        return true;
    }

    /** Check if Priority is valid
     *
     * Check if MX or SRV priority is within range
     *
     * @param mixed $prio Priority
     * @param string $type Record type
     *
     * @return boolean true if valid, false otherwise
     */
    public static function is_valid_rr_prio(mixed $prio, string $type): bool
    {
        return ($type == "MX" || $type == "SRV") && (is_numeric($prio) && $prio >= 0 && $prio <= 65535) || is_numeric($prio) && $prio == 0;
    }

    /** Check if SRV name is valid
     *
     * @param mixed $name SRV name
     *
     * @return boolean true if valid, false otherwise
     */
    public function is_valid_rr_srv_name(mixed &$name): bool
    {
        if (strlen($name) > 255) {
            $error = new ErrorMessage(_('The hostname is too long.'));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            return false;
        }

        $fields = explode('.', $name, 3);
        if (!preg_match('/^_[\w\-]+$/i', $fields[0])) {
            $error = new ErrorMessage(_('Invalid service value in name field of SRV record.'), $name);
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            return false;
        }
        if (!preg_match('/^_[\w]+$/i', $fields[1])) {
            $error = new ErrorMessage(_('Invalid protocol value in name field of SRV record.'), $name);
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            return false;
        }
        if (!$this->is_valid_hostname_fqdn($fields[2], 0)) {
            $error = new ErrorMessage(_('Invalid FQDN value in name field of SRV record.'), $name);
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

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
    public function is_valid_rr_srv_content(mixed &$content, $name): bool
    {
        $fields = preg_split("/\s+/", trim($content), 3);
        if (!is_numeric($fields[0]) || $fields[0] < 0 || $fields[0] > 65535) {
            $error = new ErrorMessage(_('Invalid value for the priority field of the SRV record.'), $name);
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            return false;
        }
        if (!is_numeric($fields[1]) || $fields[1] < 0 || $fields[1] > 65535) {
            $error = new ErrorMessage(_('Invalid value for the weight field of the SRV record.'), $name);
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            return false;
        }
        if ($fields[2] == "" || ($fields[2] != "." && !$this->is_valid_hostname_fqdn($fields[2], 0))) {
            $error = new ErrorMessage(_('Invalid SRV target.'), $name);
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

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
    public static function is_valid_rr_ttl(int &$ttl, $dns_ttl): bool
    {

        if (!isset($ttl) || $ttl == "") {
            $ttl = $dns_ttl;
        }

        if (!is_numeric($ttl) || $ttl < 0 || $ttl > 2147483647) {
            $error = new ErrorMessage(_('Invalid value for TTL field. It should be numeric.'));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            return false;
        }

        return true;
    }

    /** Check if SPF content is valid
     *
     * @param string $content SPF content
     *
     * @return boolean true if valid, false otherwise
     */
    public static function is_valid_spf(string $content): bool
    {
        // Cleanup required quotes before validation
        $content = trim($content, '"');

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
    public static function is_valid_loc(string $content): bool
    {
        $regex = "^(90|[1-8]\d|0?\d)( ([1-5]\d|0?\d)( ([1-5]\d|0?\d)(\.\d{1,3})?)?)? [NS] (180|1[0-7]\d|[1-9]\d|0?\d)( ([1-5]\d|0?\d)( ([1-5]\d|0?\d)(\.\d{1,3})?)?)? [EW] (-(100000(\.00)?|\d{1,5}(\.\d\d)?)|([1-3]?\d{1,7}(\.\d\d)?|4([01][0-9]{6}|2([0-7][0-9]{5}|8([0-3][0-9]{4}|4([0-8][0-9]{3}|9([0-5][0-9]{2}|6([0-6][0-9]|7[01]))))))(\.\d\d)?|42849672(\.([0-8]\d|9[0-5]))?))[m]?( (\d{1,7}|[1-8]\d{7})(\.\d\d)?[m]?){0,3}$^";
        if (!preg_match($regex, $content)) {
            return false;
        } else {
            return true;
        }
    }

    public static function is_valid_ds($content): bool
    {
        if (preg_match("/^([0-9]+) ([0-9]+) ([0-9]+) ([a-f0-9]+)$/i", $content)) {
            return true;
        } else {
            return false;
        }
    }
}
