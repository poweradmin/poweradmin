<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2026 Poweradmin Development Team
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
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Service\Validation\ValidationResult;

/**
 * DNS Violation Validator
 *
 * This class handles validation of DNS rule violations on a zone level.
 * It checks for violations like multiple CNAME records with the same name,
 * CNAME records that conflict with other record types, etc.
 */
class DNSViolationValidator
{
    private RecordRepositoryInterface $recordRepository;

    public function __construct(RecordRepositoryInterface $recordRepository)
    {
        $this->recordRepository = $recordRepository;
    }

    /**
     * Check if this is a new record (sentinel value -1)
     */
    private function isNewRecord(int|string $recordId): bool
    {
        return $recordId === -1;
    }

    /**
     * Check if record ID matches (works for both int and string IDs)
     */
    private function recordIdMatches(int|string $recordId, mixed $otherIdRaw): bool
    {
        return (string)$recordId === (string)($otherIdRaw ?? '');
    }

    /**
     * Validate that the record doesn't create DNS violations
     *
     * @param int|string $recordId Record ID (-1 for new records, string in API backend mode)
     * @param int $zoneId Zone ID
     * @param string $type Record type
     * @param string $name Record name
     * @param string $content Record content
     *
     * @return ValidationResult ValidationResult object with validation result
     */
    public function validate(int|string $recordId, int $zoneId, string $type, string $name, string $content): ValidationResult
    {
        switch ($type) {
            case RecordType::CNAME:
                return $this->validateCNAMEViolations($recordId, $zoneId, $name);
            case RecordType::A:
            case RecordType::AAAA:
            case RecordType::TXT:
            case RecordType::MX:
            case RecordType::NS:
            case RecordType::PTR:
                return $this->validateConflictsWithCNAME($recordId, $zoneId, $name);
            default:
                return ValidationResult::success(true);
        }
    }

    private function validateCNAMEViolations(int|string $recordId, int $zoneId, string $name): ValidationResult
    {
        $duplicateResult = $this->checkDuplicateCNAME($recordId, $zoneId, $name);
        if (!$duplicateResult->isValid()) {
            return $duplicateResult;
        }

        $conflictResult = $this->checkCNAMEConflictsWithOtherTypes($recordId, $zoneId, $name);
        if (!$conflictResult->isValid()) {
            return $conflictResult;
        }

        return ValidationResult::success(true);
    }

    private function checkDuplicateCNAME(int|string $recordId, int $zoneId, string $name): ValidationResult
    {
        $records = $this->recordRepository->getRecordsByDomainId($zoneId, 'CNAME');
        foreach ($records as $r) {
            if ($r['name'] === $name && ($this->isNewRecord($recordId) || !$this->recordIdMatches($recordId, $r['id'] ?? null))) {
                return ValidationResult::failure(_('Multiple CNAME records with the same name are not allowed. This would create a DNS violation.'));
            }
        }
        return ValidationResult::success(true);
    }

    private function checkCNAMEConflictsWithOtherTypes(int|string $recordId, int $zoneId, string $name): ValidationResult
    {
        $records = $this->recordRepository->getRecordsByDomainId($zoneId);
        foreach ($records as $r) {
            if ($r['name'] === $name && $r['type'] !== 'CNAME' && ($this->isNewRecord($recordId) || !$this->recordIdMatches($recordId, $r['id'] ?? null))) {
                return ValidationResult::failure(sprintf(_('A CNAME record cannot coexist with other record types for the same name. Found existing %s record.'), $r['type']));
            }
        }
        return ValidationResult::success(true);
    }

    private function validateConflictsWithCNAME(int|string $recordId, int $zoneId, string $name): ValidationResult
    {
        $records = $this->recordRepository->getRecordsByDomainId($zoneId, 'CNAME');
        foreach ($records as $r) {
            if ($r['name'] === $name && ($this->isNewRecord($recordId) || !$this->recordIdMatches($recordId, $r['id'] ?? null))) {
                return ValidationResult::failure(_('This record conflicts with an existing CNAME record with the same name. A CNAME record cannot coexist with other record types.'));
            }
        }
        return ValidationResult::success(true);
    }
}
