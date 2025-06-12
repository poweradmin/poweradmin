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

use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Domain\Service\Validator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOCommon;

/**
 * SOA record validator
 *
 * Validates SOA (Start of Authority) records according to:
 * - RFC 1035: Domain Names - Implementation and Specification
 * - RFC 1982: Serial Number Arithmetic
 * - RFC 2308: Negative Caching of DNS Queries (DNS NCACHE)
 *
 * SOA record format:
 * [primary_ns] [admin_email] [serial] [refresh] [retry] [expire] [minimum]
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class SOARecordValidator implements DnsRecordValidatorInterface
{
    private ConfigurationManager $config;
    private HostnameValidator $hostnameValidator;
    private TTLValidator $ttlValidator;
    private PDOCommon $db;

    // SOA-specific parameters
    private string $dns_hostmaster;
    private string $zone;

    public function __construct(ConfigurationManager $config, PDOCommon $db)
    {
        $this->config = $config;
        $this->db = $db;
        $this->hostnameValidator = new HostnameValidator($config);
        $this->ttlValidator = new TTLValidator();
    }

    /**
     * Set SOA-specific validation parameters
     *
     * @param string $dns_hostmaster Hostmaster email address
     * @param string $zone Zone name
     */
    public function setSOAParams(string $dns_hostmaster, string $zone): void
    {
        $this->dns_hostmaster = $dns_hostmaster;
        $this->zone = $zone;
    }

    /**
     * Validates SOA record
     *
     * @param string $content SOA record content
     * @param string $name SOA name
     * @param mixed $prio Priority (not used for SOA records)
     * @param int|string|null $ttl TTL value
     * @param int $defaultTTL Default TTL value
     * @param mixed ...$args Additional parameters: [0] => string|null $dns_hostmaster, [1] => string|null $zone
     *
     * @return ValidationResult ValidationResult containing validated data or error messages
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL, ...$args): ValidationResult
    {
        $errors = [];

        // Extract optional parameters
        $dns_hostmaster = $args[0] ?? null;
        $zone = $args[1] ?? null;

        // If params are passed directly, use them; otherwise use the ones set via setSOAParams
        $dns_hostmaster_to_use = $dns_hostmaster ?? $this->dns_hostmaster ?? null;
        $zone_to_use = $zone ?? $this->zone ?? null;

        // Check if SOA params have been set
        if (!isset($dns_hostmaster_to_use) || !isset($zone_to_use)) {
            return ValidationResult::failure(_('SOA validation parameters not set. Call setSOAParams() first or provide them as arguments.'));
        }

        // Set the params for this validation run if passed directly
        if ($dns_hostmaster !== null && $zone !== null) {
            $this->dns_hostmaster = $dns_hostmaster;
            $this->zone = $zone;
        }

        // Validate zone name
        if ($name != $this->zone) {
            return ValidationResult::failure(_('Invalid value for name field of SOA record. It should be the name of the zone.'));
        }

        // Validate hostname
        $hostnameResult = $this->hostnameValidator->validate($name, true);
        if (!$hostnameResult->isValid()) {
            return $hostnameResult;
        }
        $hostnameData = $hostnameResult->getData();
        $name = $hostnameData['hostname'];

        // Validate SOA content
        $soaResult = $this->validateSoaContent($content, $this->dns_hostmaster);
        if (!$soaResult['isValid']) {
            if (empty($soaResult['errors'])) {
                return ValidationResult::failure(_('Your content field doesnt have a legit value.'));
            }
            return ValidationResult::errors($soaResult['errors']);
        }
        $content = $soaResult['content'];

        // If there are warnings, add them to the result but still continue
        $warnings = $soaResult['warnings'] ?? [];

        // Validate TTL
        $ttlResult = $this->ttlValidator->validate($ttl, $defaultTTL);
        if (!$ttlResult->isValid()) {
            return $ttlResult;
        }
        $ttlData = $ttlResult->getData();
        $validatedTtl = is_array($ttlData) && isset($ttlData['ttl']) ? $ttlData['ttl'] : $ttlData;

        $resultData = [
            'content' => $content,
            'name' => $name,
            'prio' => 0, // SOA records don't use priority
            'ttl' => $validatedTtl
        ];

        // Return with explicit warnings parameter
        return ValidationResult::success($resultData, $warnings);
    }

    /**
     * Validate SOA content
     *
     * @param string $content SOA record content
     * @param string $dns_hostmaster Hostmaster email address
     *
     * @return array Result with 'isValid', 'content', and 'errors' keys
     */
    private function validateSoaContent(string $content, string $dns_hostmaster): array
    {
        $errors = [];
        $warnings = [];
        $fields = preg_split("/\s+/", trim($content));
        $field_count = count($fields);

        if ($field_count == 0 || $field_count > 7) {
            $errors[] = _('SOA record must have between 1 and 7 fields.');
            return ['isValid' => false, 'errors' => $errors];
        }

        // Validate primary nameserver
        $primaryNsResult = $this->hostnameValidator->validate($fields[0], false);
        if (!$primaryNsResult->isValid() || preg_match('/\.arpa\.?$/', $fields[0])) {
            $errors[] = _('Invalid primary nameserver in SOA record.');
            return ['isValid' => false, 'errors' => $errors];
        }
        $final_soa = $primaryNsResult->getData()['hostname'];

        // Process email address
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
        if (!$validation->isValidEmail($addr_to_check)) {
            $errors[] = _('Invalid email address in SOA record.');
            return ['isValid' => false, 'errors' => $errors];
        }

        $addr_final = explode('@', $addr_to_check, 2);
        $final_soa .= " " . str_replace(".", "\\.", $addr_final[0]) . "." . $addr_final[1];

        // Process serial number according to RFC 1035 and RFC 1982
        if (isset($fields[2])) {
            // Serial must be numeric
            if (!is_numeric($fields[2])) {
                $errors[] = _('Serial number must be numeric.');
                return ['isValid' => false, 'errors' => $errors];
            }

            // Serial should be a 32-bit unsigned integer (0 to 4294967295)
            if ($fields[2] < 0 || $fields[2] > 4294967295) {
                $errors[] = _('Serial number must be a 32-bit unsigned integer (0 to 4294967295).');
                return ['isValid' => false, 'errors' => $errors];
            }

            // Recommended: Check if serial follows the YYYYMMDDnn format pattern
            // This is a common convention but not a strict requirement
            if (strlen($fields[2]) == 10) {
                $year = substr($fields[2], 0, 4);
                $month = substr($fields[2], 4, 2);
                $day = substr($fields[2], 6, 2);

                // Very basic validation - not enforced but will produce a warning
                if (!checkdate((int)$month, (int)$day, (int)$year)) {
                    $warnings[] = _('Serial number appears to use YYYYMMDDnn format but contains an invalid date. This is allowed but not recommended.');
                }
            }

            $final_soa .= " " . $fields[2];
        } else {
            // Default to current date in YYYYMMDDnn format if no serial provided
            $today = new \DateTime();
            $default_serial = $today->format('Ymd') . '01';
            $final_soa .= " " . $default_serial;
        }

        // Process remaining numeric fields
        if ($field_count != 7) {
            $errors[] = _('SOA record must have exactly 7 fields (primary NS, email, serial, refresh, retry, expire, minimum).');
            return ['isValid' => false, 'errors' => $errors];
        }

        // Define field names and RFC 2308 recommended minimum values
        $soa_field_names = ['refresh', 'retry', 'expire', 'minimum'];
        $soa_field_mins = [
            'refresh' => 1800,   // 30 minutes (RFC 2308 recommends refresh >= 2 hours)
            'retry' => 600,      // 10 minutes (RFC 2308 recommends retry < refresh)
            'expire' => 604800,  // 1 week (RFC 2308 recommends expire >= 2 weeks)
            'minimum' => 300     // 5 minutes (RFC 2308 recommendations for negative caching)
        ];

        // Process the SOA timing fields (refresh, retry, expire, minimum)
        for ($i = 3; ($i < 7); $i++) {
            $field_idx = $i - 3;
            $field_name = $soa_field_names[$field_idx];

            // Basic validation - fields must be numeric
            if (!is_numeric($fields[$i])) {
                $errors[] = sprintf(_('SOA %s field must be numeric.'), $field_name);
                return ['isValid' => false, 'errors' => $errors];
            }

            // Ensure values are positive integers
            if ((int)$fields[$i] < 0) {
                $errors[] = sprintf(_('SOA %s field must be a positive integer.'), $field_name);
                return ['isValid' => false, 'errors' => $errors];
            }

            // Check against RFC 2308 recommended minimum values - warnings only
            $recommended_min = $soa_field_mins[$field_name];
            if ((int)$fields[$i] < $recommended_min) {
                $warnings[] = sprintf(
                    _('SOA %s value (%d) is below the RFC 2308 recommended minimum (%d). This is allowed but not recommended.'),
                    $field_name,
                    (int)$fields[$i],
                    $recommended_min
                );
            }

            // Specific validation for retry < refresh (RFC 2308 recommendation)
            if ($field_name === 'retry' && isset($fields[3]) && (int)$fields[$i] >= (int)$fields[3]) {
                $warnings[] = _('SOA retry value should be less than refresh value according to RFC 2308. This is allowed but not recommended.');
            }

            $final_soa .= " " . $fields[$i];
        }

        // Per RFC 2308, minimum field is now used as the negative caching TTL
        if (isset($fields[6]) && (int)$fields[6] > 86400) {
            $warnings[] = _('SOA minimum (negative caching) value exceeds 24 hours (86400), which may be excessive according to RFC 2308.');
        }

        return ['isValid' => true, 'content' => $final_soa, 'errors' => $errors, 'warnings' => $warnings];
    }
}
