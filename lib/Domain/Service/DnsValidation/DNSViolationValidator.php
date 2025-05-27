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

use Poweradmin\Domain\Model\RecordType;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOCommon;

/**
 * DNS Violation Validator
 *
 * This class handles validation of DNS rule violations on a zone level.
 * It checks for violations like multiple CNAME records with the same name,
 * CNAME records that conflict with other record types, etc.
 *
 * @package Poweradmin
 * @copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright 2010-2025 Poweradmin Development Team
 * @license https://opensource.org/licenses/GPL-3.0 GPL
 */
class DNSViolationValidator
{
    private PDOCommon $db;
    private ConfigurationManager $config;

    /**
     * Constructor
     *
     * @param PDOCommon $db
     * @param ConfigurationManager $config
     */
    public function __construct(PDOCommon $db, ConfigurationManager $config)
    {
        $this->db = $db;
        $this->config = $config;
    }

    /**
     * Validate that the record doesn't create DNS violations
     *
     * @param int $recordId Record ID (0 for new records)
     * @param int $zoneId Zone ID
     * @param string $type Record type
     * @param string $name Record name
     * @param string $content Record content
     *
     * @return ValidationResult ValidationResult object with validation result
     */
    public function validate(int $recordId, int $zoneId, string $type, string $name, string $content): ValidationResult
    {
        // Check specific violations based on record type
        switch ($type) {
            case RecordType::CNAME:
                return $this->validateCNAMEViolations($recordId, $zoneId, $name);
            case RecordType::A:
            case RecordType::AAAA:
            case RecordType::TXT:
            case RecordType::MX:
            case RecordType::NS:
            case RecordType::PTR:
                // Check for conflicts with CNAME
                return $this->validateConflictsWithCNAME($recordId, $zoneId, $name);
            default:
                return ValidationResult::success(true);
        }
    }

    /**
     * Validate that a CNAME record doesn't violate DNS rules
     *
     * @param int $recordId Record ID (0 for new records)
     * @param int $zoneId Zone ID
     * @param string $name Record name
     *
     * @return ValidationResult ValidationResult object with validation result
     */
    private function validateCNAMEViolations(int $recordId, int $zoneId, string $name): ValidationResult
    {
        // Check for duplicate CNAME records with the same name
        $duplicateResult = $this->checkDuplicateCNAME($recordId, $zoneId, $name);
        if (!$duplicateResult->isValid()) {
            return $duplicateResult;
        }

        // Check for conflicts with other record types
        $conflictResult = $this->checkCNAMEConflictsWithOtherTypes($recordId, $zoneId, $name);
        if (!$conflictResult->isValid()) {
            return $conflictResult;
        }

        return ValidationResult::success(true);
    }

    /**
     * Check for duplicate CNAME records with the same name
     *
     * @param int $recordId Record ID (0 for new records)
     * @param int $zoneId Zone ID
     * @param string $name Record name
     *
     * @return ValidationResult ValidationResult object with validation result
     */
    private function checkDuplicateCNAME(int $recordId, int $zoneId, string $name): ValidationResult
    {
        $pdns_db_name = $this->config->get('database', 'pdns_db_name');
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

        // Using native PDO parameter binding for security
        if ($recordId > 0) {
            $query = "SELECT COUNT(*) FROM $records_table
                     WHERE name = :name
                     AND type = 'CNAME'
                     AND domain_id = :zone_id
                     AND id != :record_id";

            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':name', $name, \PDO::PARAM_STR);
            $stmt->bindParam(':zone_id', $zoneId, \PDO::PARAM_INT);
            $stmt->bindParam(':record_id', $recordId, \PDO::PARAM_INT);
        } else {
            $query = "SELECT COUNT(*) FROM $records_table
                     WHERE name = :name
                     AND type = 'CNAME'
                     AND domain_id = :zone_id";

            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':name', $name, \PDO::PARAM_STR);
            $stmt->bindParam(':zone_id', $zoneId, \PDO::PARAM_INT);
        }

        $stmt->execute();
        $count = $stmt->fetchColumn();

        if ($count && $count > 0) {
            return ValidationResult::failure(_('Multiple CNAME records with the same name are not allowed. This would create a DNS violation.'));
        }

        return ValidationResult::success(true);
    }

    /**
     * Check if CNAME conflicts with other record types
     *
     * @param int $recordId Record ID (0 for new records)
     * @param int $zoneId Zone ID
     * @param string $name Record name
     *
     * @return ValidationResult ValidationResult object with validation result
     */
    private function checkCNAMEConflictsWithOtherTypes(int $recordId, int $zoneId, string $name): ValidationResult
    {
        $pdns_db_name = $this->config->get('database', 'pdns_db_name');
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

        if ($recordId > 0) {
            $query = "SELECT type FROM $records_table
                     WHERE name = :name
                     AND type != 'CNAME'
                     AND domain_id = :zone_id
                     AND id != :record_id
                     LIMIT 1";

            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':name', $name, \PDO::PARAM_STR);
            $stmt->bindParam(':zone_id', $zoneId, \PDO::PARAM_INT);
            $stmt->bindParam(':record_id', $recordId, \PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $query = "SELECT type FROM $records_table
                     WHERE name = :name
                     AND type != 'CNAME'
                     AND domain_id = :zone_id
                     LIMIT 1";

            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':name', $name, \PDO::PARAM_STR);
            $stmt->bindParam(':zone_id', $zoneId, \PDO::PARAM_INT);
            $stmt->execute();
        }

        $type = $stmt->fetchColumn();
        if ($type) {
            return ValidationResult::failure(sprintf(_('A CNAME record cannot coexist with other record types for the same name. Found existing %s record.'), $type));
        }

        return ValidationResult::success(true);
    }

    /**
     * Validate that a record doesn't conflict with existing CNAME records
     *
     * @param int $recordId Record ID (0 for new records)
     * @param int $zoneId Zone ID
     * @param string $name Record name
     *
     * @return ValidationResult ValidationResult object with validation result
     */
    private function validateConflictsWithCNAME(int $recordId, int $zoneId, string $name): ValidationResult
    {
        $pdns_db_name = $this->config->get('database', 'pdns_db_name');
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

        if ($recordId > 0) {
            $query = "SELECT id FROM $records_table
                     WHERE name = :name
                     AND type = 'CNAME'
                     AND domain_id = :zone_id
                     AND id != :record_id
                     LIMIT 1";

            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':name', $name, \PDO::PARAM_STR);
            $stmt->bindParam(':zone_id', $zoneId, \PDO::PARAM_INT);
            $stmt->bindParam(':record_id', $recordId, \PDO::PARAM_INT);
            $stmt->execute();
        } else {
            $query = "SELECT id FROM $records_table
                     WHERE name = :name
                     AND type = 'CNAME'
                     AND domain_id = :zone_id
                     LIMIT 1";

            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':name', $name, \PDO::PARAM_STR);
            $stmt->bindParam(':zone_id', $zoneId, \PDO::PARAM_INT);
            $stmt->execute();
        }

        $result = $stmt->fetchColumn();
        if ($result) {
            return ValidationResult::failure(_('This record conflicts with an existing CNAME record with the same name. A CNAME record cannot coexist with other record types.'));
        }

        return ValidationResult::success(true);
    }
}
