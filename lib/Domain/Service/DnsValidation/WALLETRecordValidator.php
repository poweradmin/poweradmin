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
 * WALLET record validator
 *
 * Validates WALLET records as understood by PowerDNS Authoritative 5.1+.
 * WALLET uses the DNS <character-string> wire format (the same as TXT),
 * conventionally carrying a "<coin-symbol> <wallet-address>" pair, e.g.
 * "BTC bc1qxy2kgdygjrsqtzq2n0yrf2493p83kkfjhx0wlh".
 *
 * The wire format itself accepts any quoted character-strings. This validator
 * enforces the wire format strictly and emits a warning if the payload does not
 * match the conventional "<coin> <address>" shape, leaving room for future
 * spec evolution without rejecting otherwise-valid records.
 */
class WALLETRecordValidator implements DnsRecordValidatorInterface
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
        $warnings = $contentResult->hasWarnings() ? $contentResult->getWarnings() : [];

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

        return ValidationResult::success(
            [
                'content' => $content,
                'name' => $validatedName,
                'prio' => $prioResult->getData(),
                'ttl' => $validatedTtl,
            ],
            $warnings
        );
    }

    private function validateContent(string $content): ValidationResult
    {
        $trimmed = trim($content);
        if ($trimmed === '') {
            return ValidationResult::failure(_('WALLET record content must not be empty.'));
        }

        if (!preg_match('/^[[:print:]]+$/', $trimmed)) {
            return ValidationResult::failure(_('Invalid characters in content field.'));
        }

        $strings = CharacterStringParser::parse($trimmed);
        if ($strings === null) {
            return ValidationResult::failure(_('WALLET record content must be one or more quoted character-strings (each up to 255 bytes).'));
        }

        $payload = implode(' ', $strings);
        $warnings = [];
        if (!preg_match('/^\S+\s+\S+/', $payload)) {
            $warnings[] = _('WALLET records conventionally contain a coin symbol and wallet address separated by whitespace, e.g. "BTC bc1q...".');
        }

        return ValidationResult::success(true, $warnings);
    }

    private function validatePriority(mixed $prio): ValidationResult
    {
        if ($prio === null || $prio === '') {
            return ValidationResult::success(0);
        }
        if (is_numeric($prio) && (int) $prio === 0) {
            return ValidationResult::success(0);
        }
        return ValidationResult::failure(_('Priority must be 0 for WALLET records.'));
    }
}
