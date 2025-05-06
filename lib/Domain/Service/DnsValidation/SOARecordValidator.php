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
use Poweradmin\Infrastructure\Database\PDOLayer;

/**
 * SOA record validator
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
    private PDOLayer $db;

    // SOA-specific parameters
    private string $dns_hostmaster;
    private string $zone;

    public function __construct(ConfigurationManager $config, PDOLayer $db)
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
     *
     * @return ValidationResult<array> ValidationResult containing validated data or error messages
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL): ValidationResult
    {
        $errors = [];

        // Check if SOA params have been set
        if (!isset($this->dns_hostmaster) || !isset($this->zone)) {
            return ValidationResult::failure(_('SOA validation parameters not set. Call setSOAParams() first.'));
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
        $soaResult = $this->validateSoaContent($content, $this->dns_hostmaster, $errors);
        if (!$soaResult['isValid']) {
            if (empty($errors)) {
                $errors[] = _('Your content field doesnt have a legit value.');
            }
            return ValidationResult::errors($errors);
        }
        $content = $soaResult['content'];

        // Validate TTL
        $ttlResult = $this->ttlValidator->validate($ttl, $defaultTTL);
        if (!$ttlResult->isValid()) {
            return $ttlResult;
        }
        $ttlData = $ttlResult->getData();
        $validatedTtl = is_array($ttlData) && isset($ttlData['ttl']) ? $ttlData['ttl'] : $ttlData;

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => 0, // SOA records don't use priority
            'ttl' => $validatedTtl
        ]);
    }

    /**
     * Validate SOA content
     *
     * @param string $content SOA record content
     * @param string $dns_hostmaster Hostmaster email address
     * @param array &$errors Array to collect validation errors
     *
     * @return array Result with 'isValid' and 'content' keys
     */
    private function validateSoaContent(string $content, string $dns_hostmaster, array &$errors): array
    {
        $fields = preg_split("/\s+/", trim($content));
        $field_count = count($fields);

        if ($field_count == 0 || $field_count > 7) {
            $errors[] = _('SOA record must have between 1 and 7 fields.');
            return ['isValid' => false];
        }

        // Validate primary nameserver
        $primaryNsResult = $this->hostnameValidator->validate($fields[0], false);
        if (!$primaryNsResult->isValid() || preg_match('/\.arpa\.?$/', $fields[0])) {
            $errors[] = _('Invalid primary nameserver in SOA record.');
            return ['isValid' => false];
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
        if (!$validation->is_valid_email($addr_to_check)) {
            $errors[] = _('Invalid email address in SOA record.');
            return ['isValid' => false];
        }

        $addr_final = explode('@', $addr_to_check, 2);
        $final_soa .= " " . str_replace(".", "\\.", $addr_final[0]) . "." . $addr_final[1];

        // Process serial number
        if (isset($fields[2])) {
            if (!is_numeric($fields[2])) {
                $errors[] = _('Serial number must be numeric.');
                return ['isValid' => false];
            }
            $final_soa .= " " . $fields[2];
        } else {
            $final_soa .= " 0";
        }

        // Process remaining numeric fields
        if ($field_count != 7) {
            $errors[] = _('SOA record must have exactly 7 fields (primary NS, email, serial, refresh, retry, expire, minimum).');
            return ['isValid' => false];
        }

        for ($i = 3; ($i < 7); $i++) {
            if (!is_numeric($fields[$i])) {
                $errors[] = _('SOA timing fields (refresh, retry, expire, minimum) must be numeric.');
                return ['isValid' => false];
            }
            $final_soa .= " " . $fields[$i];
        }

        return ['isValid' => true, 'content' => $final_soa];
    }
}
