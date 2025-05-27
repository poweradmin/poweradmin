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
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOCommon;

/**
 * Validator for CNAME DNS records
 *
 * Validates CNAME records according to:
 * - RFC 1034: Domain Names - Concepts and Facilities
 * - RFC 1035: Domain Names - Implementation and Specification
 * - RFC 2181: Clarifications to the DNS Specification (Section 10.1)
 *
 * CNAME records (Canonical Name) create an alias from one domain name to another.
 * According to RFC 1034 and RFC 2181, CNAME record names must be unique, cannot
 * coexist with other record types, and MX/NS records cannot point to a CNAME.
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class CNAMERecordValidator implements DnsRecordValidatorInterface
{
    private HostnameValidator $hostnameValidator;
    private TTLValidator $ttlValidator;
    private ConfigurationManager $config;
    private PDOCommon $db;

    /**
     * Constructor
     *
     * @param ConfigurationManager $config
     * @param PDOCommon $db
     */
    public function __construct(ConfigurationManager $config, PDOCommon $db)
    {
        $this->hostnameValidator = new HostnameValidator($config);
        $this->ttlValidator = new TTLValidator();
        $this->config = $config;
        $this->db = $db;
    }

    /**
     * Validate CNAME record
     *
     * @param string $content Target hostname
     * @param string $name CNAME hostname
     * @param mixed $prio Priority (not used for CNAME records)
     * @param int|string|null $ttl TTL value
     * @param int $defaultTTL Default TTL value
     * @param int $rid Record ID (for checking uniqueness)
     * @param string $zone Zone name (for checking empty CNAME)
     *
     * @return ValidationResult ValidationResult containing validated data or error messages
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL, int $rid = 0, string $zone = ''): ValidationResult
    {
        // 1. Check if CNAME unique (already exists as another record type)
        $uniqueResult = $this->validateCnameUnique($name, $rid);
        if (!$uniqueResult->isValid()) {
            return $uniqueResult;
        }

        // 2. Check if CNAME already exists
        $existenceResult = $this->validateCnameExistence($name, $rid);
        if (!$existenceResult->isValid()) {
            return $existenceResult;
        }

        // 3. Check for MX or NS records pointing to this CNAME
        $nameResult = $this->validateCnameName($name);
        if (!$nameResult->isValid()) {
            return $nameResult;
        }

        // 4. Validate CNAME hostname
        $hostnameResult = $this->hostnameValidator->validate($name, true);
        if (!$hostnameResult->isValid()) {
            return $hostnameResult;
        }
        $hostnameData = $hostnameResult->getData();
        $name = $hostnameData['hostname'];

        // 5. Validate target hostname
        $contentHostnameResult = $this->hostnameValidator->validate($content, false);
        if (!$contentHostnameResult->isValid()) {
            return $contentHostnameResult;
        }
        $contentData = $contentHostnameResult->getData();
        $content = $contentData['hostname'];

        // 6. Check that zone does not have an empty CNAME RR
        if (!empty($zone)) {
            $emptyResult = $this->validateNotEmptyCnameRR($name, $zone);
            if (!$emptyResult->isValid()) {
                return $emptyResult;
            }
        }

        // 7. Validate TTL
        $ttlResult = $this->ttlValidator->validate($ttl, $defaultTTL);
        if (!$ttlResult->isValid()) {
            return $ttlResult;
        }
        $ttlData = $ttlResult->getData();
        $validatedTtl = is_array($ttlData) && isset($ttlData['ttl']) ? $ttlData['ttl'] : $ttlData;

        // 8. Validate priority (should be 0 for CNAME records)
        $prioResult = $this->validatePriority($prio);
        if (!$prioResult->isValid()) {
            return $prioResult;
        }
        $validatedPrio = $prioResult->getData();

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => $validatedPrio,
            'ttl' => $validatedTtl
        ]);
    }

    /**
     * Validate priority for CNAME records
     * CNAME records don't use priority, so it should be 0
     *
     * @param mixed $prio Priority value
     *
     * @return ValidationResult ValidationResult containing validated priority or error message
     */
    private function validatePriority(mixed $prio): ValidationResult
    {
        // If priority is not provided or empty, set it to 0
        if (!isset($prio) || $prio === "") {
            return ValidationResult::success(0);
        }

        // If provided, ensure it's 0 for CNAME records
        if (is_numeric($prio) && intval($prio) === 0) {
            return ValidationResult::success(0);
        }

        return ValidationResult::failure(_('Invalid value for priority field. CNAME records must have priority value of 0.'));
    }

    /**
     * Check if CNAME is unique (doesn't overlap other record types)
     *
     * @param string $name CNAME
     * @param int $rid Record ID
     *
     * @return ValidationResult ValidationResult containing success or error message
     */
    private function validateCnameUnique(string $name, int $rid): ValidationResult
    {
        $pdns_db_name = $this->config->get('database', 'pdns_db_name');
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

        // Check if there are any records with this name
        if ($rid > 0) {
            $query = "SELECT id FROM $records_table WHERE name = ? AND TYPE != 'CNAME' AND id != ?";
            $stmt = $this->db->prepare($query);
            $stmt->execute([$name, $rid]);
        } else {
            $query = "SELECT id FROM $records_table WHERE name = ? AND TYPE != 'CNAME'";
            $stmt = $this->db->prepare($query);
            $stmt->execute([$name]);
        }

        $response = $stmt->fetchColumn();
        if ($response) {
            return ValidationResult::failure(_('This is not a valid CNAME. There already exists a record with this name.'));
        }
        return ValidationResult::success(true);
    }

    /**
     * Check if CNAME is valid
     *
     * Check if any MX or NS entries exist which invalidate CNAME
     *
     * @param string $name CNAME to lookup
     *
     * @return ValidationResult ValidationResult containing success or error message
     */
    private function validateCnameName(string $name): ValidationResult
    {
        $pdns_db_name = $this->config->get('database', 'pdns_db_name');
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

        $query = "SELECT id FROM $records_table WHERE content = ? AND (type = ? OR type = ?)";
        $stmt = $this->db->prepare($query);
        $stmt->execute([$name, 'MX', 'NS']);

        $response = $stmt->fetchColumn();

        if (!empty($response)) {
            return ValidationResult::failure(_('This is not a valid CNAME. Did you assign an MX or NS record to the record?'));
        }

        return ValidationResult::success(true);
    }

    /**
     * Check that the zone does not have an empty CNAME RR
     *
     * @param string $name Hostname
     * @param string $zone Zone name
     *
     * @return ValidationResult ValidationResult containing success or error message
     */
    private function validateNotEmptyCnameRR(string $name, string $zone): ValidationResult
    {
        if ($name == $zone) {
            return ValidationResult::failure(_('Empty CNAME records are not allowed.'));
        }
        return ValidationResult::success(true);
    }

    /**
     * Check if CNAME already exists
     *
     * @param string $name CNAME
     * @param int $rid Record ID
     *
     * @return ValidationResult ValidationResult containing success or error message
     */
    public function validateCnameExistence(string $name, int $rid): ValidationResult
    {
        $pdns_db_name = $this->config->get('database', 'pdns_db_name');
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

        if ($rid > 0) {
            $query = "SELECT id FROM $records_table WHERE name = ? AND TYPE = 'CNAME' AND id != ?";
            $stmt = $this->db->prepare($query);
            $stmt->execute([$name, $rid]);
        } else {
            $query = "SELECT id FROM $records_table WHERE name = ? AND TYPE = 'CNAME'";
            $stmt = $this->db->prepare($query);
            $stmt->execute([$name]);
        }

        $response = $stmt->fetchColumn();
        if ($response) {
            return ValidationResult::failure(_('This is not a valid record. There already exists a CNAME with this name.'));
        }
        return ValidationResult::success(true);
    }
}
