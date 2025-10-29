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

use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\DbCompat;
use Poweradmin\Infrastructure\Database\PDOCommon;
use Poweradmin\Infrastructure\Database\PdnsTable;

/**
 * Zone Validation Service
 *
 * Validates zone records for common issues that could cause DNSSEC signing to fail.
 * Performs comprehensive pre-flight checks before DNSSEC operations.
 */
class ZoneValidationService
{
    private PDOCommon $db;
    private string $pdnsDbName;
    private string $dbType;

    public function __construct(PDOCommon $db)
    {
        $this->db = $db;

        // Get PowerDNS database name from configuration
        $config = ConfigurationManager::getInstance();
        $this->pdnsDbName = $config->get('database', 'pdns_db_name', 'pdns');
        $this->dbType = $config->get('database', 'type');
    }

    /**
     * Get the full table name with PowerDNS database prefix
     */
    private function getTableName(PdnsTable $table): string
    {
        return $table->getFullName($this->pdnsDbName);
    }

    /**
     * Validate zone records before DNSSEC signing
     *
     * Performs pre-flight checks:
     * - SOA record validation (must exist and be valid)
     * - NS record validation (at least one NS record required at zone apex)
     *
     * Note: We only validate critical requirements for DNSSEC signing.
     * Other checks (TXT quoting, duplicates, TTL consistency) are omitted
     * as they don't prevent DNSSEC signing from succeeding.
     *
     * Delegation NS records (e.g., child.example.com NS ...) are not counted
     * as they represent subdomain delegations, not zone authority.
     *
     * @param int $zoneId Zone ID to validate
     * @param string $zoneName Zone name (FQDN)
     * @return array Array with 'valid' boolean and 'issues' array of problem descriptions
     */
    public function validateZoneForDnssec(int $zoneId, string $zoneName): array
    {
        $issues = [];

        // Critical checks - these MUST pass for DNSSEC signing
        $soaIssues = $this->checkSoaRecord($zoneId, $zoneName);
        if (!empty($soaIssues)) {
            $issues = array_merge($issues, $soaIssues);
        }

        $nsIssues = $this->checkNsRecords($zoneId, $zoneName);
        if (!empty($nsIssues)) {
            $issues = array_merge($issues, $nsIssues);
        }

        return [
            'valid' => empty(array_filter($issues, fn($issue) => $issue['severity'] === 'error' || $issue['severity'] === 'critical')),
            'issues' => $issues
        ];
    }

    /**
     * Check SOA record
     *
     * Validates that an active (non-disabled) SOA record exists at the zone apex.
     * Disabled SOA records are ignored as PowerDNS will not use them.
     *
     * @param int $zoneId Zone ID
     * @param string $zoneName Zone name
     * @return array Array of issues found
     */
    private function checkSoaRecord(int $zoneId, string $zoneName): array
    {
        $issues = [];

        $recordsTable = $this->getTableName(PdnsTable::RECORDS);
        $query = "SELECT id, name, content
                  FROM {$recordsTable}
                  WHERE domain_id = :zone_id
                  AND type = 'SOA'
                  AND disabled = " . DbCompat::boolFalse($this->dbType);

        $stmt = $this->db->prepare($query);
        $stmt->execute([':zone_id' => $zoneId]);
        $soaRecords = $stmt->fetchAll();

        if (empty($soaRecords)) {
            $issues[] = [
                'type' => 'missing_soa',
                'severity' => 'critical',
                'message' => _('No SOA record present, or active, in zone. This is required for DNSSEC.'),
                'suggestion' => _('Add an SOA record to the zone before attempting to sign it.')
            ];
        } elseif (count($soaRecords) > 1) {
            $issues[] = [
                'type' => 'multiple_soa',
                'severity' => 'error',
                'message' => sprintf(_('Zone has %d SOA records. Only one SOA record is allowed per zone.'), count($soaRecords)),
                'suggestion' => _('Remove duplicate SOA records, keeping only one.')
            ];
        } else {
            $soa = $soaRecords[0];

            // Check if SOA is at apex
            if (rtrim($soa['name'], '.') !== rtrim($zoneName, '.')) {
                $issues[] = [
                    'type' => 'soa_not_at_apex',
                    'severity' => 'error',
                    'record_name' => $soa['name'],
                    'message' => sprintf(_('SOA record not at apex. Found at "%s" but zone is "%s".'), $soa['name'], $zoneName),
                    'suggestion' => _('Move the SOA record to the zone apex.')
                ];
            }

            // Check SOA content format
            if (!$this->validateSoaContent($soa['content'])) {
                $issues[] = [
                    'type' => 'invalid_soa_content',
                    'severity' => 'error',
                    'record_name' => $soa['name'],
                    'message' => _('SOA record has invalid content format.'),
                    'suggestion' => _('SOA format should be: primary-ns hostmaster serial refresh retry expire minimum')
                ];
            }
        }

        return $issues;
    }

