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
use Poweradmin\Domain\Service\DnsValidation\LOCRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\SPFRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\SRVRecordValidator;
use Poweradmin\Domain\Service\DnsValidation\StringValidator;
use Poweradmin\Domain\Service\DnsValidation\TTLValidator;
use Poweradmin\Domain\Service\DnsValidation\TXTRecordValidator;
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
    private LOCRecordValidator $locRecordValidator;
    private SPFRecordValidator $spfRecordValidator;
    private SRVRecordValidator $srvRecordValidator;
    private TXTRecordValidator $txtRecordValidator;

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
        $this->locRecordValidator = new LOCRecordValidator($config);
        $this->spfRecordValidator = new SPFRecordValidator($config);
        $this->srvRecordValidator = new SRVRecordValidator($config);
        $this->txtRecordValidator = new TXTRecordValidator($config);
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
                $validationResult = $this->locRecordValidator->validate($content, $name, $prio, $ttl, $dns_ttl);
                if ($validationResult === false) {
                    return false;
                }

                // Update variables with validated data
                $content = $validationResult['content'];
                $name = $validationResult['name'];
                $prio = $validationResult['prio'];
                $ttl = $validationResult['ttl'];
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
                $validationResult = $this->spfRecordValidator->validate($content, $name, $prio, $ttl, $dns_ttl);
                if ($validationResult === false) {
                    return false;
                }

                // Update variables with validated data
                $content = $validationResult['content'];
                $name = $validationResult['name'];
                $prio = $validationResult['prio'];
                $ttl = $validationResult['ttl'];
                break;

            case RecordType::SRV:
                $validationResult = $this->srvRecordValidator->validate($content, $name, $prio, $ttl, $dns_ttl);
                if ($validationResult === false) {
                    return false;
                }

                // Update variables with validated data
                $content = $validationResult['content'];
                $name = $validationResult['name'];
                $prio = $validationResult['prio'];
                $ttl = $validationResult['ttl'];
                break;

            case RecordType::TXT:
                $validationResult = $this->txtRecordValidator->validate($content, $name, $prio, $ttl, $dns_ttl);
                if ($validationResult === false) {
                    return false;
                }

                // Update variables with validated data
                $content = $validationResult['content'];
                $name = $validationResult['name'];
                $prio = $validationResult['prio'];
                $ttl = $validationResult['ttl'];
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
}
