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

namespace Poweradmin\Domain\Service;

use Poweradmin\Domain\Model\RecordType;
use Poweradmin\Domain\Service\DnsValidation\CNAMERecordValidator;
use Poweradmin\Domain\Service\DnsValidation\DnsValidatorRegistry;
use Poweradmin\Domain\Service\DnsValidation\SOARecordValidator;
use Poweradmin\Domain\Service\Validation\ValidationResult;

/**
 * Validates zone template records before they are stored.
 *
 * A template record is not a finished DNS record - it carries placeholders that are
 * resolved per zone at creation time. It is checked by resolving those placeholders
 * against a sample zone and validating the result with the normal per-type validators,
 * which answers whether the record could ever be valid rather than whether it is valid
 * for one particular zone.
 *
 * The result reports validity only. Resolved values are sample data and must never be
 * stored, so callers keep the values they were given.
 *
 * @package Poweradmin
 * @copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright 2010-2026 Poweradmin Development Team
 * @license https://opensource.org/licenses/GPL-3.0 GPL
 */
class ZoneTemplateRecordValidationService
{
    private const SAMPLE_ZONE = 'example.com';
    private const SAMPLE_HOSTMASTER = 'hostmaster.example.com';
    private const SAMPLE_TIMER = '3600';

    /**
     * Stand-ins for the placeholders zone creation resolves. Sample values are used
     * instead of this install's configuration so a record is judged on its own syntax
     * and an unset nameserver or hostmaster setting cannot fail an otherwise sound record.
     */
    private const PLACEHOLDER_SAMPLES = [
        '[ZONE]' => self::SAMPLE_ZONE,
        '[DOMAIN]' => 'example',
        '[TLD]' => 'com',
        '[SERIAL]' => '2024010100',
        '[UNIXTIME]' => '1700000000',
        '[COUNTER]' => '1',
        '[NS1]' => 'ns1.example.com',
        '[NS2]' => 'ns2.example.com',
        '[NS3]' => 'ns3.example.com',
        '[NS4]' => 'ns4.example.com',
        '[HOSTMASTER]' => self::SAMPLE_HOSTMASTER,
        '[SOA_REFRESH]' => self::SAMPLE_TIMER,
        '[SOA_RETRY]' => self::SAMPLE_TIMER,
        '[SOA_EXPIRE]' => self::SAMPLE_TIMER,
        '[SOA_MINIMUM]' => self::SAMPLE_TIMER,
    ];

    private DnsValidatorRegistry $validatorRegistry;

    public function __construct(DnsValidatorRegistry $validatorRegistry)
    {
        $this->validatorRegistry = $validatorRegistry;
    }

    /**
     * Check whether a template record could produce a valid DNS record.
     *
     * @param string $name Name part of the template record
     * @param string $type Record type
     * @param string $content Content part of the template record
     * @param mixed $ttl TTL value
     * @param mixed $prio Priority value
     * @param int $defaultTtl Default TTL used when the record has none
     *
     * @return ValidationResult Successful result carries true, never the resolved values
     */
    public function validate(
        string $name,
        string $type,
        string $content,
        mixed $ttl,
        mixed $prio,
        int $defaultTtl
    ): ValidationResult {
        $resolvedName = $this->resolvePlaceholders($name);
        $resolvedContent = $this->resolvePlaceholders($content);

        // Zone creation completes short SOA rdata with the configured timers, so a
        // template carrying only the leading fields is legitimate.
        if ($type === RecordType::SOA) {
            $missingTimers = 7 - count(explode(' ', $resolvedContent));
            if ($missingTimers > 0) {
                $resolvedContent .= ' ' . implode(' ', array_fill(0, $missingTimers, self::SAMPLE_TIMER));
            }
        }

        // An unknown placeholder leaves nothing dependable to check, so the record is
        // stored as it stands rather than rejected on a guess.
        if ($this->hasUnresolvedPlaceholder($resolvedName) || $this->hasUnresolvedPlaceholder($resolvedContent)) {
            return ValidationResult::success(true);
        }

        $result = $this->runValidator($type, $resolvedName, $resolvedContent, $ttl, $prio, $defaultTtl);

        return $result->isValid() ? ValidationResult::success(true) : $result;
    }

    private function runValidator(
        string $type,
        string $name,
        string $content,
        mixed $ttl,
        mixed $prio,
        int $defaultTtl
    ): ValidationResult {
        $validator = $this->validatorRegistry->getValidator($type);

        if ($type === RecordType::SOA && $validator instanceof SOARecordValidator) {
            return $validator->validate($content, $name, $prio, $ttl, $defaultTtl, self::SAMPLE_HOSTMASTER, self::SAMPLE_ZONE);
        }

        // The remaining CNAME checks look for conflicting records in a zone the
        // template record does not belong to yet.
        if ($type === RecordType::CNAME && $validator instanceof CNAMERecordValidator) {
            return $validator->validateSyntax($content, $name, $prio, $ttl, $defaultTtl);
        }

        return $validator->validate($content, $name, $prio, $ttl, $defaultTtl);
    }

    private function resolvePlaceholders(string $value): string
    {
        return str_replace(
            array_keys(self::PLACEHOLDER_SAMPLES),
            array_values(self::PLACEHOLDER_SAMPLES),
            $value
        );
    }

    private function hasUnresolvedPlaceholder(string $value): bool
    {
        return preg_match('/\[[A-Za-z0-9_]+\]/', $value) === 1;
    }
}