    /**
     * Validate SOA content format
     *
     * @param string $content SOA content
     * @return bool True if valid
     */
    private function validateSoaContent(string $content): bool
    {
        $parts = preg_split('/\s+/', trim($content));
        // SOA should have 7 parts: primary-ns hostmaster serial refresh retry expire minimum
        return count($parts) >= 7;
    }

    /**
     * Check NS records at zone apex
     *
     * Verifies that at least one NS record exists at the zone apex.
     * Delegation NS records (e.g., child.example.com NS ...) are not counted.
     *
     * @param int $zoneId Zone ID
     * @param string $zoneName Zone name (FQDN)
     * @return array Array of issues found
     */
    private function checkNsRecords(int $zoneId, string $zoneName): array
    {
        $issues = [];
        $apex = rtrim($zoneName, '.');

        $recordsTable = $this->getTableName(PdnsTable::RECORDS);
        $query = "SELECT name
                  FROM {$recordsTable}
                  WHERE domain_id = :zone_id
                  AND type = 'NS'
                  AND disabled = " . DbCompat::boolFalse($this->dbType);

        $stmt = $this->db->prepare($query);
        $stmt->execute([':zone_id' => $zoneId]);
        $nsRecords = $stmt->fetchAll();

        // Count only apex NS records (exclude delegations)
        $apexNsCount = 0;
        foreach ($nsRecords as $record) {
            if (rtrim($record['name'], '.') === $apex) {
                $apexNsCount++;
            }
        }

        if ($apexNsCount == 0) {
            $issues[] = [
                'type' => 'missing_apex_ns',
                'severity' => 'error',
                'message' => _('Zone has no NS (Name Server) records at the apex. At least one apex NS record is required for DNSSEC.'),
                'suggestion' => _('Add NS records at the zone apex for your authoritative name servers.')
            ];
        }

        return $issues;
    }

    /**
     * Get a formatted error message from validation results
     *
     * @param array $validationResult Result from validateZoneForDnssec()
     * @return string Formatted error message for display
     */
    public function getFormattedErrorMessage(array $validationResult): string
    {
        if ($validationResult['valid']) {
            return '';
        }

        $messages = [];
        $hasErrors = false;
        $hasWarnings = false;

        // Separate critical/errors from warnings
        $critical = [];
        $errors = [];
        $warnings = [];

        foreach ($validationResult['issues'] as $issue) {
            switch ($issue['severity']) {
                case 'critical':
                    $critical[] = $issue;
                    $hasErrors = true;
                    break;
                case 'error':
                    $errors[] = $issue;
                    $hasErrors = true;
                    break;
                case 'warning':
                    $warnings[] = $issue;
                    $hasWarnings = true;
                    break;
            }
        }

        if ($hasErrors) {
            $messages[] = _('DNSSEC signing cannot proceed due to the following errors:');
            $messages[] = '';

            foreach ($critical as $issue) {
                $messages[] = sprintf('❌ [CRITICAL] %s', $issue['message']);
                if (!empty($issue['suggestion'])) {
                    $messages[] = sprintf('   💡 %s', $issue['suggestion']);
                }
                $messages[] = '';
            }

            foreach ($errors as $issue) {
                $messages[] = sprintf('❌ [ERROR] %s', $issue['message']);
                if (!empty($issue['suggestion'])) {
                    $messages[] = sprintf('   💡 %s', $issue['suggestion']);
                }
                $messages[] = '';
            }
        }

        if ($hasWarnings && !empty($warnings)) {
            if ($hasErrors) {
                $messages[] = _('Additionally, the following warnings were found:');
            } else {
                $messages[] = _('The following warnings were found:');
            }
            $messages[] = '';

            foreach ($warnings as $issue) {
                $messages[] = sprintf('⚠️  [WARNING] %s', $issue['message']);
                if (!empty($issue['suggestion'])) {
                    $messages[] = sprintf('   💡 %s', $issue['suggestion']);
                }
                $messages[] = '';
            }
        }

        return implode("\n", $messages);
    }
}
