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

            case "CAA":
                if (!self::is_valid_caa($content)) {
                    return false;
                }
                if (!$this->is_valid_hostname_fqdn($name, 1)) {
                    return false;
                }
                break;

            case "AFSDB":
                if (!self::is_valid_afsdb($content)) {
                    return false;
                }
                // Extract hostname from "subtype hostname" format for validation
                if (preg_match('/^\d+\s+(.+)$/i', $content, $matches)) {
                    $hostname = trim($matches[1]);
                    if (!$this->is_valid_hostname_fqdn($hostname, 1)) {
                        return false;
                    }
                }
                break;

            case "ALIAS":
                if (!self::is_valid_alias($content)) {
                    return false;
                }
                if (!$this->is_valid_hostname_fqdn($content, 1)) {
                    return false;
                }
                break;

            case "APL":
                if (!self::is_valid_apl($content)) {
                    return false;
                }
                break;

            case "CDNSKEY":
                if (!self::is_valid_cdnskey($content)) {
                    return false;
                }
                break;

            case "CDS":
                if (!self::is_valid_cds($content)) {
                    return false;
                }
                break;

            case "CERT":
                if (!self::is_valid_cert($content)) {
                    return false;
                }
                break;

            case "DNAME":
                if (!self::is_valid_dname($content)) {
                    return false;
                }
                if (!$this->is_valid_hostname_fqdn($content, 1)) {
                    return false;
                }
                break;

            case "L32":
                if (!self::is_valid_l32($content)) {
                    return false;
                }
                break;

            case "L64":
                if (!self::is_valid_l64($content)) {
                    return false;
                }
                break;

            case "LUA":
                if (!self::is_valid_lua($content)) {
                    return false;
                }
                break;

            case "LP":
                if (!self::is_valid_lp($content)) {
                    return false;
                }
                // Extract FQDN from "preference fqdn" format for validation
                if (preg_match('/^\d+\s+(.+)$/i', $content, $matches)) {
                    $fqdn = trim($matches[1]);
                    if (!$this->is_valid_hostname_fqdn($fqdn, 1)) {
                        return false;
                    }
                }
                break;

            case "MAILA":
            case "MAILB":
                // MAILA and MAILB are obsolete meta-query types (RFC 883)
                // They are used only in DNS queries, not in zone files
                // Reject any attempt to create these record types
                if (!self::is_valid_meta_query_type($type)) {
                    return false;
                }
                break;

            case "OPENPGPKEY":
                if (!self::is_valid_openpgpkey($content)) {
                    return false;
                }
                break;

            case "SIG":
                if (!self::is_valid_sig($content)) {
                    return false;
                }
                break;

            case "DHCID":
                if (!self::is_valid_dhcid($content)) {
                    return false;
                }
                break;

            case "SMIMEA":
                if (!self::is_valid_smimea($content)) {
                    return false;
                }
                break;

            case "TKEY":
                if (!self::is_valid_tkey($content)) {
                    return false;
                }
                break;

            case "URI":
                if (!self::is_valid_uri($content)) {
                    return false;
                }
                break;

            case "TLSA":
                if (!self::is_valid_tlsa($content)) {
                    return false;
                }
                break;

            case "SSHFP":
                if (!self::is_valid_sshfp($content)) {
                    return false;
                }
                break;

            case "NAPTR":
                if (!self::is_valid_naptr($content)) {
                    return false;
                }
                break;

            case "RP":
                if (!self::is_valid_rp($content)) {
                    return false;
                }
                break;

            case "DNSKEY":
                if (!self::is_valid_dnskey($content)) {
                    return false;
                }
                break;

            case "NSEC":
                if (!self::is_valid_nsec($content)) {
                    return false;
                }
                break;

            case "NSEC3":
                if (!self::is_valid_nsec3($content)) {
                    return false;
                }
                break;

            case "NSEC3PARAM":
                if (!self::is_valid_nsec3param($content)) {
                    return false;
                }
                break;

            case "RRSIG":
                if (!self::is_valid_rrsig($content)) {
                    return false;
                }
                break;

            case "TSIG":
                if (!self::is_valid_tsig($content)) {
                    return false;
                }
                break;

            case "A6":
                if (!self::is_valid_a6($content)) {
                    return false;
                }
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

            case 'EUI48':
                if (!self::is_valid_eui48($content)) {
                    return false;
                }
                break;

            case 'EUI64':
                if (!self::is_valid_eui64($content)) {
                    return false;
                }
                break;

            case 'NID':
                if (!self::is_valid_nid($content)) {
                    return false;
                }
                break;

            case 'KX':
                if (!self::is_valid_kx($content)) {
                    return false;
                }
                break;

            case 'IPSECKEY':
                if (!self::is_valid_ipseckey($content)) {
                    return false;
                }
                break;

            case 'DLV':
                if (!self::is_valid_dlv($content)) {
                    return false;
                }
                break;

            case 'KEY':
                if (!self::is_valid_key($content)) {
                    return false;
                }
                break;

            case 'MINFO':
                if (!self::is_valid_minfo($content)) {
                    return false;
                }
                break;

            case 'MR':
                if (!self::is_valid_mr($content)) {
                    return false;
                }
                break;

            case 'WKS':
                if (!self::is_valid_wks($content)) {
                    return false;
                }
                break;

            case 'HTTPS':
                if (!self::is_valid_https($content)) {
                    return false;
                }
                break;

            case 'SVCB':
                if (!self::is_valid_svcb($content)) {
                    return false;
                }
                break;

            case 'CSYNC':
                if (!self::is_valid_csync($content)) {
                    return false;
                }
                break;

            case 'ZONEMD':
                if (!self::is_valid_zonemd($content)) {
                    return false;
                }
                break;

            case 'RKEY':
                // TODO: implement validation
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

        if (filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
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

        if (filter_var($ipv6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
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

    /** Check if CDNSKEY content is valid
     *
     * Based on PowerDNS CDNSKEY implementation (RFC 7344)
     * Format: <flags> <protocol> <algorithm> <public_key>
     * - flags: 16-bit integer (0-65535)
     * - protocol: 8-bit integer (always 3 for DNSSEC)
     * - algorithm: 8-bit integer (DNSSEC algorithm number)
     * - public_key: Base64-encoded public key
     *
     * CDNSKEY is identical to DNSKEY but used for signaling child zone keys.
     *
     * @param string $content CDNSKEY content
     * @param boolean $answer print error if true
     * [default=true]
     *
     * @return boolean true if valid, false otherwise
     */
    public static function is_valid_cdnskey(string $content, bool $answer = true): bool
    {
        $content = trim($content);

        // Match: <flags> <protocol> <algorithm> <public_key>
        if (!preg_match('/^(\d+)\s+(\d+)\s+(\d+)\s+(.+)$/i', $content, $matches)) {
            if ($answer) {
                $error = new ErrorMessage(_('Invalid CDNSKEY record format. Expected: FLAGS PROTOCOL ALGORITHM PUBLIC_KEY (e.g., 257 3 5 AwEA...)'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        $flags = (int)$matches[1];
        $protocol = (int)$matches[2];
        $algorithm = (int)$matches[3];
        $public_key = trim($matches[4]);

        // Validate flags (0-65535, uint16)
        if ($flags < 0 || $flags > 65535) {
            if ($answer) {
                $error = new ErrorMessage(_('CDNSKEY flags must be between 0 and 65535.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate protocol (0-255, uint8, typically 3)
        if ($protocol < 0 || $protocol > 255) {
            if ($answer) {
                $error = new ErrorMessage(_('CDNSKEY protocol must be between 0 and 255.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate algorithm (0-255, uint8)
        if ($algorithm < 0 || $algorithm > 255) {
            if ($answer) {
                $error = new ErrorMessage(_('CDNSKEY algorithm must be between 0 and 255.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate public key is Base64
        if (empty($public_key)) {
            if ($answer) {
                $error = new ErrorMessage(_('CDNSKEY public key cannot be empty.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Check if public key is valid Base64
        if (!preg_match('/^[A-Za-z0-9+\/=]+$/', $public_key)) {
            if ($answer) {
                $error = new ErrorMessage(_('CDNSKEY public key must be valid Base64.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Try to decode Base64 to ensure it's valid
        $decoded = base64_decode($public_key, true);
        if ($decoded === false) {
            if ($answer) {
                $error = new ErrorMessage(_('CDNSKEY public key contains invalid Base64 encoding.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        return true;
    }

    /** Check if APL content is valid
     *
     * Based on PowerDNS APL implementation (RFC 3123)
     * Format: [!]<addressfamily>:<address>/<prefix> [...]
     * - addressfamily: 1 (IPv4) or 2 (IPv6)
     * - address: IP address
     * - prefix: CIDR prefix length
     * - ! prefix means negation (optional)
     *
     * Examples:
     * - 1:10.0.0.0/32
     * - !1:10.1.1.1/32
     * - 2:2001:db8::/32
     * - 1:10.0.0.0/32 2:100::/8
     *
     * @param string $content APL content
     * @param boolean $answer print error if true
     * [default=true]
     *
     * @return boolean true if valid, false otherwise
     */
    public static function is_valid_apl(string $content, bool $answer = true): bool
    {
        $content = trim($content);

        // Empty APL is valid per RFC 3123
        if (empty($content)) {
            return true;
        }

        // Split by spaces to get individual APL elements
        $elements = preg_split('/\s+/', $content);

        foreach ($elements as $element) {
            // Parse optional negation prefix
            $negated = false;
            if (str_starts_with($element, '!')) {
                $negated = true;
                $element = substr($element, 1);
            }

            // Match: <family>:<address>/<prefix>
            if (!preg_match('/^([12]):(.+)\/(\d+)$/', $element, $matches)) {
                if ($answer) {
                    $error = new ErrorMessage(sprintf(_('Invalid APL element format: %s. Expected: [!]FAMILY:ADDRESS/PREFIX'), $element));
                    $errorPresenter = new ErrorPresenter();
                    $errorPresenter->present($error);
                }
                return false;
            }

            $family = (int)$matches[1];
            $address = $matches[2];
            $prefix = (int)$matches[3];

            // Validate based on address family
            if ($family === 1) {
                // IPv4
                if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    if ($answer) {
                        $error = new ErrorMessage(sprintf(_('Invalid IPv4 address in APL: %s'), $address));
                        $errorPresenter = new ErrorPresenter();
                        $errorPresenter->present($error);
                    }
                    return false;
                }
                if ($prefix < 0 || $prefix > 32) {
                    if ($answer) {
                        $error = new ErrorMessage(_('IPv4 prefix must be between 0 and 32.'));
                        $errorPresenter = new ErrorPresenter();
                        $errorPresenter->present($error);
                    }
                    return false;
                }
            } elseif ($family === 2) {
                // IPv6
                if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    if ($answer) {
                        $error = new ErrorMessage(sprintf(_('Invalid IPv6 address in APL: %s'), $address));
                        $errorPresenter = new ErrorPresenter();
                        $errorPresenter->present($error);
                    }
                    return false;
                }
                if ($prefix < 0 || $prefix > 128) {
                    if ($answer) {
                        $error = new ErrorMessage(_('IPv6 prefix must be between 0 and 128.'));
                        $errorPresenter = new ErrorPresenter();
                        $errorPresenter->present($error);
                    }
                    return false;
                }
            } else {
                if ($answer) {
                    $error = new ErrorMessage(sprintf(_('Unknown address family in APL: %d. Must be 1 (IPv4) or 2 (IPv6).'), $family));
                    $errorPresenter = new ErrorPresenter();
                    $errorPresenter->present($error);
                }
                return false;
            }
        }

        return true;
    }

    /** Check if ALIAS content is valid
     *
     * Based on PowerDNS ALIAS implementation
     * Format: <target>
     * - target: fully qualified domain name
     *
     * ALIAS is similar to CNAME but allows other records at the apex.
     * It's a PowerDNS-specific extension, not a standard DNS record type.
     *
     * @param string $content ALIAS content
     * @param boolean $answer print error if true
     * [default=true]
     *
     * @return boolean true if valid, false otherwise
     */
    public static function is_valid_alias(string $content, bool $answer = true): bool
    {
        $content = trim($content);

        // ALIAS record contains just a target hostname
        if (empty($content)) {
            if ($answer) {
                $error = new ErrorMessage(_('ALIAS target hostname cannot be empty.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Basic hostname format check (detailed validation done separately)
        // Allow standard hostname characters including hyphens, dots, underscores
        if (!preg_match('/^[a-z0-9._-]+$/i', $content)) {
            if ($answer) {
                $error = new ErrorMessage(_('ALIAS target must be a valid hostname.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        return true;
    }

    /** Check if AFSDB content is valid
     *
     * Based on PowerDNS AFSDB implementation (RFC 1183)
     * Format: <subtype> <hostname>
     * - subtype: 16-bit integer (0-65535)
     *   1 = AFS version 3.0 location service
     *   2 = DCE/NCA root cell directory node
     * - hostname: fully qualified domain name
     *
     * @param string $content AFSDB content
     * @param boolean $answer print error if true
     * [default=true]
     *
     * @return boolean true if valid, false otherwise
     */
    public static function is_valid_afsdb(string $content, bool $answer = true): bool
    {
        $content = trim($content);

        // Match: <subtype> <hostname>
        if (!preg_match('/^(\d+)\s+(.+)$/i', $content, $matches)) {
            if ($answer) {
                $error = new ErrorMessage(_('Invalid AFSDB record format. Expected: SUBTYPE HOSTNAME (e.g., 1 afs-server.example.com)'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        $subtype = (int)$matches[1];
        $hostname = trim($matches[2]);

        // Validate subtype (0-65535, uint16)
        if ($subtype < 0 || $subtype > 65535) {
            if ($answer) {
                $error = new ErrorMessage(_('AFSDB subtype must be between 0 and 65535.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate hostname is not empty
        if (empty($hostname)) {
            if ($answer) {
                $error = new ErrorMessage(_('AFSDB hostname cannot be empty.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Note: Hostname validation is handled separately by is_valid_hostname_fqdn
        return true;
    }

    /** Check if CAA content is valid
     *
     * Based on PowerDNS CAA implementation (RFC 8659)
     * Format: <flags> <tag> <value>
     * - flags: 0-255 (uint8)
     * - tag: alphanumeric property tag (case-insensitive)
     * - value: quoted string (can be empty)
     *
     * @param string $content CAA content
     * @param boolean $answer print error if true
     * [default=true]
     *
     * @return boolean true if valid, false otherwise
     */
    public static function is_valid_caa(string $content, bool $answer = true): bool
    {
        $content = trim($content);

        // Match: <flags> <tag> <value>
        // Value is optional but if present must be quoted (per PowerDNS implementation)
        if (!preg_match('/^(\d{1,3})\s+([a-z0-9]+)(?:\s+(.*))?$/i', $content, $matches)) {
            if ($answer) {
                $error = new ErrorMessage(_('Invalid CAA record format. Expected: FLAGS TAG VALUE (e.g., 0 issue "letsencrypt.org")'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        $flags = (int)$matches[1];
        $tag = strtolower($matches[2]);
        $value = $matches[3] ?? '';

        // Validate flags (0-255, uint8)
        if ($flags < 0 || $flags > 255) {
            if ($answer) {
                $error = new ErrorMessage(_('CAA flags must be between 0 and 255.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate tag (alphanumeric only, per RFC 8659)
        // Any alphanumeric tag is valid for extensibility
        if (!preg_match('/^[a-z0-9]+$/i', $tag)) {
            if ($answer) {
                $error = new ErrorMessage(_('CAA tag must be alphanumeric.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // If value is present, it must be quoted (per PowerDNS xfrText)
        if (!empty($value)) {
            if (!preg_match('/^".*"$/', $value)) {
                if ($answer) {
                    $error = new ErrorMessage(_('CAA value must be enclosed in quotes (e.g., "letsencrypt.org" or "").'));
                    $errorPresenter = new ErrorPresenter();
                    $errorPresenter->present($error);
                }
                return false;
            }

            // Check for properly escaped quotes inside
            // Empty value "" is valid per RFC 8659 (denies issuance)
            $inner_value = substr($value, 1, -1);
            if (!empty($inner_value)) {
                // Look for quotes not preceded by a backslash
                if (preg_match('/(?<!\\\\)"/', $inner_value)) {
                    if ($answer) {
                        $error = new ErrorMessage(_('Quotes inside CAA value must be escaped with backslash.'));
                        $errorPresenter = new ErrorPresenter();
                        $errorPresenter->present($error);
                    }
                    return false;
                }
            }
        }

        return true;
    }

    /** Check if CDS content is valid
     *
     * Based on PowerDNS CDS implementation (RFC 7344)
     * Format: <keytag> <algorithm> <digesttype> <digest>
     * - keytag: 16-bit integer (0-65535)
     * - algorithm: 8-bit integer (DNSSEC algorithm number)
     * - digesttype: 8-bit integer (digest algorithm type)
     * - digest: hexadecimal digest string
     *
     * CDS is identical to DS (Delegation Signer) but used for signaling child zone delegation.
     *
     * Example: 20642 8 2 04443ABE7E94C3985196BEAE5D548C727B044DDA5151E60D7CD76A9FD931D00E
     *
     * @param string $content CDS content
     * @param boolean $answer print error if true
     * [default=true]
     *
     * @return boolean true if valid, false otherwise
     */
    public static function is_valid_cds(string $content, bool $answer = true): bool
    {
        $content = trim($content);

        // Match: <keytag> <algorithm> <digesttype> <digest>
        if (!preg_match('/^(\d+)\s+(\d+)\s+(\d+)\s+([0-9A-Fa-f]+)$/i', $content, $matches)) {
            if ($answer) {
                $error = new ErrorMessage(_('Invalid CDS record format. Expected: KEYTAG ALGORITHM DIGESTTYPE DIGEST (e.g., 20642 8 2 04443ABE...)'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        $keytag = (int)$matches[1];
        $algorithm = (int)$matches[2];
        $digesttype = (int)$matches[3];
        $digest = $matches[4];

        // Validate keytag (0-65535, uint16)
        if ($keytag < 0 || $keytag > 65535) {
            if ($answer) {
                $error = new ErrorMessage(_('CDS keytag must be between 0 and 65535.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate algorithm (0-255, uint8)
        if ($algorithm < 0 || $algorithm > 255) {
            if ($answer) {
                $error = new ErrorMessage(_('CDS algorithm must be between 0 and 255.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate digesttype (0-255, uint8)
        if ($digesttype < 0 || $digesttype > 255) {
            if ($answer) {
                $error = new ErrorMessage(_('CDS digest type must be between 0 and 255.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate digest is hexadecimal
        if (empty($digest)) {
            if ($answer) {
                $error = new ErrorMessage(_('CDS digest cannot be empty.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Digest length depends on digest type
        // SHA-1 (1): 40 hex chars (20 bytes)
        // SHA-256 (2): 64 hex chars (32 bytes)
        // GOST R 34.11-94 (3): 64 hex chars (32 bytes)
        // SHA-384 (4): 96 hex chars (48 bytes)
        $digest_length = strlen($digest);
        $valid_lengths = [40, 64, 96]; // Common digest lengths

        if (!in_array($digest_length, $valid_lengths)) {
            if ($answer) {
                $error = new ErrorMessage(_('CDS digest has unexpected length. Expected 40 (SHA-1), 64 (SHA-256), or 96 (SHA-384) hex characters.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        return true;
    }

    /** Check if CERT content is valid
     *
     * Based on PowerDNS CERT implementation (RFC 4398)
     * Format: <type> <keytag> <algorithm> <certificate>
     * - type: 16-bit integer (0-65535) - certificate type
     * - keytag: 16-bit integer (0-65535) - key tag
     * - algorithm: 8-bit integer (0-255) - algorithm number
     * - certificate: Base64-encoded certificate or CRL
     *
     * Certificate types (RFC 4398):
     * - 1: PKIX (X.509 as per PKIX)
     * - 2: SPKI (SPKI certificate)
     * - 3: PGP (OpenPGP packet)
     * - 4: IPKIX (The URL of an X.509 data object)
     * - 5: ISPKI (The URL of an SPKI certificate)
     * - 6: IPGP (The fingerprint and URL of an OpenPGP packet)
     * - 7: ACPKIX (Attribute Certificate)
     * - 8: IACPKIX (The URL of an Attribute Certificate)
     * - 253: URI (URI private)
     * - 254: OID (OID private)
     *
     * Example: 1 0 0 MIIB9DCCAV2gAwIBAgIJAKxU...
     *
     * @param string $content CERT content
     * @param boolean $answer print error if true
     * [default=true]
     *
     * @return boolean true if valid, false otherwise
     */
    public static function is_valid_cert(string $content, bool $answer = true): bool
    {
        $content = trim($content);

        // Match: <type> <keytag> <algorithm> <certificate>
        if (!preg_match('/^(\d+)\s+(\d+)\s+(\d+)\s+(.+)$/i', $content, $matches)) {
            if ($answer) {
                $error = new ErrorMessage(_('Invalid CERT record format. Expected: TYPE KEYTAG ALGORITHM CERTIFICATE (e.g., 1 0 0 MIIB9D...)'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        $type = (int)$matches[1];
        $keytag = (int)$matches[2];
        $algorithm = (int)$matches[3];
        $certificate = trim($matches[4]);

        // Validate type (0-65535, uint16)
        if ($type < 0 || $type > 65535) {
            if ($answer) {
                $error = new ErrorMessage(_('CERT type must be between 0 and 65535.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate keytag (0-65535, uint16)
        if ($keytag < 0 || $keytag > 65535) {
            if ($answer) {
                $error = new ErrorMessage(_('CERT keytag must be between 0 and 65535.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate algorithm (0-255, uint8)
        if ($algorithm < 0 || $algorithm > 255) {
            if ($answer) {
                $error = new ErrorMessage(_('CERT algorithm must be between 0 and 255.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate certificate is Base64
        if (empty($certificate)) {
            if ($answer) {
                $error = new ErrorMessage(_('CERT certificate cannot be empty.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Check if certificate is valid Base64
        if (!preg_match('/^[A-Za-z0-9+\/=]+$/', $certificate)) {
            if ($answer) {
                $error = new ErrorMessage(_('CERT certificate must be valid Base64.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Try to decode Base64 to ensure it's valid
        $decoded = base64_decode($certificate, true);
        if ($decoded === false) {
            if ($answer) {
                $error = new ErrorMessage(_('CERT certificate contains invalid Base64 encoding.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        return true;
    }

    /** Check if DNAME content is valid
     *
     * Based on PowerDNS DNAME implementation (RFC 6672)
     * Format: <target>
     * - target: A domain name (FQDN)
     *
     * DNAME provides redirection for a subtree of the domain name tree.
     * It's similar to CNAME but applies to an entire subtree rather than a single name.
     *
     * Examples:
     * - example.com.
     * - target.example.org
     * - sub.domain.test.
     *
     * @param string $content DNAME content
     * @param boolean $answer print error if true
     * [default=true]
     *
     * @return boolean true if valid, false otherwise
     */
    public static function is_valid_dname(string $content, bool $answer = true): bool
    {
        $content = trim($content);

        // DNAME content must not be empty
        if (empty($content)) {
            if ($answer) {
                $error = new ErrorMessage(_('DNAME target cannot be empty.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // DNAME target must be a valid hostname (checked by is_valid_hostname_fqdn in validate_input)
        // Basic validation here: no spaces, valid characters for DNS names
        if (preg_match('/\s/', $content)) {
            if ($answer) {
                $error = new ErrorMessage(_('DNAME target cannot contain spaces.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Check for invalid characters (only alphanumeric, dots, hyphens, and underscores allowed)
        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $content)) {
            if ($answer) {
                $error = new ErrorMessage(_('DNAME target contains invalid characters. Only alphanumeric, dots, hyphens, and underscores are allowed.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        return true;
    }

    /** Check if L32 content is valid
     *
     * Based on PowerDNS L32 implementation (RFC 6742)
     * Format: <preference> <locator32>
     * - preference: 16-bit integer (0-65535)
     * - locator32: 32-bit locator in IPv4 address format
     *
     * L32 is part of the Identifier-Locator Network Protocol (ILNP).
     * The Locator32 value is expressed as an IPv4 address.
     *
     * Example: 513 192.0.2.1
     *
     * @param string $content L32 content
     * @param boolean $answer print error if true
     * [default=true]
     *
     * @return boolean true if valid, false otherwise
     */
    public static function is_valid_l32(string $content, bool $answer = true): bool
    {
        $content = trim($content);

        // Match: <preference> <locator32>
        if (!preg_match('/^(\d+)\s+(\S+)$/i', $content, $matches)) {
            if ($answer) {
                $error = new ErrorMessage(_('Invalid L32 record format. Expected: PREFERENCE LOCATOR32 (e.g., 513 192.0.2.1)'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        $preference = (int)$matches[1];
        $locator32 = trim($matches[2]);

        // Validate preference (0-65535, uint16)
        if ($preference < 0 || $preference > 65535) {
            if ($answer) {
                $error = new ErrorMessage(_('L32 preference must be between 0 and 65535.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate locator32 is a valid IPv4 address
        if (!self::is_valid_ipv4($locator32, false)) {
            if ($answer) {
                $error = new ErrorMessage(_('L32 locator32 must be a valid IPv4 address.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        return true;
    }

    /** Check if L64 content is valid
     *
     * Based on PowerDNS L64 implementation (RFC 6742)
     * Format: <preference> <locator64>
     * - preference: 16-bit integer (0-65535)
     * - locator64: 64-bit locator in IPv6-style format (4 groups of 4 hex digits)
     *
     * L64 is part of the Identifier-Locator Network Protocol (ILNP).
     * The Locator64 value is a 64-bit identifier expressed in IPv6 address notation format.
     *
     * Example: 255 2001:0DB8:1234:ABCD
     *
     * @param string $content L64 content
     * @param boolean $answer print error if true
     * [default=true]
     *
     * @return boolean true if valid, false otherwise
     */
    public static function is_valid_l64(string $content, bool $answer = true): bool
    {
        $content = trim($content);

        // Match: <preference> <locator64>
        if (!preg_match('/^(\d+)\s+(\S+)$/i', $content, $matches)) {
            if ($answer) {
                $error = new ErrorMessage(_('Invalid L64 record format. Expected: PREFERENCE LOCATOR64 (e.g., 255 2001:0DB8:1234:ABCD)'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        $preference = (int)$matches[1];
        $locator64 = trim($matches[2]);

        // Validate preference (0-65535, uint16)
        if ($preference < 0 || $preference > 65535) {
            if ($answer) {
                $error = new ErrorMessage(_('L64 preference must be between 0 and 65535.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate locator64 format: 4 groups of 1-4 hex digits separated by colons
        // Format: XXXX:XXXX:XXXX:XXXX (64-bit identifier)
        if (!preg_match('/^([0-9A-Fa-f]{1,4}):([0-9A-Fa-f]{1,4}):([0-9A-Fa-f]{1,4}):([0-9A-Fa-f]{1,4})$/i', $locator64)) {
            if ($answer) {
                $error = new ErrorMessage(_('L64 locator64 must be in format XXXX:XXXX:XXXX:XXXX (e.g., 2001:0DB8:1234:ABCD).'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        return true;
    }

    /** Check if LUA content is valid
     *
     * Based on PowerDNS LUA implementation
     * Format: <type> <lua_code>
     * - type: DNS record type (e.g., A, AAAA, TXT, etc.)
     * - lua_code: Lua script code for dynamic DNS responses
     *
     * LUA records are a PowerDNS-specific extension that allows dynamic DNS responses
     * using Lua scripting. The record contains a DNS type followed by Lua code.
     *
     * Examples:
     * - A "ifportup(443, {'192.0.2.1', '192.0.2.2'})"
     * - AAAA "ifurlup('https://example.com/', {{'2001:db8::1', '2001:db8::2'}})"
     * - TXT "\"Hello from Lua\""
     *
     * @param string $content LUA content
     * @param boolean $answer print error if true
     * [default=true]
     *
     * @return boolean true if valid, false otherwise
     */
    public static function is_valid_lua(string $content, bool $answer = true): bool
    {
        $content = trim($content);

        // LUA content must not be empty
        if (empty($content)) {
            if ($answer) {
                $error = new ErrorMessage(_('LUA record content cannot be empty.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Match: <type> <lua_code>
        // Type should be a valid DNS record type name (letters only, 1-10 chars is reasonable)
        if (!preg_match('/^([A-Z0-9]+)\s+(.+)$/i', $content, $matches)) {
            if ($answer) {
                $error = new ErrorMessage(_('Invalid LUA record format. Expected: TYPE LUA_CODE (e.g., A "ifportup(443, {\'192.0.2.1\'})")'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        $type = strtoupper(trim($matches[1]));
        $lua_code = trim($matches[2]);

        // Validate the type is a known DNS record type
        $valid_types = [
            'A', 'AAAA', 'AFSDB', 'ALIAS', 'CAA', 'CERT', 'CNAME', 'DNAME',
            'HINFO', 'MX', 'NAPTR', 'NS', 'PTR', 'SOA', 'SRV', 'SSHFP',
            'TLSA', 'TXT', 'SPF'
        ];

        if (!in_array($type, $valid_types)) {
            if ($answer) {
                $error = new ErrorMessage(_('LUA record type must be a valid DNS record type (e.g., A, AAAA, TXT, MX, etc.).'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate Lua code is not empty
        if (empty($lua_code)) {
            if ($answer) {
                $error = new ErrorMessage(_('LUA code cannot be empty.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Basic validation: Lua code should contain valid characters
        // We don't perform full Lua syntax validation as that would require a Lua parser
        // Just ensure it's not completely malformed

        return true;
    }

    /** Check if LP content is valid
     *
     * Based on PowerDNS LP implementation (RFC 6742)
     * Format: <preference> <fqdn>
     * - preference: 16-bit integer (0-65535)
     * - fqdn: Fully Qualified Domain Name
     *
     * LP (Locator Pointer) is part of the Identifier-Locator Network Protocol (ILNP).
     * It provides a mapping from an ILNP Identifier to an FQDN.
     *
     * Example: 512 foo.powerdns.org.
     *
     * @param string $content LP content
     * @param boolean $answer print error if true
     * [default=true]
     *
     * @return boolean true if valid, false otherwise
     */
    public static function is_valid_lp(string $content, bool $answer = true): bool
    {
        $content = trim($content);

        // Match: <preference> <fqdn>
        if (!preg_match('/^(\d+)\s+(\S+)$/i', $content, $matches)) {
            if ($answer) {
                $error = new ErrorMessage(_('Invalid LP record format. Expected: PREFERENCE FQDN (e.g., 512 foo.powerdns.org.)'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        $preference = (int)$matches[1];
        $fqdn = trim($matches[2]);

        // Validate preference (0-65535, uint16)
        if ($preference < 0 || $preference > 65535) {
            if ($answer) {
                $error = new ErrorMessage(_('LP preference must be between 0 and 65535.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate FQDN is not empty
        if (empty($fqdn)) {
            if ($answer) {
                $error = new ErrorMessage(_('LP FQDN cannot be empty.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Basic FQDN validation (detailed validation done by is_valid_hostname_fqdn in validate_input)
        // Check for basic structure: alphanumeric, dots, hyphens, underscores
        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $fqdn)) {
            if ($answer) {
                $error = new ErrorMessage(_('LP FQDN contains invalid characters. Only alphanumeric, dots, hyphens, and underscores are allowed.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        return true;
    }

    /** Check if meta-query type is valid (reject for zone records)
     *
     * MAILA and MAILB are obsolete meta-query types from RFC 883.
     * They are used only in DNS queries (like ANY, AXFR), not in zone files.
     *
     * Meta-query types:
     * - MAILA (254): Mail agent query (obsolete)
     * - MAILB (253): Mailbox-related query (obsolete)
     * - ANY (255): Request for all records
     * - AXFR (252): Zone transfer request
     *
     * These should never appear as actual records in a zone file.
     *
     * @param string $type Record type
     * @param boolean $answer print error if true
     * [default=true]
     *
     * @return boolean false (always - these types are not valid for zone records)
     */
    public static function is_valid_meta_query_type(string $type, bool $answer = true): bool
    {
        if ($answer) {
            $error = new ErrorMessage(sprintf(
                _('%s is a meta-query type and cannot be used as a zone record. It is only used in DNS queries.'),
                strtoupper($type)
            ));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);
        }
        return false;
    }

    /** Check if OPENPGPKEY content is valid
     *
     * Based on PowerDNS OPENPGPKEY implementation (RFC 7929)
     * Format: <base64_encoded_pgp_key>
     * - Base64-encoded PGP public key (keyring)
     *
     * OPENPGPKEY stores PGP public keys in DNS for email encryption (DANE for OpenPGP).
     * The record contains a Base64-encoded PGP public key transferable public key.
     *
     * Example: mQINBFUIXh0BEADNPlL6NpWEaR2KJx6p19scIVpsBIo7UqzCIzeFbRJa...
     *
     * @param string $content OPENPGPKEY content
     * @param boolean $answer print error if true
     * [default=true]
     *
     * @return boolean true if valid, false otherwise
     */
    public static function is_valid_openpgpkey(string $content, bool $answer = true): bool
    {
        $content = trim($content);

        // OPENPGPKEY content must not be empty
        if (empty($content)) {
            if ($answer) {
                $error = new ErrorMessage(_('OPENPGPKEY content cannot be empty.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Check if content is valid Base64
        if (!preg_match('/^[A-Za-z0-9+\/=]+$/', $content)) {
            if ($answer) {
                $error = new ErrorMessage(_('OPENPGPKEY must be valid Base64-encoded data.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Try to decode Base64 to ensure it's valid
        $decoded = base64_decode($content, true);
        if ($decoded === false) {
            if ($answer) {
                $error = new ErrorMessage(_('OPENPGPKEY contains invalid Base64 encoding.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Optional: Check if decoded data looks like a PGP key
        // PGP keys typically start with specific packet headers
        // Packet Tag 6 (Public-Key Packet) typically starts with 0x99 for new format
        // This is optional validation - we don't enforce it strictly

        return true;
    }

    /** Check if SIG content is valid
     *
     * Based on PowerDNS RRSIG implementation (SIG is obsolete, replaced by RRSIG in RFC 4034)
     * Format: <type_covered> <algorithm> <labels> <original_ttl> <sig_expiration> <sig_inception> <key_tag> <signer_name> <signature>
     * - type_covered: DNS record type being signed (e.g., SOA, A, AAAA)
     * - algorithm: 8-bit integer (DNSSEC algorithm number)
     * - labels: 8-bit integer (number of labels in original owner name)
     * - original_ttl: 32-bit integer (original TTL)
     * - sig_expiration: Signature expiration time (YYYYMMDDHHmmss or Unix timestamp)
     * - sig_inception: Signature inception time (YYYYMMDDHHmmss or Unix timestamp)
     * - key_tag: 16-bit integer (0-65535)
     * - signer_name: Domain name of the signer
     * - signature: Base64-encoded signature
     *
     * SIG is obsolete (RFC 2535), replaced by RRSIG (RFC 4034). Same format.
     *
     * Example: SOA 8 3 300 20130523000000 20130509000000 54216 rec.test. ecWKD/OsdAiXpbM/sgPT82KVD...
     *
     * @param string $content SIG content
     * @param boolean $answer print error if true
     * [default=true]
     *
     * @return boolean true if valid, false otherwise
     */
    public static function is_valid_sig(string $content, bool $answer = true): bool
    {
        $content = trim($content);

        // Match: <type_covered> <algorithm> <labels> <original_ttl> <sig_expiration> <sig_inception> <key_tag> <signer_name> <signature>
        if (!preg_match('/^(\S+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\S+)\s+(.+)$/i', $content, $matches)) {
            if ($answer) {
                $error = new ErrorMessage(_('Invalid SIG record format. Expected: TYPE_COVERED ALGORITHM LABELS ORIGINAL_TTL SIG_EXPIRATION SIG_INCEPTION KEY_TAG SIGNER_NAME SIGNATURE'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        $type_covered = strtoupper(trim($matches[1]));
        $algorithm = (int)$matches[2];
        $labels = (int)$matches[3];
        $original_ttl = (int)$matches[4];
        $sig_expiration = $matches[5];
        $sig_inception = $matches[6];
        $key_tag = (int)$matches[7];
        $signer_name = trim($matches[8]);
        $signature = trim($matches[9]);

        // Validate type_covered is a valid DNS record type name
        if (!preg_match('/^[A-Z0-9]+$/i', $type_covered)) {
            if ($answer) {
                $error = new ErrorMessage(_('SIG type covered must be a valid DNS record type.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate algorithm (0-255, uint8)
        if ($algorithm < 0 || $algorithm > 255) {
            if ($answer) {
                $error = new ErrorMessage(_('SIG algorithm must be between 0 and 255.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate labels (0-255, uint8)
        if ($labels < 0 || $labels > 255) {
            if ($answer) {
                $error = new ErrorMessage(_('SIG labels must be between 0 and 255.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate original_ttl (32-bit integer, but we accept any positive integer)
        if ($original_ttl < 0) {
            if ($answer) {
                $error = new ErrorMessage(_('SIG original TTL must be a positive integer.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate key_tag (0-65535, uint16)
        if ($key_tag < 0 || $key_tag > 65535) {
            if ($answer) {
                $error = new ErrorMessage(_('SIG key tag must be between 0 and 65535.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate signer_name is not empty and looks like a domain name
        if (empty($signer_name)) {
            if ($answer) {
                $error = new ErrorMessage(_('SIG signer name cannot be empty.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $signer_name)) {
            if ($answer) {
                $error = new ErrorMessage(_('SIG signer name must be a valid domain name.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate signature is Base64
        if (empty($signature)) {
            if ($answer) {
                $error = new ErrorMessage(_('SIG signature cannot be empty.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        if (!preg_match('/^[A-Za-z0-9+\/=]+$/', $signature)) {
            if ($answer) {
                $error = new ErrorMessage(_('SIG signature must be valid Base64.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        $decoded = base64_decode($signature, true);
        if ($decoded === false) {
            if ($answer) {
                $error = new ErrorMessage(_('SIG signature contains invalid Base64 encoding.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        return true;
    }

    /**
     * Validates DHCID (DHCP Identifier) record content
     *
     * Format: <base64_encoded_data>
     * RFC 4701 - A DNS Resource Record (RR) for Encoding Dynamic Host Configuration Protocol (DHCP) Information
     *
     * The DHCID RR contains a Base64-encoded string representing:
     * - 2 octets: identifier type code
     * - 1 octet: digest type code
     * - n octets: digest (32+ octets for SHA-256)
     *
     * @param string $content The DHCID record content to validate
     * @param bool $answer Whether to show validation errors (default true)
     * @return bool True if valid DHCID format, false otherwise
     */
    public static function is_valid_dhcid(string $content, bool $answer = true): bool
    {
        $content = trim($content);

        if (empty($content)) {
            $error = _('DHCID record content is required');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // DHCID is a Base64-encoded string
        // Minimum length after decoding should be at least 35 bytes:
        // 2 bytes (identifier type) + 1 byte (digest type) + 32 bytes (SHA-256 digest minimum)
        $decoded = base64_decode($content, true);

        if ($decoded === false) {
            $error = _('DHCID record must contain valid Base64-encoded data');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Check minimum length (35 bytes minimum for valid DHCID)
        if (strlen($decoded) < 35) {
            $error = _('DHCID record data is too short (minimum 35 bytes required)');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        return true;
    }

    /**
     * Validates SMIMEA record content
     *
     * Format: <usage> <selector> <matching-type> <certificate-association-data>
     * RFC 8162 - Using Secure DNS to Associate Certificates with Domain Names for S/MIME
     *
     * Fields:
     * - usage: 8-bit integer (0-255) - Certificate usage
     * - selector: 8-bit integer (0-255) - Selector (0=full cert, 1=SubjectPublicKeyInfo)
     * - matching-type: 8-bit integer (0-255) - Matching type (0=exact, 1=SHA-256, 2=SHA-512)
     * - certificate-association-data: Hexadecimal string
     *
     * @param string $content The SMIMEA record content to validate
     * @param bool $answer Whether to show validation errors (default true)
     * @return bool True if valid SMIMEA format, false otherwise
     */
    public static function is_valid_smimea(string $content, bool $answer = true): bool
    {
        $content = trim($content);

        if (empty($content)) {
            $error = _('SMIMEA record content is required');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        $fields = preg_split('/\s+/', $content);

        if (count($fields) < 4) {
            $error = _('SMIMEA record must have at least 4 fields: usage, selector, matching-type, and certificate data');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        $usage = $fields[0];
        $selector = $fields[1];
        $matchingType = $fields[2];
        $certData = implode('', array_slice($fields, 3)); // Certificate data may be split across multiple fields

        // Validate usage (0-255)
        if (!is_numeric($usage) || $usage < 0 || $usage > 255 || $usage != (int)$usage) {
            $error = _('SMIMEA usage must be an integer between 0 and 255');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate selector (0-255)
        if (!is_numeric($selector) || $selector < 0 || $selector > 255 || $selector != (int)$selector) {
            $error = _('SMIMEA selector must be an integer between 0 and 255');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate matching type (0-255)
        if (!is_numeric($matchingType) || $matchingType < 0 || $matchingType > 255 || $matchingType != (int)$matchingType) {
            $error = _('SMIMEA matching type must be an integer between 0 and 255');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate certificate data (must be hexadecimal)
        if (empty($certData)) {
            $error = _('SMIMEA certificate data is required');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        if (!ctype_xdigit($certData)) {
            $error = _('SMIMEA certificate data must be a valid hexadecimal string');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate minimum certificate data length (at least 1 byte = 2 hex chars)
        if (strlen($certData) < 2) {
            $error = _('SMIMEA certificate data must be at least 2 hexadecimal characters (1 byte)');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate certificate data length must be even (each byte = 2 hex chars)
        if (strlen($certData) % 2 !== 0) {
            $error = _('SMIMEA certificate data must have an even number of hexadecimal characters');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        return true;
    }

    /**
     * Validates TKEY (Transaction Key) record content
     *
     * Format: <algorithm> <inception> <expiration> <mode> <error> <keysize> <keydata> <othersize> <otherdata>
     * RFC 2930 - Secret Key Establishment for DNS (TKEY RR)
     *
     * Fields:
     * - algorithm: Domain name (e.g., gss-tsig., HMAC-MD5.SIG-ALG.REG.INT.)
     * - inception: 32-bit unsigned integer (time in seconds since epoch)
     * - expiration: 32-bit unsigned integer (time in seconds since epoch)
     * - mode: 16-bit unsigned integer (0-65535) - Key agreement mode
     * - error: 16-bit unsigned integer (0-65535) - Error code
     * - keysize: 16-bit unsigned integer (0-65535) - Size of key data in bytes
     * - keydata: Base64-encoded key data (if keysize > 0)
     * - othersize: 16-bit unsigned integer (0-65535) - Size of other data in bytes
     * - otherdata: Base64-encoded other data (if othersize > 0)
     *
     * @param string $content The TKEY record content to validate
     * @param bool $answer Whether to show validation errors (default true)
     * @return bool True if valid TKEY format, false otherwise
     */
    public static function is_valid_tkey(string $content, bool $answer = true): bool
    {
        $content = trim($content);

        if (empty($content)) {
            $error = _('TKEY record content is required');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        $fields = preg_split('/\s+/', $content);

        // Minimum 6 fields: algorithm, inception, expiration, mode, error, keysize
        // Plus optionally: keydata (if keysize > 0), othersize, otherdata (if othersize > 0)
        if (count($fields) < 6) {
            $error = _('TKEY record must have at least 6 fields: algorithm, inception, expiration, mode, error, and keysize');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        $algorithm = $fields[0];
        $inception = $fields[1];
        $expiration = $fields[2];
        $mode = $fields[3];
        $error_code = $fields[4];
        $keysize = $fields[5];

        // Validate algorithm (domain name format)
        if (empty($algorithm) || !preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-_.]*[a-zA-Z0-9])?\.?$/', $algorithm)) {
            $error = _('TKEY algorithm must be a valid domain name');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate inception (32-bit unsigned integer)
        if (!is_numeric($inception) || $inception < 0 || $inception > 4294967295 || $inception != (int)$inception) {
            $error = _('TKEY inception must be a 32-bit unsigned integer (0-4294967295)');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate expiration (32-bit unsigned integer)
        if (!is_numeric($expiration) || $expiration < 0 || $expiration > 4294967295 || $expiration != (int)$expiration) {
            $error = _('TKEY expiration must be a 32-bit unsigned integer (0-4294967295)');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate mode (16-bit unsigned integer)
        if (!is_numeric($mode) || $mode < 0 || $mode > 65535 || $mode != (int)$mode) {
            $error = _('TKEY mode must be a 16-bit unsigned integer (0-65535)');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate error code (16-bit unsigned integer)
        if (!is_numeric($error_code) || $error_code < 0 || $error_code > 65535 || $error_code != (int)$error_code) {
            $error = _('TKEY error must be a 16-bit unsigned integer (0-65535)');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate keysize (16-bit unsigned integer)
        if (!is_numeric($keysize) || $keysize < 0 || $keysize > 65535 || $keysize != (int)$keysize) {
            $error = _('TKEY keysize must be a 16-bit unsigned integer (0-65535)');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        $keysize_int = (int)$keysize;
        $current_field = 6;

        // If keysize > 0, expect keydata
        if ($keysize_int > 0) {
            if (!isset($fields[$current_field])) {
                $error = _('TKEY keydata is required when keysize > 0');
                if ($answer) {
                    $errorPresenter = new ErrorPresenter();
                    $errorPresenter->present($error);
                }
                return false;
            }

            $keydata = $fields[$current_field];

            // Validate keydata is Base64
            if (base64_decode($keydata, true) === false) {
                $error = _('TKEY keydata must be valid Base64-encoded data');
                if ($answer) {
                    $errorPresenter = new ErrorPresenter();
                    $errorPresenter->present($error);
                }
                return false;
            }

            $current_field++;
        }

        // Expect othersize field
        if (!isset($fields[$current_field])) {
            $error = _('TKEY othersize field is required');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        $othersize = $fields[$current_field];

        // Validate othersize (16-bit unsigned integer)
        if (!is_numeric($othersize) || $othersize < 0 || $othersize > 65535 || $othersize != (int)$othersize) {
            $error = _('TKEY othersize must be a 16-bit unsigned integer (0-65535)');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        $othersize_int = (int)$othersize;
        $current_field++;

        // If othersize > 0, expect otherdata
        if ($othersize_int > 0) {
            if (!isset($fields[$current_field])) {
                $error = _('TKEY otherdata is required when othersize > 0');
                if ($answer) {
                    $errorPresenter = new ErrorPresenter();
                    $errorPresenter->present($error);
                }
                return false;
            }

            $otherdata = $fields[$current_field];

            // Validate otherdata is Base64
            if (base64_decode($otherdata, true) === false) {
                $error = _('TKEY otherdata must be valid Base64-encoded data');
                if ($answer) {
                    $errorPresenter = new ErrorPresenter();
                    $errorPresenter->present($error);
                }
                return false;
            }
        }

        return true;
    }

    /**
     * Validates URI record content
     *
     * Format: <priority> <weight> "<target>"
     * RFC 7553 - The Uniform Resource Identifier (URI) DNS Resource Record
     *
     * Fields:
     * - priority: 16-bit unsigned integer (0-65535) - Priority of the target URI
     * - weight: 16-bit unsigned integer (0-65535) - Weight for records with the same priority
     * - target: URI string enclosed in double quotes
     *
     * @param string $content The URI record content to validate
     * @param bool $answer Whether to show validation errors (default true)
     * @return bool True if valid URI format, false otherwise
     */
    public static function is_valid_uri(string $content, bool $answer = true): bool
    {
        $content = trim($content);

        if (empty($content)) {
            $error = _('URI record content is required');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // URI format: <priority> <weight> "<target>"
        // The target must be in quotes
        if (!preg_match('/^(\d+)\s+(\d+)\s+"(.+)"$/', $content, $matches)) {
            $error = _('URI record must have format: <priority> <weight> "<target>" (target must be in quotes)');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        $priority = $matches[1];
        $weight = $matches[2];
        $target = $matches[3];

        // Validate priority (16-bit unsigned integer)
        if (!is_numeric($priority) || $priority < 0 || $priority > 65535 || $priority != (int)$priority) {
            $error = _('URI priority must be a 16-bit unsigned integer (0-65535)');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate weight (16-bit unsigned integer)
        if (!is_numeric($weight) || $weight < 0 || $weight > 65535 || $weight != (int)$weight) {
            $error = _('URI weight must be a 16-bit unsigned integer (0-65535)');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate target (must not be empty and should be a valid URI)
        if (empty($target)) {
            $error = _('URI target cannot be empty');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Basic URI validation - must contain scheme and colon
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:/', $target)) {
            $error = _('URI target must be a valid URI with a scheme (e.g., http://, ftp://, mailto:)');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        return true;
    }

    /**
     * Validates TLSA record content
     *
     * Format: <usage> <selector> <matching-type> <certificate-association-data>
     * RFC 6698 - The DNS-Based Authentication of Named Entities (DANE) Transport Layer Security (TLS) Protocol: TLSA
     *
     * TLSA has the exact same structure as SMIMEA
     *
     * @param string $content The TLSA record content to validate
     * @param bool $answer Whether to show validation errors (default true)
     * @return bool True if valid TLSA format, false otherwise
     */
    public static function is_valid_tlsa(string $content, bool $answer = true): bool
    {
        // TLSA uses the exact same format as SMIMEA
        return self::is_valid_smimea($content, $answer);
    }

    /**
     * Validates SSHFP (SSH Fingerprint) record content
     *
     * Format: <algorithm> <fptype> <fingerprint>
     * RFC 4255 - Using DNS to Securely Publish Secure Shell (SSH) Key Fingerprints
     *
     * Fields:
     * - algorithm: 8-bit integer (0-255) - Public key algorithm (1=RSA, 2=DSS, 3=ECDSA, 4=Ed25519)
     * - fptype: 8-bit integer (0-255) - Fingerprint type (1=SHA-1, 2=SHA-256)
     * - fingerprint: Hexadecimal string
     *
     * @param string $content The SSHFP record content to validate
     * @param bool $answer Whether to show validation errors (default true)
     * @return bool True if valid SSHFP format, false otherwise
     */
    public static function is_valid_sshfp(string $content, bool $answer = true): bool
    {
        $content = trim($content);

        if (empty($content)) {
            $error = _('SSHFP record content is required');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        $fields = preg_split('/\s+/', $content);

        if (count($fields) < 3) {
            $error = _('SSHFP record must have 3 fields: algorithm, fptype, and fingerprint');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        $algorithm = $fields[0];
        $fptype = $fields[1];
        $fingerprint = implode('', array_slice($fields, 2)); // Fingerprint may be split across multiple fields

        // Validate algorithm (8-bit integer)
        if (!is_numeric($algorithm) || $algorithm < 0 || $algorithm > 255 || $algorithm != (int)$algorithm) {
            $error = _('SSHFP algorithm must be an 8-bit integer (0-255)');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate fptype (8-bit integer)
        if (!is_numeric($fptype) || $fptype < 0 || $fptype > 255 || $fptype != (int)$fptype) {
            $error = _('SSHFP fingerprint type must be an 8-bit integer (0-255)');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate fingerprint (hexadecimal)
        if (empty($fingerprint)) {
            $error = _('SSHFP fingerprint is required');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        if (!ctype_xdigit($fingerprint)) {
            $error = _('SSHFP fingerprint must be a valid hexadecimal string');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate fingerprint length is even (each byte = 2 hex chars)
        if (strlen($fingerprint) % 2 !== 0) {
            $error = _('SSHFP fingerprint must have an even number of hexadecimal characters');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        return true;
    }

    /**
     * Validates NAPTR (Naming Authority Pointer) record content
     *
     * Format: <order> <preference> "<flags>" "<services>" "<regexp>" <replacement>
     * RFC 3403 - Dynamic Delegation Discovery System (DDDS)
     *
     * @param string $content The NAPTR record content to validate
     * @param bool $answer Whether to show validation errors (default true)
     * @return bool True if valid NAPTR format, false otherwise
     */
    public static function is_valid_naptr(string $content, bool $answer = true): bool
    {
        $content = trim($content);

        if (empty($content)) {
            $error = _('NAPTR record content is required');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // NAPTR format: <order> <preference> "<flags>" "<services>" "<regexp>" <replacement>
        if (!preg_match('/^(\d+)\s+(\d+)\s+"([^"]*)"\s+"([^"]*)"\s+"([^"]*)"\s+(.+)$/', $content, $matches)) {
            $error = _('NAPTR record must have format: <order> <preference> "<flags>" "<services>" "<regexp>" <replacement>');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        $order = $matches[1];
        $preference = $matches[2];
        $flags = $matches[3];
        $services = $matches[4];
        $regexp = $matches[5];
        $replacement = $matches[6];

        // Validate order (16-bit unsigned integer)
        if (!is_numeric($order) || $order < 0 || $order > 65535 || $order != (int)$order) {
            $error = _('NAPTR order must be a 16-bit unsigned integer (0-65535)');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate preference (16-bit unsigned integer)
        if (!is_numeric($preference) || $preference < 0 || $preference > 65535 || $preference != (int)$preference) {
            $error = _('NAPTR preference must be a 16-bit unsigned integer (0-65535)');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate replacement (domain name or ".")
        // Allow underscores for service names like _http._tcp.example.com
        if ($replacement !== '.' && !preg_match('/^[a-zA-Z0-9_]([a-zA-Z0-9\-_.]*[a-zA-Z0-9])?\.?$/', $replacement)) {
            $error = _('NAPTR replacement must be a valid domain name or "."');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        return true;
    }

    /**
     * Validates RP (Responsible Person) record content
     *
     * Format: <mbox-dname> <txt-dname>
     * RFC 1183 - New DNS RR Definitions
     *
     * @param string $content The RP record content to validate
     * @param bool $answer Whether to show validation errors (default true)
     * @return bool True if valid RP format, false otherwise
     */
    public static function is_valid_rp(string $content, bool $answer = true): bool
    {
        $content = trim($content);

        if (empty($content)) {
            $error = _('RP record content is required');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        $fields = preg_split('/\s+/', $content);

        if (count($fields) < 2) {
            $error = _('RP record must have 2 fields: mbox-dname and txt-dname');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        $mbox = $fields[0];
        $txt = $fields[1];

        // Validate mbox-dname (mailbox domain name)
        if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-_.]*[a-zA-Z0-9])?\.?$/', $mbox)) {
            $error = _('RP mbox-dname must be a valid domain name');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate txt-dname (domain name pointing to TXT record)
        if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-_.]*[a-zA-Z0-9])?\.?$/', $txt)) {
            $error = _('RP txt-dname must be a valid domain name');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        return true;
    }

    /**
     * Validates DNSKEY record content
     *
     * Format: <flags> <protocol> <algorithm> <public-key>
     * RFC 4034 - Resource Records for the DNS Security Extensions
     *
     * @param string $content The DNSKEY record content to validate
     * @param bool $answer Whether to show validation errors (default true)
     * @return bool True if valid DNSKEY format, false otherwise
     */
    public static function is_valid_dnskey(string $content, bool $answer = true): bool
    {
        $content = trim($content);

        if (empty($content)) {
            $error = _('DNSKEY record content is required');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        $fields = preg_split('/\s+/', $content);

        if (count($fields) < 4) {
            $error = _('DNSKEY record must have at least 4 fields: flags, protocol, algorithm, and public key');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        $flags = $fields[0];
        $protocol = $fields[1];
        $algorithm = $fields[2];
        $publicKey = implode('', array_slice($fields, 3)); // Public key may be split across multiple fields

        // Validate flags (16-bit unsigned integer)
        if (!is_numeric($flags) || $flags < 0 || $flags > 65535 || $flags != (int)$flags) {
            $error = _('DNSKEY flags must be a 16-bit unsigned integer (0-65535)');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate protocol (8-bit unsigned integer, must be 3)
        if (!is_numeric($protocol) || $protocol != 3) {
            $error = _('DNSKEY protocol must be 3');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate algorithm (8-bit unsigned integer)
        if (!is_numeric($algorithm) || $algorithm < 0 || $algorithm > 255 || $algorithm != (int)$algorithm) {
            $error = _('DNSKEY algorithm must be an 8-bit unsigned integer (0-255)');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate public key (Base64)
        if (empty($publicKey)) {
            $error = _('DNSKEY public key is required');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        if (base64_decode($publicKey, true) === false) {
            $error = _('DNSKEY public key must be valid Base64-encoded data');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        return true;
    }

    /**
     * Validates NSEC record content
     *
     * Format: <next-domain> <type-list>
     * RFC 4034 - Resource Records for the DNS Security Extensions
     *
     * @param string $content The NSEC record content to validate
     * @param bool $answer Whether to show validation errors (default true)
     * @return bool True if valid NSEC format, false otherwise
     */
    public static function is_valid_nsec(string $content, bool $answer = true): bool
    {
        $content = trim($content);

        if (empty($content)) {
            $error = _('NSEC record content is required');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        $fields = preg_split('/\s+/', $content);

        if (count($fields) < 2) {
            $error = _('NSEC record must have at least 2 fields: next domain name and type list');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        $nextDomain = $fields[0];

        // Validate next domain name (allow underscores for service names)
        if (!preg_match('/^[a-zA-Z0-9_]([a-zA-Z0-9\-_.]*[a-zA-Z0-9])?\.?$/', $nextDomain)) {
            $error = _('NSEC next domain must be a valid domain name');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Type list validation - at least one type required
        // Types are DNS record type names (A, AAAA, MX, etc.)
        for ($i = 1; $i < count($fields); $i++) {
            $type = $fields[$i];
            if (!preg_match('/^[A-Z0-9]+$/', $type)) {
                $error = _('NSEC type list must contain valid DNS record type names');
                if ($answer) {
                    $errorPresenter = new ErrorPresenter();
                    $errorPresenter->present($error);
                }
                return false;
            }
        }

        return true;
    }

    /**
     * Validates NSEC3 record content
     *
     * Format: <algorithm> <flags> <iterations> <salt> <next-hash> <type-list>
     * RFC 5155 - DNS Security (DNSSEC) Hashed Authenticated Denial of Existence
     *
     * @param string $content The NSEC3 record content to validate
     * @param bool $answer Whether to show validation errors (default true)
     * @return bool True if valid NSEC3 format, false otherwise
     */
    public static function is_valid_nsec3(string $content, bool $answer = true): bool
    {
        $content = trim($content);

        if (empty($content)) {
            $error = _('NSEC3 record content is required');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        $fields = preg_split('/\s+/', $content);

        if (count($fields) < 5) {
            $error = _('NSEC3 record must have at least 5 fields: algorithm, flags, iterations, salt, and next hash');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        $algorithm = $fields[0];
        $flags = $fields[1];
        $iterations = $fields[2];
        $salt = $fields[3];
        $nextHash = $fields[4];

        // Validate algorithm (8-bit unsigned integer)
        if (!is_numeric($algorithm) || $algorithm < 0 || $algorithm > 255 || $algorithm != (int)$algorithm) {
            $error = _('NSEC3 algorithm must be an 8-bit unsigned integer (0-255)');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate flags (8-bit unsigned integer)
        if (!is_numeric($flags) || $flags < 0 || $flags > 255 || $flags != (int)$flags) {
            $error = _('NSEC3 flags must be an 8-bit unsigned integer (0-255)');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate iterations (16-bit unsigned integer)
        if (!is_numeric($iterations) || $iterations < 0 || $iterations > 65535 || $iterations != (int)$iterations) {
            $error = _('NSEC3 iterations must be a 16-bit unsigned integer (0-65535)');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate salt (hex or "-" for no salt)
        if ($salt !== '-' && !ctype_xdigit($salt)) {
            $error = _('NSEC3 salt must be hexadecimal or "-" for no salt');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate next hash (Base32hex encoded - RFC 4648)
        if (!preg_match('/^[0-9A-Va-v]+$/', $nextHash)) {
            $error = _('NSEC3 next hash must be Base32hex-encoded (0-9, A-V)');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate type list (optional, but if present must be uppercase)
        if (count($fields) > 5) {
            $typeList = array_slice($fields, 5);
            foreach ($typeList as $type) {
                if ($type !== strtoupper($type)) {
                    $error = _('NSEC3 type list must contain uppercase type names');
                    if ($answer) {
                        $errorPresenter = new ErrorPresenter();
                        $errorPresenter->present($error);
                    }
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Validates NSEC3PARAM record content
     *
     * Format: <algorithm> <flags> <iterations> <salt>
     * RFC 5155 - DNS Security (DNSSEC) Hashed Authenticated Denial of Existence
     *
     * @param string $content The NSEC3PARAM record content to validate
     * @param bool $answer Whether to show validation errors (default true)
     * @return bool True if valid NSEC3PARAM format, false otherwise
     */
    public static function is_valid_nsec3param(string $content, bool $answer = true): bool
    {
        $content = trim($content);

        if (empty($content)) {
            $error = _('NSEC3PARAM record content is required');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        $fields = preg_split('/\s+/', $content);

        if (count($fields) < 4) {
            $error = _('NSEC3PARAM record must have 4 fields: algorithm, flags, iterations, and salt');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        $algorithm = $fields[0];
        $flags = $fields[1];
        $iterations = $fields[2];
        $salt = $fields[3];

        // Validate algorithm (8-bit unsigned integer)
        if (!is_numeric($algorithm) || $algorithm < 0 || $algorithm > 255 || $algorithm != (int)$algorithm) {
            $error = _('NSEC3PARAM algorithm must be an 8-bit unsigned integer (0-255)');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate flags (8-bit unsigned integer)
        if (!is_numeric($flags) || $flags < 0 || $flags > 255 || $flags != (int)$flags) {
            $error = _('NSEC3PARAM flags must be an 8-bit unsigned integer (0-255)');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate iterations (16-bit unsigned integer)
        if (!is_numeric($iterations) || $iterations < 0 || $iterations > 65535 || $iterations != (int)$iterations) {
            $error = _('NSEC3PARAM iterations must be a 16-bit unsigned integer (0-65535)');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate salt (hex or "-" for no salt)
        if ($salt !== '-' && !ctype_xdigit($salt)) {
            $error = _('NSEC3PARAM salt must be hexadecimal or "-" for no salt');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        return true;
    }

    /**
     * Validates RRSIG record content
     *
     * Format: <type-covered> <algorithm> <labels> <original-ttl> <signature-expiration> <signature-inception> <key-tag> <signer-name> <signature>
     * RFC 4034 - Resource Records for the DNS Security Extensions
     *
     * RRSIG has the exact same structure as SIG (which we already implemented)
     *
     * @param string $content The RRSIG record content to validate
     * @param bool $answer Whether to show validation errors (default true)
     * @return bool True if valid RRSIG format, false otherwise
     */
    public static function is_valid_rrsig(string $content, bool $answer = true): bool
    {
        // RRSIG uses the exact same format as SIG
        return self::is_valid_sig($content, $answer);
    }

    /**
     * Validates TSIG record content
     *
     * Format: <algorithm> <time-signed> <fudge> <mac-size> <mac> <original-id> <error> <other-len> [<other-data>]
     * RFC 2845 - Secret Key Transaction Authentication for DNS (TSIG)
     *
     * @param string $content The TSIG record content to validate
     * @param bool $answer Whether to show validation errors (default true)
     * @return bool True if valid TSIG format, false otherwise
     */
    public static function is_valid_tsig(string $content, bool $answer = true): bool
    {
        $content = trim($content);

        if (empty($content)) {
            $error = _('TSIG record content is required');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        $fields = preg_split('/\s+/', $content);

        // Minimum 8 fields required (other-data is optional if other-len is 0)
        if (count($fields) < 8) {
            $error = _('TSIG record must have at least 8 fields: algorithm, time-signed, fudge, mac-size, mac, original-id, error, and other-len');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        $algorithm = $fields[0];
        $timeSigned = $fields[1];
        $fudge = $fields[2];
        $macSize = $fields[3];
        $mac = $fields[4];
        $originalId = $fields[5];
        $error_code = $fields[6];
        $otherLen = $fields[7];

        // Validate algorithm (domain name format)
        if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-_.]*[a-zA-Z0-9])?\.?$/', $algorithm)) {
            $error = _('TSIG algorithm must be a valid domain name');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate time-signed (48-bit timestamp - can be large)
        if (!is_numeric($timeSigned) || $timeSigned < 0) {
            $error = _('TSIG time-signed must be a non-negative integer');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate fudge (16-bit unsigned integer)
        if (!is_numeric($fudge) || $fudge < 0 || $fudge > 65535 || $fudge != (int)$fudge) {
            $error = _('TSIG fudge must be a 16-bit unsigned integer (0-65535)');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate mac-size (16-bit unsigned integer)
        if (!is_numeric($macSize) || $macSize < 0 || $macSize > 65535 || $macSize != (int)$macSize) {
            $error = _('TSIG mac-size must be a 16-bit unsigned integer (0-65535)');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate MAC (Base64)
        if ((int)$macSize > 0 && base64_decode($mac, true) === false) {
            $error = _('TSIG MAC must be valid Base64-encoded data');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate original-id (16-bit unsigned integer)
        if (!is_numeric($originalId) || $originalId < 0 || $originalId > 65535 || $originalId != (int)$originalId) {
            $error = _('TSIG original-id must be a 16-bit unsigned integer (0-65535)');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate error (16-bit unsigned integer)
        if (!is_numeric($error_code) || $error_code < 0 || $error_code > 65535 || $error_code != (int)$error_code) {
            $error = _('TSIG error must be a 16-bit unsigned integer (0-65535)');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate other-len (16-bit unsigned integer)
        if (!is_numeric($otherLen) || $otherLen < 0 || $otherLen > 65535 || $otherLen != (int)$otherLen) {
            $error = _('TSIG other-len must be a 16-bit unsigned integer (0-65535)');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // If other-len > 0, validate other-data (Base64)
        if ((int)$otherLen > 0) {
            if (!isset($fields[8])) {
                $error = _('TSIG other-data is required when other-len > 0');
                if ($answer) {
                    $errorPresenter = new ErrorPresenter();
                    $errorPresenter->present($error);
                }
                return false;
            }

            $otherData = $fields[8];
            if (base64_decode($otherData, true) === false) {
                $error = _('TSIG other-data must be valid Base64-encoded data');
                if ($answer) {
                    $errorPresenter = new ErrorPresenter();
                    $errorPresenter->present($error);
                }
                return false;
            }
        }

        return true;
    }

    /**
     * Validates EUI48 record content
     * Format: 6 octets separated by dashes (MAC-48 address)
     * RFC 7043
     *
     * @param string $content The record content
     * @param bool $answer Whether to present errors
     * @return bool True if valid, false otherwise
     */
    public static function is_valid_eui48(string $content, bool $answer = true): bool
    {
        $content = trim($content);

        if (empty($content)) {
            $error = _('EUI48 record content is required');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // EUI48 format: 6 octets separated by dashes (e.g., 00-11-22-33-44-55)
        if (!preg_match('/^([0-9A-Fa-f]{2}-){5}[0-9A-Fa-f]{2}$/', $content)) {
            $error = _('EUI48 must be 6 octets separated by dashes (e.g., 00-11-22-33-44-55)');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        return true;
    }

    /**
     * Validates EUI64 record content
     * Format: 8 octets separated by dashes (EUI-64 address)
     * RFC 7043
     *
     * @param string $content The record content
     * @param bool $answer Whether to present errors
     * @return bool True if valid, false otherwise
     */
    public static function is_valid_eui64(string $content, bool $answer = true): bool
    {
        $content = trim($content);

        if (empty($content)) {
            $error = _('EUI64 record content is required');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // EUI64 format: 8 octets separated by dashes (e.g., 00-11-22-33-44-55-66-77)
        if (!preg_match('/^([0-9A-Fa-f]{2}-){7}[0-9A-Fa-f]{2}$/', $content)) {
            $error = _('EUI64 must be 8 octets separated by dashes (e.g., 00-11-22-33-44-55-66-77)');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        return true;
    }

    /**
     * Validates NID record content
     * Format: preference node-id (64-bit identifier in colon-hex format)
     * RFC 6742
     *
     * @param string $content The record content
     * @param bool $answer Whether to present errors
     * @return bool True if valid, false otherwise
     */
    public static function is_valid_nid(string $content, bool $answer = true): bool
    {
        $content = trim($content);

        if (empty($content)) {
            $error = _('NID record content is required');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        $fields = preg_split('/\s+/', $content);

        if (count($fields) != 2) {
            $error = _('NID record must have 2 fields: preference and node-id');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        $preference = $fields[0];
        $nodeId = $fields[1];

        // Validate preference (16-bit unsigned integer)
        if (!is_numeric($preference) || $preference < 0 || $preference > 65535 || $preference != (int)$preference) {
            $error = _('NID preference must be a 16-bit unsigned integer (0-65535)');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate node-id (64-bit in format XXXX:XXXX:XXXX:XXXX)
        if (!preg_match('/^([0-9A-Fa-f]{4}:){3}[0-9A-Fa-f]{4}$/', $nodeId)) {
            $error = _('NID node-id must be in format XXXX:XXXX:XXXX:XXXX (64-bit hex with colons)');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        return true;
    }

    /**
     * Validates KX record content
     * Format: preference exchanger (like MX but for key exchange)
     * RFC 2230
     *
     * @param string $content The record content
     * @param bool $answer Whether to present errors
     * @return bool True if valid, false otherwise
     */
    public static function is_valid_kx(string $content, bool $answer = true): bool
    {
        $content = trim($content);

        if (empty($content)) {
            $error = _('KX record content is required');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        $fields = preg_split('/\s+/', $content);

        if (count($fields) != 2) {
            $error = _('KX record must have 2 fields: preference and exchanger');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        $preference = $fields[0];
        $exchanger = $fields[1];

        // Validate preference (16-bit unsigned integer)
        if (!is_numeric($preference) || $preference < 0 || $preference > 65535 || $preference != (int)$preference) {
            $error = _('KX preference must be a 16-bit unsigned integer (0-65535)');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate exchanger (domain name)
        // Each label must start with alphanumeric, end with alphanumeric, and can contain hyphens in the middle
        if (!preg_match('/^([a-zA-Z0-9_]([a-zA-Z0-9\-]*[a-zA-Z0-9])?\.)*[a-zA-Z0-9_]([a-zA-Z0-9\-]*[a-zA-Z0-9])?\.?$/', $exchanger)) {
            $error = _('KX exchanger must be a valid domain name');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        return true;
    }

    /**
     * Validates IPSECKEY record content
     * Format: precedence gateway-type algorithm [gateway] [public-key]
     * RFC 4025
     *
     * @param string $content The record content
     * @param bool $answer Whether to present errors
     * @return bool True if valid, false otherwise
     */
    public static function is_valid_ipseckey(string $content, bool $answer = true): bool
    {
        $content = trim($content);

        if (empty($content)) {
            $error = _('IPSECKEY record content is required');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        $fields = preg_split('/\s+/', $content);

        if (count($fields) < 3) {
            $error = _('IPSECKEY record must have at least 3 fields: precedence, gateway-type, and algorithm');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        $precedence = $fields[0];
        $gatewayType = $fields[1];
        $algorithm = $fields[2];

        // Validate precedence (8-bit unsigned integer)
        if (!is_numeric($precedence) || $precedence < 0 || $precedence > 255 || $precedence != (int)$precedence) {
            $error = _('IPSECKEY precedence must be an 8-bit unsigned integer (0-255)');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate gateway-type (8-bit unsigned integer, 0-3)
        if (!is_numeric($gatewayType) || $gatewayType < 0 || $gatewayType > 3 || $gatewayType != (int)$gatewayType) {
            $error = _('IPSECKEY gateway-type must be 0-3 (0=none, 1=IPv4, 2=IPv6, 3=domain)');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate algorithm (8-bit unsigned integer)
        if (!is_numeric($algorithm) || $algorithm < 0 || $algorithm > 255 || $algorithm != (int)$algorithm) {
            $error = _('IPSECKEY algorithm must be an 8-bit unsigned integer (0-255)');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate gateway based on gateway-type
        $gatewayTypeInt = (int)$gatewayType;

        if ($gatewayTypeInt == 0) {
            // No gateway, may have public key
            if (count($fields) > 3) {
                // Has public key - validate Base64
                $publicKey = $fields[3];
                if (!preg_match('/^[A-Za-z0-9+\/]+=*$/', $publicKey)) {
                    $error = _('IPSECKEY public key must be Base64-encoded');
                    if ($answer) {
                        $errorPresenter = new ErrorPresenter();
                        $errorPresenter->present($error);
                    }
                    return false;
                }
            }
        } elseif ($gatewayTypeInt == 1) {
            // IPv4 gateway
            if (count($fields) < 4) {
                $error = _('IPSECKEY with gateway-type 1 requires an IPv4 address');
                if ($answer) {
                    $errorPresenter = new ErrorPresenter();
                    $errorPresenter->present($error);
                }
                return false;
            }
            $gateway = $fields[3];
            if (!filter_var($gateway, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $error = _('IPSECKEY gateway must be a valid IPv4 address');
                if ($answer) {
                    $errorPresenter = new ErrorPresenter();
                    $errorPresenter->present($error);
                }
                return false;
            }
            // Validate public key if present
            if (count($fields) > 4) {
                $publicKey = $fields[4];
                if (!preg_match('/^[A-Za-z0-9+\/]+=*$/', $publicKey)) {
                    $error = _('IPSECKEY public key must be Base64-encoded');
                    if ($answer) {
                        $errorPresenter = new ErrorPresenter();
                        $errorPresenter->present($error);
                    }
                    return false;
                }
            }
        } elseif ($gatewayTypeInt == 2) {
            // IPv6 gateway
            if (count($fields) < 4) {
                $error = _('IPSECKEY with gateway-type 2 requires an IPv6 address');
                if ($answer) {
                    $errorPresenter = new ErrorPresenter();
                    $errorPresenter->present($error);
                }
                return false;
            }
            $gateway = $fields[3];
            if (!filter_var($gateway, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $error = _('IPSECKEY gateway must be a valid IPv6 address');
                if ($answer) {
                    $errorPresenter = new ErrorPresenter();
                    $errorPresenter->present($error);
                }
                return false;
            }
            // Validate public key if present
            if (count($fields) > 4) {
                $publicKey = $fields[4];
                if (!preg_match('/^[A-Za-z0-9+\/]+=*$/', $publicKey)) {
                    $error = _('IPSECKEY public key must be Base64-encoded');
                    if ($answer) {
                        $errorPresenter = new ErrorPresenter();
                        $errorPresenter->present($error);
                    }
                    return false;
                }
            }
        } elseif ($gatewayTypeInt == 3) {
            // Domain gateway
            if (count($fields) < 4) {
                $error = _('IPSECKEY with gateway-type 3 requires a domain name');
                if ($answer) {
                    $errorPresenter = new ErrorPresenter();
                    $errorPresenter->present($error);
                }
                return false;
            }
            $gateway = $fields[3];
            // Each label must start with alphanumeric, end with alphanumeric, and can contain hyphens in the middle
            if (!preg_match('/^([a-zA-Z0-9_]([a-zA-Z0-9\-]*[a-zA-Z0-9])?\.)*[a-zA-Z0-9_]([a-zA-Z0-9\-]*[a-zA-Z0-9])?\.?$/', $gateway)) {
                $error = _('IPSECKEY gateway must be a valid domain name');
                if ($answer) {
                    $errorPresenter = new ErrorPresenter();
                    $errorPresenter->present($error);
                }
                return false;
            }
            // Validate public key if present
            if (count($fields) > 4) {
                $publicKey = $fields[4];
                if (!preg_match('/^[A-Za-z0-9+\/]+=*$/', $publicKey)) {
                    $error = _('IPSECKEY public key must be Base64-encoded');
                    if ($answer) {
                        $errorPresenter = new ErrorPresenter();
                        $errorPresenter->present($error);
                    }
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Validates DLV record content
     * Format: key-tag algorithm digest-type digest (same as DS)
     * RFC 4431
     *
     * @param string $content The record content
     * @param bool $answer Whether to present errors
     * @return bool True if valid, false otherwise
     */
    public static function is_valid_dlv(string $content, bool $answer = true): bool
    {
        // DLV has the same format as DS
        return self::is_valid_ds($content, $answer);
    }

    /**
     * Validates KEY record content
     * Format: flags protocol algorithm public-key (same as DNSKEY)
     * RFC 2535
     *
     * @param string $content The record content
     * @param bool $answer Whether to present errors
     * @return bool True if valid, false otherwise
     */
    public static function is_valid_key(string $content, bool $answer = true): bool
    {
        // KEY has the same format as DNSKEY
        return self::is_valid_dnskey($content, $answer);
    }

    /**
     * Validates MINFO record content
     * Format: rmailbx emailbx (two email addresses/mailboxes)
     * RFC 1035
     *
     * @param string $content The record content
     * @param bool $answer Whether to present errors
     * @return bool True if valid, false otherwise
     */
    public static function is_valid_minfo(string $content, bool $answer = true): bool
    {
        $content = trim($content);

        if (empty($content)) {
            $error = _('MINFO record content is required');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        $fields = preg_split('/\s+/', $content);

        if (count($fields) != 2) {
            $error = _('MINFO record must have 2 fields: rmailbx and emailbx');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        $rmailbx = $fields[0];
        $emailbx = $fields[1];

        // Validate rmailbx (responsible mailbox - domain name)
        if (!preg_match('/^([a-zA-Z0-9_]([a-zA-Z0-9\-]*[a-zA-Z0-9])?\.)*[a-zA-Z0-9_]([a-zA-Z0-9\-]*[a-zA-Z0-9])?\.?$/', $rmailbx)) {
            $error = _('MINFO rmailbx must be a valid domain name');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate emailbx (error mailbox - domain name)
        if (!preg_match('/^([a-zA-Z0-9_]([a-zA-Z0-9\-]*[a-zA-Z0-9])?\.)*[a-zA-Z0-9_]([a-zA-Z0-9\-]*[a-zA-Z0-9])?\.?$/', $emailbx)) {
            $error = _('MINFO emailbx must be a valid domain name');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        return true;
    }

    /**
     * Validates MR record content
     * Format: newname (single domain name - mailbox rename)
     * RFC 1035
     *
     * @param string $content The record content
     * @param bool $answer Whether to present errors
     * @return bool True if valid, false otherwise
     */
    public static function is_valid_mr(string $content, bool $answer = true): bool
    {
        $content = trim($content);

        if (empty($content)) {
            $error = _('MR record content is required');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate newname (domain name)
        if (!preg_match('/^([a-zA-Z0-9_]([a-zA-Z0-9\-]*[a-zA-Z0-9])?\.)*[a-zA-Z0-9_]([a-zA-Z0-9\-]*[a-zA-Z0-9])?\.?$/', $content)) {
            $error = _('MR newname must be a valid domain name');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        return true;
    }

    /**
     * Validates WKS record content
     * Format: address protocol bitmap (well-known services)
     * RFC 1035
     *
     * @param string $content The record content
     * @param bool $answer Whether to present errors
     * @return bool True if valid, false otherwise
     */
    public static function is_valid_wks(string $content, bool $answer = true): bool
    {
        $content = trim($content);

        if (empty($content)) {
            $error = _('WKS record content is required');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        $fields = preg_split('/\s+/', $content);

        if (count($fields) < 3) {
            $error = _('WKS record must have at least 3 fields: address, protocol, and services');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        $address = $fields[0];
        $protocol = $fields[1];

        // Validate address (IPv4)
        if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $error = _('WKS address must be a valid IPv4 address');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate protocol (8-bit unsigned integer or protocol name)
        if (is_numeric($protocol)) {
            if ($protocol < 0 || $protocol > 255 || $protocol != (int)$protocol) {
                $error = _('WKS protocol must be an 8-bit unsigned integer (0-255) or protocol name');
                if ($answer) {
                    $errorPresenter = new ErrorPresenter();
                    $errorPresenter->present($error);
                }
                return false;
            }
        } else {
            // Protocol name (e.g., "tcp", "udp")
            if (!preg_match('/^[a-zA-Z][a-zA-Z0-9]*$/', $protocol)) {
                $error = _('WKS protocol must be an 8-bit unsigned integer (0-255) or protocol name');
                if ($answer) {
                    $errorPresenter = new ErrorPresenter();
                    $errorPresenter->present($error);
                }
                return false;
            }
        }

        // Services are port numbers or service names - validate each
        for ($i = 2; $i < count($fields); $i++) {
            $service = $fields[$i];
            if (is_numeric($service)) {
                if ($service < 0 || $service > 65535 || $service != (int)$service) {
                    $error = _('WKS service must be a 16-bit port number (0-65535) or service name');
                    if ($answer) {
                        $errorPresenter = new ErrorPresenter();
                        $errorPresenter->present($error);
                    }
                    return false;
                }
            } else {
                // Service name (e.g., "http", "smtp")
                if (!preg_match('/^[a-zA-Z][a-zA-Z0-9\-]*$/', $service)) {
                    $error = _('WKS service must be a 16-bit port number (0-65535) or service name');
                    if ($answer) {
                        $errorPresenter = new ErrorPresenter();
                        $errorPresenter->present($error);
                    }
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Validates A6 record content (deprecated)
     * Format: prefix-length address-suffix prefix-name
     * RFC 2874 (deprecated by RFC 6563)
     *
     * @param string $content The record content
     * @param bool $answer Whether to present errors
     * @return bool True if valid, false otherwise
     */
    public static function is_valid_a6(string $content, bool $answer = true): bool
    {
        $content = trim($content);

        if (empty($content)) {
            $error = _('A6 record content is required');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // A6 is deprecated - basic validation: at least one field present
        // Format can be: "0 ::1", "64 ::1 prefix.example.com", or "128 prefix.example.com"
        $fields = preg_split('/\s+/', $content);

        if (count($fields) < 2) {
            $error = _('A6 record must have at least 2 fields: prefix-length and address-suffix or prefix-name');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        $prefixLength = $fields[0];

        // Validate prefix length (0-128)
        if (!is_numeric($prefixLength) || $prefixLength < 0 || $prefixLength > 128 || $prefixLength != (int)$prefixLength) {
            $error = _('A6 prefix length must be an integer (0-128)');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        return true;
    }

    /**
     * Validates CSYNC record content
     * Format: SOA-serial flags type-list
     * RFC 7477
     *
     * @param string $content The record content
     * @param bool $answer Whether to present errors
     * @return bool True if valid, false otherwise
     */
    public static function is_valid_csync(string $content, bool $answer = true): bool
    {
        $content = trim($content);

        if (empty($content)) {
            $error = _('CSYNC record content is required');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        $fields = preg_split('/\s+/', $content);

        if (count($fields) < 3) {
            $error = _('CSYNC record must have at least 3 fields: SOA-serial, flags, and type-list');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        $serial = $fields[0];
        $flags = $fields[1];

        // Validate SOA serial (32-bit unsigned integer)
        if (!is_numeric($serial) || $serial < 0 || $serial > 4294967295 || $serial != (int)$serial) {
            $error = _('CSYNC SOA serial must be a 32-bit unsigned integer (0-4294967295)');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate flags (16-bit unsigned integer)
        if (!is_numeric($flags) || $flags < 0 || $flags > 65535 || $flags != (int)$flags) {
            $error = _('CSYNC flags must be a 16-bit unsigned integer (0-65535)');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate type list (must be uppercase)
        for ($i = 2; $i < count($fields); $i++) {
            $type = $fields[$i];
            if ($type !== strtoupper($type)) {
                $error = _('CSYNC type list must contain uppercase type names');
                if ($answer) {
                    $errorPresenter = new ErrorPresenter();
                    $errorPresenter->present($error);
                }
                return false;
            }
        }

        return true;
    }

    /**
     * Validates ZONEMD record content
     * Format: serial scheme algorithm digest
     * RFC 8976
     *
     * @param string $content The record content
     * @param bool $answer Whether to present errors
     * @return bool True if valid, false otherwise
     */
    public static function is_valid_zonemd(string $content, bool $answer = true): bool
    {
        $content = trim($content);

        if (empty($content)) {
            $error = _('ZONEMD record content is required');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        $fields = preg_split('/\s+/', $content);

        if (count($fields) != 4) {
            $error = _('ZONEMD record must have 4 fields: serial, scheme, algorithm, and digest');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        $serial = $fields[0];
        $scheme = $fields[1];
        $algorithm = $fields[2];
        $digest = $fields[3];

        // Validate serial (32-bit unsigned integer)
        if (!is_numeric($serial) || $serial < 0 || $serial > 4294967295 || $serial != (int)$serial) {
            $error = _('ZONEMD serial must be a 32-bit unsigned integer (0-4294967295)');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate scheme (8-bit unsigned integer)
        if (!is_numeric($scheme) || $scheme < 0 || $scheme > 255 || $scheme != (int)$scheme) {
            $error = _('ZONEMD scheme must be an 8-bit unsigned integer (0-255)');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate algorithm (8-bit unsigned integer)
        if (!is_numeric($algorithm) || $algorithm < 0 || $algorithm > 255 || $algorithm != (int)$algorithm) {
            $error = _('ZONEMD algorithm must be an 8-bit unsigned integer (0-255)');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate digest (hex string)
        if (!ctype_xdigit($digest)) {
            $error = _('ZONEMD digest must be hexadecimal');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        return true;
    }

    /**
     * Validates HTTPS record content (basic validation)
     * Format: priority target [parameters]
     * RFC 9460
     *
     * @param string $content The record content
     * @param bool $answer Whether to present errors
     * @return bool True if valid, false otherwise
     */
    public static function is_valid_https(string $content, bool $answer = true): bool
    {
        // HTTPS has the same format as SVCB
        return self::is_valid_svcb($content, $answer);
    }

    /**
     * Validates SVCB record content (basic validation)
     * Format: priority target [parameters]
     * RFC 9460
     *
     * @param string $content The record content
     * @param bool $answer Whether to present errors
     * @return bool True if valid, false otherwise
     */
    public static function is_valid_svcb(string $content, bool $answer = true): bool
    {
        $content = trim($content);

        if (empty($content)) {
            $error = _('SVCB record content is required');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        $fields = preg_split('/\s+/', $content, 2);

        if (count($fields) < 2) {
            $error = _('SVCB record must have at least 2 fields: priority and target');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        $priority = $fields[0];
        $target = explode(' ', $fields[1])[0]; // Get first part as target

        // Validate priority (16-bit unsigned integer)
        if (!is_numeric($priority) || $priority < 0 || $priority > 65535 || $priority != (int)$priority) {
            $error = _('SVCB priority must be a 16-bit unsigned integer (0-65535)');
            if ($answer) {
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            return false;
        }

        // Validate target (domain name or ".")
        if ($target !== '.') {
            if (!preg_match('/^([a-zA-Z0-9_]([a-zA-Z0-9\-]*[a-zA-Z0-9])?\.)*[a-zA-Z0-9_]([a-zA-Z0-9\-]*[a-zA-Z0-9])?\.?$/', $target)) {
                $error = _('SVCB target must be a valid domain name or "."');
                if ($answer) {
                    $errorPresenter = new ErrorPresenter();
                    $errorPresenter->present($error);
                }
                return false;
            }
        }

        // Parameters are optional and complex - basic validation passes if priority and target are valid
        return true;
    }
}
