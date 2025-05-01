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
use Poweradmin\Domain\Service\DnsValidation\ARecordValidator;
use Poweradmin\Domain\Service\DnsValidation\AAAARecordValidator;
use Poweradmin\Domain\Service\DnsValidation\CNAMERecordValidator;
use Poweradmin\Domain\Service\DnsValidation\CSYNCRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\DSRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Domain\Service\DnsValidation\IPAddressValidator;
use Poweradmin\Domain\Service\DnsValidation\TTLValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Service\MessageService;
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
    private ConfigurationManager $config;
    private PDOLayer $db;
    private MessageService $messageService;
    private HostnameValidator $hostnameValidator;
    private TTLValidator $ttlValidator;
    private ARecordValidator $aRecordValidator;
    private AAAARecordValidator $aaaaRecordValidator;
    private CNAMERecordValidator $cnameRecordValidator;
    private CSYNCRecordValidator $csyncRecordValidator;
    private DSRecordValidator $dsRecordValidator;

    public function __construct(PDOLayer $db, ConfigurationManager $config)
    {
        $this->db = $db;
        $this->config = $config;
        $this->messageService = new MessageService();
        $this->hostnameValidator = new HostnameValidator($config);
        $this->ttlValidator = new TTLValidator();
        $this->aRecordValidator = new ARecordValidator($config);
        $this->aaaaRecordValidator = new AAAARecordValidator($config);
        $this->cnameRecordValidator = new CNAMERecordValidator($config, $db);
        $this->csyncRecordValidator = new CSYNCRecordValidator($config);
        $this->dsRecordValidator = new DSRecordValidator($config);
    }

    /** Validate DNS record input
     *
     * @param int $rid Record ID
     * @param int $zid Zone ID
     * @param string $type Record Type
     * @param mixed $content content part of record
     * @param string $name Name part of record
     * @param mixed $prio Priority
     * @param mixed $ttl TTL
     *
     * @return array|bool Returns array with validated data on success, false otherwise
     */
    public function validate_input(int $rid, int $zid, string $type, mixed $content, string $name, mixed $prio, mixed $ttl, $dns_hostmaster, $dns_ttl): array|bool
    {
        $dnsRecord = new DnsRecord($this->db, $this->config);
        $zone = $dnsRecord->get_domain_name_by_id($zid);
        if (!$zone) {
            $this->messageService->addSystemError(_('Unable to find domain with the given ID.'));
            return false;
        }

        if ($type != RecordType::CNAME) {
            if (!$this->is_valid_rr_cname_exists($name, $rid)) {
                return false;
            }
        }

        switch ($type) {
            case RecordType::A:
                $validationResult = $this->aRecordValidator->validate($content, $name, $prio, $ttl, $dns_ttl);
                if ($validationResult === false) {
                    return false;
                }

                // Update variables with validated data
                $content = $validationResult['content'];
                $name = $validationResult['name'];
                $prio = $validationResult['prio'];
                $ttl = $validationResult['ttl'];
                break;

            // TODO: implement validation.
            case RecordType::AFSDB:
            case RecordType::ALIAS:
            case RecordType::APL:
            case RecordType::CAA:
            case RecordType::CDNSKEY:
            case RecordType::CDS:
            case RecordType::CERT:
            case RecordType::DNAME:
            case RecordType::L32:
            case RecordType::L64:
            case RecordType::LUA:
            case RecordType::LP:
            case RecordType::OPENPGPKEY:
            case RecordType::SMIMEA:
            case RecordType::TKEY:
            case RecordType::URI:
                break;

            case RecordType::AAAA:
                $validationResult = $this->aaaaRecordValidator->validate($content, $name, $prio, $ttl, $dns_ttl);
                if ($validationResult === false) {
                    return false;
                }

                // Update variables with validated data
                $content = $validationResult['content'];
                $name = $validationResult['name'];
                $prio = $validationResult['prio'];
                $ttl = $validationResult['ttl'];
                break;

            case RecordType::CNAME:
                $validationResult = $this->cnameRecordValidator->validate($content, $name, $prio, $ttl, $dns_ttl, $rid, $zone);
                if ($validationResult === false) {
                    return false;
                }

                // Update variables with validated data
                $content = $validationResult['content'];
                $name = $validationResult['name'];
                $prio = $validationResult['prio'];
                $ttl = $validationResult['ttl'];
                break;

            case RecordType::DHCID:
            case RecordType::DLV:
            case RecordType::DNSKEY:
            case RecordType::EUI48:
            case RecordType::EUI64:
            case RecordType::HTTPS:
            case RecordType::IPSECKEY:
            case RecordType::KEY:
            case RecordType::KX:
            case RecordType::MINFO:
            case RecordType::MR:
            case RecordType::NAPTR:
            case RecordType::NID:
            case RecordType::NSEC:
            case RecordType::NSEC3:
            case RecordType::NSEC3PARAM:
            case RecordType::RKEY:
            case RecordType::RP:
            case RecordType::RRSIG:
            case RecordType::SSHFP:
            case RecordType::SVCB:
            case RecordType::TLSA:
            case RecordType::TSIG:
            case RecordType::CSYNC:
                $validationResult = $this->csyncRecordValidator->validate($content, $name, $prio, $ttl, $dns_ttl);
                if ($validationResult === false) {
                    return false;
                }

                // Update variables with validated data
                $content = $validationResult['content'];
                $name = $validationResult['name'];
                $prio = $validationResult['prio'];
                $ttl = $validationResult['ttl'];
                break;

            case RecordType::DS:
                $validationResult = $this->dsRecordValidator->validate($content, $name, $prio, $ttl, $dns_ttl);
                if ($validationResult === false) {
                    return false;
                }

                // Update variables with validated data
                $content = $validationResult['content'];
                $name = $validationResult['name'];
                $prio = $validationResult['prio'];
                $ttl = $validationResult['ttl'];
                break;

            case RecordType::HINFO:
                if (!self::is_valid_rr_hinfo_content($content)) {
                    return false;
                }
                $hostnameResult = $this->is_valid_hostname_fqdn($name, 1);
                if ($hostnameResult === false) {
                    return false;
                }
                $name = $hostnameResult['hostname'];
                break;

            case RecordType::LOC:
                if (!self::is_valid_loc($content)) {
                    return false;
                }
                $hostnameResult = $this->is_valid_hostname_fqdn($name, 1);
                if ($hostnameResult === false) {
                    return false;
                }
                $name = $hostnameResult['hostname'];
                break;

            case RecordType::NS:
            case RecordType::MX:
                $contentHostnameResult = $this->is_valid_hostname_fqdn($content, 0);
                if ($contentHostnameResult === false) {
                    return false;
                }
                $content = $contentHostnameResult['hostname'];

                $hostnameResult = $this->is_valid_hostname_fqdn($name, 1);
                if ($hostnameResult === false) {
                    return false;
                }
                $name = $hostnameResult['hostname'];

                if (!$this->is_valid_non_alias_target($content)) {
                    return false;
                }
                break;

            case RecordType::PTR:
                $contentHostnameResult = $this->is_valid_hostname_fqdn($content, 0);
                if ($contentHostnameResult === false) {
                    return false;
                }
                $content = $contentHostnameResult['hostname'];

                $hostnameResult = $this->is_valid_hostname_fqdn($name, 1);
                if ($hostnameResult === false) {
                    return false;
                }
                $name = $hostnameResult['hostname'];
                break;

            case RecordType::SOA:
                if (!self::is_valid_rr_soa_name($name, $zone)) {
                    return false;
                }
                $hostnameResult = $this->is_valid_hostname_fqdn($name, 1);
                if ($hostnameResult === false) {
                    return false;
                }
                $name = $hostnameResult['hostname'];

                $soaResult = $this->is_valid_rr_soa_content($content, $dns_hostmaster);
                if ($soaResult === false) {
                    $this->messageService->addSystemError(_('Your content field doesnt have a legit value.'));
                    return false;
                }
                $content = $soaResult['content'];
                break;

            case RecordType::SPF:
                if (!self::is_valid_spf($content)) {
                    $this->messageService->addSystemError(_('The content of the SPF record is invalid'));

                    return false;
                }
                if (!self::has_quotes_around($content)) {
                    return false;
                }
                break;

            case RecordType::SRV:
                $srvNameResult = $this->is_valid_rr_srv_name($name);
                if ($srvNameResult === false) {
                    return false;
                }
                $name = $srvNameResult['name'];

                $srvContentResult = $this->is_valid_rr_srv_content($content, $name);
                if ($srvContentResult === false) {
                    return false;
                }
                $content = $srvContentResult['content'];
                break;

            case RecordType::TXT:
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
                $this->messageService->addSystemError(_('Unknown record type.'));

                return false;
        }

        // Skip validation if it was already handled by a specific validator
        if ($type !== RecordType::A && $type !== RecordType::AAAA && $type !== RecordType::CNAME && $type !== RecordType::CSYNC) {
            $validatedPrio = self::is_valid_rr_prio($prio, $type);
            if ($validatedPrio === false) {
                $this->messageService->addSystemError(_('Invalid value for prio field.'));
                return false;
            }

            $validatedTtl = $this->ttlValidator->isValidTTL($ttl, $dns_ttl);
            if ($validatedTtl === false) {
                return false;
            }
        } else {
            // We've already validated these in the specific record validator
            $validatedPrio = $prio;
            $validatedTtl = $ttl;
        }

        return [
            'content' => $content,
            'name' => $name,
            'prio' => $validatedPrio,
            'ttl' => $validatedTtl
        ];
    }

    /** Test if hostname is valid FQDN
     *
     * @param mixed $hostname Hostname string
     * @param string $wildcard Hostname includes wildcard '*'
     *
     * @return array|bool Returns array with normalized hostname if valid, false otherwise
     */
    public function is_valid_hostname_fqdn(mixed $hostname, string $wildcard): array|bool
    {
        return $this->hostnameValidator->isValidHostnameFqdn($hostname, $wildcard);
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
        $validator = new IPAddressValidator();
        return $validator->isValidIPv4($ipv4, $answer);
    }

    /** Test if IPv6 address is valid
     *
     * @param string $ipv6 IPv6 address string
     * @param boolean $answer print error if true
     * [default=false]
     *
     * @return boolean true if valid, false otherwise
     */
    public static function is_valid_ipv6(string $ipv6, bool $answer = false): bool
    {
        $validator = new IPAddressValidator();
        return $validator->isValidIPv6($ipv6, $answer);
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
        $validator = new IPAddressValidator();
        return $validator->areMultipleValidIPs($ips);
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
            (new MessageService())->addSystemError(_('Invalid characters have been used in this record.'));
            return false;
        }
        return true;
    }

    /** Test if string has html opening and closing tags
     *
     * @param string $string Input string
     * @return bool true if HTML tags are found, false otherwise
     */
    public static function has_html_tags(string $string): bool
    {
        // Method should return true if the string contains HTML tags, false otherwise
        $contains_tags = preg_match('/[<>]/', trim($string));
        if ($contains_tags) {
            (new MessageService())->addSystemError(_('You cannot use html tags for this type of record.'));
        }
        return $contains_tags;
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
            (new MessageService())->addSystemError(_('Backslashes must precede all quotes (") in TXT content'));
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
            (new MessageService())->addSystemError(_('Add quotes around TXT record content.'));
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
     * @deprecated Use CNAMERecordValidator::isValidCnameName() instead
     */
    public function is_valid_rr_cname_name(string $name): bool
    {
        $pdns_db_name = $this->config->get('database', 'pdns_name');
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

        $query = "SELECT id FROM $records_table
			WHERE content = " . $this->db->quote($name, 'text') . "
			AND (type = " . $this->db->quote('MX', 'text') . " OR type = " . $this->db->quote('NS', 'text') . ")";

        $response = $this->db->queryOne($query);

        if (!empty($response)) {
            $this->messageService->addSystemError(_('This is not a valid CNAME. Did you assign an MX or NS record to the record?'));
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
     * @deprecated Use CNAMERecordValidator::isValidCnameExistence() instead
     */
    public function is_valid_rr_cname_exists(string $name, int $rid): bool
    {
        $pdns_db_name = $this->config->get('database', 'pdns_name');
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

        $where = ($rid > 0 ? " AND id != " . $this->db->quote($rid, 'integer') : '');
        $query = "SELECT id FROM $records_table
                        WHERE name = " . $this->db->quote($name, 'text') . $where . "
                        AND TYPE = 'CNAME'";

        $response = $this->db->queryOne($query);
        if ($response) {
            $this->messageService->addSystemError(_('This is not a valid record. There is already exists a CNAME with this name.'));
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
     * @deprecated Use CNAMERecordValidator::isValidCnameUnique() instead
     */
    public function is_valid_rr_cname_unique(string $name, string $rid): bool
    {
        $pdns_db_name = $this->config->get('database', 'pdns_name');
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

        $where = ($rid > 0 ? " AND id != " . $this->db->quote($rid, 'integer') : '');
        // Check if there are any records with this name
        $query = "SELECT id FROM $records_table
                        WHERE name = " . $this->db->quote($name, 'text') .
                        " AND TYPE != 'CNAME'" .
                        $where;

        $response = $this->db->queryOne($query);
        if ($response) {
            $this->messageService->addSystemError(_('This is not a valid CNAME. There already exists a record with this name.'));
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
     * @deprecated Use CNAMERecordValidator::isNotEmptyCnameRR() instead
     */
    public static function is_not_empty_cname_rr(string $name, string $zone): bool
    {

        if ($name == $zone) {
            (new MessageService())->addSystemError(_('Empty CNAME records are not allowed.'));
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
        $pdns_db_name = $this->config->get('database', 'pdns_name');
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

        $query = "SELECT id FROM $records_table
			WHERE name = " . $this->db->quote($target, 'text') . "
			AND TYPE = " . $this->db->quote('CNAME', 'text');

        $response = $this->db->queryOne($query);
        if ($response) {
            $this->messageService->addSystemError(_('You can not point a NS or MX record to a CNAME record. Remove or rename the CNAME record first, or take another name.'));
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
                (new MessageService())->addSystemError(_('Invalid value for content field of HINFO record.'));
                return false;
            }
        }

        return true;
    }

    /** Check if SOA content is valid
     *
     * @param mixed $content SOA record content
     * @param string $dns_hostmaster Hostmaster email address
     *
     * @return array|bool Returns array with formatted content if valid, false otherwise
     */
    public function is_valid_rr_soa_content(mixed $content, $dns_hostmaster): array|bool
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
        return ['content' => $final_soa];
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
            (new MessageService())->addSystemError(_('Invalid value for name field of SOA record. It should be the name of the zone.'));
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
     * @return int|bool Valid priority value or false if invalid
     */
    public static function is_valid_rr_prio(mixed $prio, string $type): int|bool
    {
        // If priority is not provided or empty, set a default value based on record type
        if (!isset($prio) || $prio === "") {
            // Use 10 as default priority for MX and SRV records (common practice)
            if ($type == "MX" || $type == "SRV") {
                return 10;
            }
            // For all other record types, use 0
            return 0;
        }

        // Validate priority
        if (($type == "MX" || $type == "SRV") && (is_numeric($prio) && $prio >= 0 && $prio <= 65535)) {
            return (int)$prio;
        } elseif (is_numeric($prio) && $prio == 0) {
            return 0;
        } else {
            return false;
        }
    }

    /** Check if SRV name is valid
     *
     * @param mixed $name SRV name
     *
     * @return array|bool Returns array with formatted name if valid, false otherwise
     */
    public function is_valid_rr_srv_name(mixed $name): array|bool
    {
        if (strlen($name) > 255) {
            $this->messageService->addSystemError(_('The hostname is too long.'));
            return false;
        }

        $fields = explode('.', $name, 3);

        // Check if we have all three parts required for an SRV record
        if (count($fields) < 3) {
            $this->messageService->addSystemError(_('SRV record name must be in format _service._protocol.domain'));
            return false;
        }

        if (!preg_match('/^_[\w\-]+$/i', $fields[0])) {
            $this->messageService->addSystemError(_('Invalid service value in name field of SRV record.'));
            return false;
        }
        if (!preg_match('/^_[\w]+$/i', $fields[1])) {
            $this->messageService->addSystemError(_('Invalid protocol value in name field of SRV record.'));
            return false;
        }
        if (!$this->is_valid_hostname_fqdn($fields[2], 0)) {
            $this->messageService->addSystemError(_('Invalid FQDN value in name field of SRV record.'));
            return false;
        }
        return ['name' => join('.', $fields)];
    }

    /** Check if SRV content is valid
     *
     * @param mixed $content SRV content
     * @param string $name SRV name
     *
     * @return array|bool Returns array with formatted content if valid, false otherwise
     */
    public function is_valid_rr_srv_content(mixed $content, $name): array|bool
    {
        $fields = preg_split("/\s+/", trim($content));

        // Check if we have exactly 4 fields for an SRV record content
        // Format should be: <priority> <weight> <port> <target>
        if (count($fields) != 4) {
            $this->messageService->addSystemError(_('SRV record content must have priority, weight, port and target'));
            return false;
        }

        if (!is_numeric($fields[0]) || $fields[0] < 0 || $fields[0] > 65535) {
            $this->messageService->addSystemError(_('Invalid value for the priority field of the SRV record.'));
            return false;
        }
        if (!is_numeric($fields[1]) || $fields[1] < 0 || $fields[1] > 65535) {
            $this->messageService->addSystemError(_('Invalid value for the weight field of the SRV record.'));
            return false;
        }
        if (!is_numeric($fields[2]) || $fields[2] < 0 || $fields[2] > 65535) {
            $this->messageService->addSystemError(_('Invalid value for the port field of the SRV record.'));
            return false;
        }
        if ($fields[3] == "" || ($fields[3] != "." && !$this->is_valid_hostname_fqdn($fields[3], 0))) {
            $this->messageService->addSystemError(_('Invalid SRV target.'));
            return false;
        }
        return ['content' => join(' ', $fields)];
    }

    /** Check if TTL is valid and within range
     *
     * @param mixed $ttl TTL
     * @param mixed $dns_ttl Default TTL
     *
     * @return int|bool Validated TTL value if valid, false otherwise
     * @deprecated Use TTLValidator::isValidTTL() instead
     */
    public static function is_valid_rr_ttl(mixed $ttl, mixed $dns_ttl): int|bool
    {
        $validator = new TTLValidator();
        return $validator->isValidTTL($ttl, $dns_ttl);
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
}
