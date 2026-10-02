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

/**
 * Default DNS record validator for record types that don't have specific validation
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class DefaultRecordValidator implements DnsRecordValidatorInterface
{
    private TTLValidator $ttlValidator;

    /**
     * @param string $recordType The type being validated; a TYPE<number> type only takes generic data
     */
    public function __construct(ConfigurationManager $_config, private readonly string $recordType = '')
    {
        // ConfigurationManager parameter is kept for interface consistency
        $this->ttlValidator = new TTLValidator();
    }

    /**
     * Validate a DNS record
     *
     * @param string $content The content part of the record
     * @param string $name The name part of the record
     * @param mixed $prio The priority value (if applicable)
     * @param int|string|null $ttl The TTL value
     * @param int $defaultTTL The default TTL to use if not specified
     *
     * @return ValidationResult Validation result with data or errors
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL, ...$args): ValidationResult
    {
        // Validate content - just ensure it's not empty
        if (empty(trim($content))) {
            return ValidationResult::failure(_('Content field cannot be empty.'));
        }

        // Make sure content has valid characters
        $printableResult = StringValidator::validatePrintable($content);
        if (!$printableResult->isValid()) {
            return $printableResult;
        }

        $trimmed = trim($content);
        // PowerDNS reads a numbered type only in the generic form
        $mustBeGeneric = preg_match('/^TYPE\\d+$/i', $this->recordType) === 1;
        if (($mustBeGeneric || str_starts_with($trimmed, '\\#')) && !self::isValidGenericContent($trimmed)) {
            return ValidationResult::failure(_('Generic record data must be "\# <length> <hex>" with the length matching the hex data.'));
        }

        // Validate TTL
        $ttlResult = $this->ttlValidator->validate($ttl, $defaultTTL);
        if (!$ttlResult->isValid()) {
            return $ttlResult;
        }
        $ttlData = $ttlResult->getData();
        $validatedTtl = is_array($ttlData) && isset($ttlData['ttl']) ? $ttlData['ttl'] : $ttlData;

        // For generic records, priority is always 0 unless specified
        $priority = ($prio !== '' && $prio !== null) ? (int)$prio : 0;

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'ttl' => $validatedTtl,
            'prio' => $priority
        ]);
    }

    /**
     * RFC 3597 generic rdata: "\# <length> <hex>", where the hex may be split by
     * whitespace and must decode to exactly <length> octets ("\# 0" has no data).
     * RDLENGTH is 16 bits, so the length cannot exceed 65535.
     */
    private static function isValidGenericContent(string $content): bool
    {
        if (!preg_match('/^\\\\#\s+(\d{1,5})(?:\s+([0-9A-Fa-f\s]*))?$/', $content, $matches)) {
            return false;
        }

        $length = (int)$matches[1];
        $hex = preg_replace('/\s+/', '', $matches[2] ?? '');

        return $length <= 65535 && strlen($hex) % 2 === 0 && strlen($hex) / 2 === $length;
    }
}
