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

use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * RESINFO (Resolver Information) record validator
 *
 * Validates RESINFO records according to RFC 9606. RESINFO uses the same wire
 * format as TXT (one or more character-strings) but conveys resolver capabilities
 * such as "qnamemin" or "exterr=15-17". Each character-string must be quoted
 * and is limited to 255 bytes (DNS character-string limit).
 *
 * Supported by PowerDNS Authoritative from 5.1.
 *
 * Example: "qnamemin exterr=15-17"
 */
class RESINFORecordValidator implements DnsRecordValidatorInterface
{
    private HostnameValidator $hostnameValidator;
    private TTLValidator $ttlValidator;

    public function __construct(ConfigurationManager $config)
    {
        $this->hostnameValidator = new HostnameValidator($config);
        $this->ttlValidator = new TTLValidator();
    }

    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL, ...$args): ValidationResult
    {
        $hostnameResult = $this->hostnameValidator->validate($name, true);
        if (!$hostnameResult->isValid()) {
            return $hostnameResult;
        }
        $validatedName = $hostnameResult->getData()['hostname'];

        $contentResult = $this->validateContent($content);
        if (!$contentResult->isValid()) {
            return $contentResult;
        }

        $ttlResult = $this->ttlValidator->validate($ttl, $defaultTTL);
        if (!$ttlResult->isValid()) {
            return $ttlResult;
        }
        $ttlData = $ttlResult->getData();
        $validatedTtl = is_array($ttlData) && isset($ttlData['ttl']) ? $ttlData['ttl'] : $ttlData;

        $prioResult = $this->validatePriority($prio);
        if (!$prioResult->isValid()) {
            return $prioResult;
        }

        return ValidationResult::success([
            'content' => $content,
            'name' => $validatedName,
            'prio' => $prioResult->getData(),
            'ttl' => $validatedTtl,
        ]);
    }

    private function validateContent(string $content): ValidationResult
    {
        $trimmed = trim($content);
        if ($trimmed === '') {
            return ValidationResult::failure(_('RESINFO record content must not be empty.'));
        }

        if (!preg_match('/^[[:print:]]+$/', $trimmed)) {
            return ValidationResult::failure(_('Invalid characters in content field.'));
        }

        $strings = CharacterStringParser::parse($trimmed);
        if ($strings === null) {
            return ValidationResult::failure(_('RESINFO record content must be one or more quoted character-strings (each up to 255 bytes).'));
        }

        return ValidationResult::success(true);
    }

    private function validatePriority(mixed $prio): ValidationResult
    {
        if ($prio === null || $prio === '') {
            return ValidationResult::success(0);
        }
        if (is_numeric($prio) && (int) $prio === 0) {
            return ValidationResult::success(0);
        }
        return ValidationResult::failure(_('Priority must be 0 for RESINFO records.'));
    }
}
