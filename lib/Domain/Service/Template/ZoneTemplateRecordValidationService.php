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

namespace Poweradmin\Domain\Service\Template;

use Poweradmin\Domain\Model\RecordType;
use Poweradmin\Domain\Service\DnsValidation\CNAMERecordValidator;
use Poweradmin\Domain\Service\DnsValidation\DnsValidatorRegistry;
use Poweradmin\Domain\Service\DnsValidation\SOARecordValidator;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Domain\Utility\DnsHelper;

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
 */
final class ZoneTemplateRecordValidationService
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
        // Applying a template writes its records without validating them again
        if (!$this->validatorRegistry->isKnownType($type)) {
            return ValidationResult::failure(_('Invalid record type.'));
        }
        if (!$this->validatorRegistry->isTypeSupportedByServer($type)) {
            return ValidationResult::failure(sprintf(_('The connected PowerDNS version cannot load %s records.'), $type));
        }

        // "@" is the apex; the hostname validator no longer accepts it as a literal name
        $resolvedName = $this->resolvePlaceholders($name === '@' ? '[ZONE]' : $name);
        $resolvedContent = $this->resolvePlaceholders($content);

        if (preg_match('/[\x00-\x1F\x7F-\x{9F}]/u', $resolvedContent) !== 0) {
            return ValidationResult::failure(_('Invalid characters in content field.'));
        }

        // Zone creation completes short SOA rdata with the configured timers, so a
        // template carrying only the leading fields is legitimate.
        if ($type === RecordType::SOA) {
            $missingTimers = 7 - count(explode(' ', $resolvedContent));
            if ($missingTimers > 0) {
                $resolvedContent .= ' ' . implode(' ', array_fill(0, $missingTimers, self::SAMPLE_TIMER));
            }
        }

        // Template content is applied as stored, and PowerDNS reads the NID preference from it
        if ($type === RecordType::NID && preg_match('/^\S+\s+\S+$/', trim($resolvedContent)) !== 1) {
            return ValidationResult::failure(_('NID record must contain preference and node identifier separated by space.'));
        }

        $result = $this->runValidator($type, $resolvedName, $resolvedContent, $ttl, $prio, $defaultTtl);

        return $result->isValid() ? ValidationResult::success(true) : $result;
    }

    /**
     * Check that a template record keeps the CNAME rule with the template's other
     * records: a CNAME owns its name alone, and never sits at the zone apex.
     *
     * An edit that keeps the owner name and whether the record is a CNAME adds no new
     * conflict, so a template saved before this check can still take a TTL or content change.
     *
     * @param array<int, array<string, mixed>> $siblings The template's stored records
     * @param int $recordId Stored record being edited, left out of the comparison; 0 for a new record
     * @param array<string, mixed>|null $stored The stored row being edited, null for a new record
     */
    public function validateCnameSiblings(string $name, string $type, array $siblings, int $recordId = 0, ?array $stored = null): ValidationResult
    {
        $owner = $this->ownerName($name);
        $isCname = strtoupper($type) === RecordType::CNAME;
        if (
            $stored !== null
            && $this->ownerName((string)($stored['name'] ?? '')) === $owner
            && (strtoupper((string)($stored['type'] ?? '')) === RecordType::CNAME) === $isCname
        ) {
            return ValidationResult::success(true);
        }
        if ($isCname && $owner === self::SAMPLE_ZONE) {
            return ValidationResult::failure(sprintf(_('A CNAME record cannot coexist with other record types for the same name. Found existing %s record.'), RecordType::SOA));
        }

        foreach ($siblings as $sibling) {
            if ($recordId !== 0 && (int)($sibling['id'] ?? 0) === $recordId) {
                continue;
            }
            if ($this->ownerName((string)($sibling['name'] ?? '')) !== $owner) {
                continue;
            }
            $siblingType = strtoupper((string)($sibling['type'] ?? ''));
            if ($isCname && $siblingType === RecordType::CNAME) {
                return ValidationResult::failure(_('Multiple CNAME records with the same name are not allowed. This would create a DNS violation.'));
            }
            if ($isCname) {
                return ValidationResult::failure(sprintf(_('A CNAME record cannot coexist with other record types for the same name. Found existing %s record.'), $siblingType));
            }
            if ($siblingType === RecordType::CNAME) {
                return ValidationResult::failure(_('This record conflicts with an existing CNAME record with the same name. A CNAME record cannot coexist with other record types.'));
            }
        }

        return ValidationResult::success(true);
    }

    /**
     * The CNAMEs in a set of template records that break the CNAME rule: one that shares
     * its name with another distinct record, or one at the zone apex.
     *
     * @param array<int, array<string, mixed>> $records
     * @return list<string> "name CNAME" for each conflicting CNAME, with the name as stored
     */
    public static function conflictingCnames(array $records): array
    {
        $entriesAtOwner = [];
        foreach ($records as $record) {
            $type = strtoupper((string)($record['type'] ?? ''));
            $entriesAtOwner[self::ownerName((string)($record['name'] ?? ''))][$type . ' ' . ($record['content'] ?? '')] = true;
        }

        $conflicts = [];
        foreach ($records as $record) {
            if (strtoupper((string)($record['type'] ?? '')) !== RecordType::CNAME) {
                continue;
            }
            $owner = self::ownerName((string)($record['name'] ?? ''));
            if ($owner === self::SAMPLE_ZONE || count($entriesAtOwner[$owner] ?? []) > 1) {
                $conflicts[] = $record['name'] . ' ' . RecordType::CNAME;
            }
        }
        return array_values(array_unique($conflicts));
    }

    /**
     * The owner name a template name resolves to in the sample zone, so "www",
     * "www.[ZONE]" and "WWW.[ZONE]" compare equal.
     */
    private static function ownerName(string $name): string
    {
        $resolved = self::resolvePlaceholders($name === '@' ? '[ZONE]' : $name);
        return strtolower(DnsHelper::restoreZoneSuffix($resolved, self::SAMPLE_ZONE));
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
            return $validator->validateSoa($content, $name, $prio, $ttl, $defaultTtl, self::SAMPLE_HOSTMASTER, self::SAMPLE_ZONE);
        }

        // The remaining CNAME checks look for conflicting records in a zone the
        // template record does not belong to yet.
        if ($type === RecordType::CNAME && $validator instanceof CNAMERecordValidator) {
            return $validator->validateSyntax($content, $name, $prio, $ttl, $defaultTtl);
        }

        return $validator->validate($content, $name, $prio, $ttl, $defaultTtl);
    }

    private static function resolvePlaceholders(string $value): string
    {
        return str_replace(
            array_keys(self::PLACEHOLDER_SAMPLES),
            array_values(self::PLACEHOLDER_SAMPLES),
            $value
        );
    }
}
