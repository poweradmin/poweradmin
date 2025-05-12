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

/**
 * DNS TTL validation service
 *
 * Validates Time To Live (TTL) values according to:
 * - RFC 1034: Domain Names - Concepts and Facilities
 * - RFC 1035: Domain Names - Implementation and Specification
 * - RFC 2181: Clarifications to the DNS Specification (Section 8)
 *
 * TTL values define how long DNS records should be cached by resolvers.
 * According to RFC 2181, TTL values are unsigned 32-bit integers (0-4294967295),
 * although practical implementations typically use much lower values.
 *
 * @package Poweradmin
 * @copyright 2010-2025 Poweradmin Development Team
 * @license https://opensource.org/licenses/GPL-3.0 GPL
 */
class TTLValidator
{
    /**
     * RFC limits and recommendations for TTL values
     */
    private const TTL_MIN = 0;           // Minimum allowed TTL (unsigned)
    private const TTL_MAX = 2147483647;  // Maximum allowed TTL (RFC 2181, signed 32-bit integer)
    private const TTL_MAX_UNSIGNED = 4294967295; // Maximum of unsigned 32-bit integer (RFC 2181)

    // Recommended values based on common practices
    private const TTL_RECOMMENDED_MIN = 300;     // 5 minutes (minimum recommended)
    private const TTL_RECOMMENDED_MAX = 604800;  // 1 week (maximum recommended for most records)
    private const TTL_RECOMMENDED_SOA_MIN = 3600; // 1 hour (for SOA minimum TTL field)

    /**
     * Validate TTL value according to RFC standards and best practices
     *
     * @param mixed $ttl TTL value to validate
     * @param mixed $defaultTtl Default TTL to use if ttl is not provided
     * @param bool $checkRecommended Whether to also check recommended ranges (warning only)
     * @param string $recordType Record type for specialized recommendations (optional)
     *
     * @return ValidationResult Validation result with TTL value, warnings, or error
     */
    public function validate(mixed $ttl, mixed $defaultTtl, bool $checkRecommended = false, string $recordType = ''): ValidationResult
    {
        $warnings = [];

        // If TTL not provided, use default
        if (!isset($ttl) || $ttl === "") {
            $ttlValue = (int)$defaultTtl;

            // Still validate the default value against RFC requirements
            if (!is_numeric($defaultTtl) || $defaultTtl < self::TTL_MIN || $defaultTtl > self::TTL_MAX) {
                return ValidationResult::failure(_('Invalid value for default TTL. It must be a number between 0 and 2147483647.'));
            }

            // Check recommendations for the default value if requested
            if ($checkRecommended) {
                $recommendationResult = $this->checkTtlRecommendations($ttlValue, $recordType);
                if (count($recommendationResult) > 0) {
                    $warnings = $recommendationResult;
                }
            }

            $result = ['ttl' => $ttlValue];
            if (!empty($warnings)) {
                $result['warnings'] = $warnings;
            }

            return ValidationResult::success($result);
        }

        // Validate TTL is numeric and within RFC limits
        if (!is_numeric($ttl)) {
            return ValidationResult::failure(_('Invalid value for TTL field. It must be numeric.'));
        }

        $ttlValue = (int)$ttl;

        // Basic RFC validation - TTL must be a positive 32-bit integer (RFC 2181)
        if ($ttlValue < self::TTL_MIN) {
            return ValidationResult::failure(_('TTL value cannot be negative. It must be 0 or higher.'));
        }

        if ($ttlValue > self::TTL_MAX) {
            return ValidationResult::failure(sprintf(
                _('TTL value exceeds maximum allowed (%d). RFC 2181 limits it to a signed 32-bit integer.'),
                self::TTL_MAX
            ));
        }

        // Check against recommended ranges if requested
        if ($checkRecommended) {
            $warnings = $this->checkTtlRecommendations($ttlValue, $recordType);
        }

        $result = ['ttl' => $ttlValue];
        if (!empty($warnings)) {
            $result['warnings'] = $warnings;
        }

        return ValidationResult::success($result);
    }

    /**
     * Check TTL value against recommendations (not strict RFC requirements)
     *
     * @param int $ttlValue The TTL value to check
     * @param string $recordType Record type for specialized recommendations
     *
     * @return array Warning messages, if any
     */
    private function checkTtlRecommendations(int $ttlValue, string $recordType = ''): array
    {
        $warnings = [];

        // Check if TTL is too low (may cause excessive DNS traffic)
        if ($ttlValue < self::TTL_RECOMMENDED_MIN) {
            $warnings[] = sprintf(
                _('TTL value (%d) is below the recommended minimum of %d seconds (5 minutes). Low TTL values increase DNS traffic.'),
                $ttlValue,
                self::TTL_RECOMMENDED_MIN
            );
        }

        // Check if TTL is too high (may cause long caching periods)
        if ($ttlValue > self::TTL_RECOMMENDED_MAX) {
            $warnings[] = sprintf(
                _('TTL value (%d) is above the recommended maximum of %d seconds (1 week). High TTL values delay DNS updates.'),
                $ttlValue,
                self::TTL_RECOMMENDED_MAX
            );
        }

        // Record type specific recommendations
        if ($recordType === 'SOA' || $recordType === \Poweradmin\Domain\Model\RecordType::SOA) {
            if ($ttlValue < self::TTL_RECOMMENDED_SOA_MIN) {
                $warnings[] = sprintf(
                    _('SOA record TTL (%d) is below the recommended minimum of %d seconds (1 hour).'),
                    $ttlValue,
                    self::TTL_RECOMMENDED_SOA_MIN
                );
            }
        }

        return $warnings;
    }

    /**
     * Validate TTL value for a specific record type
     *
     * @param mixed $ttl TTL value to validate
     * @param mixed $defaultTtl Default TTL to use if ttl is not provided
     * @param string $recordType Record type for specialized recommendations
     *
     * @return ValidationResult Validation result with TTL value, warnings, or error
     */
    public function validateForRecordType(mixed $ttl, mixed $defaultTtl, string $recordType): ValidationResult
    {
        // Delegate to the main validate method with record type parameter
        return $this->validate($ttl, $defaultTtl, true, $recordType);
    }
}
