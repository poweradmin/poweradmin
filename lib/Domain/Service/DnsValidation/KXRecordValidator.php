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
 * KX Record Validator
 *
 * KX (Key Exchanger) records provide an authenticatable method of delegating authorization
 * for one node to provide key exchange services on behalf of one or more nodes. They are
 * similar in structure to MX records but are used for key exchange delegation rather than
 * mail exchange.
 *
 * Format: <preference> <exchanger>
 *
 * Where:
 * - preference: A 16-bit unsigned integer (0-65535) indicating the preference
 *   Lower values have higher preference, similar to MX record priority.
 * - exchanger: A domain name that specifies the DNS name of the host willing to
 *   act as a key exchanger for the owner name.
 *
 * Example: 10 kx.example.com
 *
 * Important security note: RFC 2230 specifies that KX records MUST be signed using
 * DNSSEC and that unsigned KX records MUST be ignored to avoid security vulnerabilities.
 * Systems not implementing Secure DNS should ignore KX records.
 *
 * @see https://datatracker.ietf.org/doc/html/rfc2230 RFC 2230: Key Exchange Delegation Record for the DNS
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class KXRecordValidator implements DnsRecordValidatorInterface
{
    private ConfigurationManager $config;
    private TTLValidator $ttlValidator;
    private HostnameValidator $hostnameValidator;

    public function __construct(ConfigurationManager $config)
    {
        $this->config = $config;
        $this->ttlValidator = new TTLValidator();
        $this->hostnameValidator = new HostnameValidator($config);
    }

    /**
     * Validate KX record according to RFC 2230
     *
     * @param string $content Key exchanger hostname (exchanger field)
     * @param string $name Domain name for the KX record (owner name)
     * @param mixed $prio Preference value (0-65535, lower values have higher preference)
     * @param int|string|null $ttl TTL value
     * @param int $defaultTTL Default TTL to use if not specified
     *
     * @return ValidationResult ValidationResult containing validated data or error messages
     *
     * @see https://datatracker.ietf.org/doc/html/rfc2230 RFC 2230, Section 3.1
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL): ValidationResult
    {
        $errors = [];

        // Validate content (key exchanger hostname according to RFC 2230)
        $contentResult = $this->hostnameValidator->validate($content, false);
        if (!$contentResult->isValid()) {
            return ValidationResult::errors(
                array_merge([_('Invalid key exchanger hostname. The exchanger field must be a valid domain name.')], $contentResult->getErrors())
            );
        }
        $contentData = $contentResult->getData();
        $content = $contentData['hostname'];

        // Validate name (domain name)
        $nameResult = $this->hostnameValidator->validate($name, true);
        if (!$nameResult->isValid()) {
            return $nameResult;
        }
        $nameData = $nameResult->getData();
        $name = $nameData['hostname'];

        // Validate priority
        $prioResult = $this->validatePriority($prio);
        if (!$prioResult->isValid()) {
            $errors[] = $prioResult->getFirstError();
            return ValidationResult::errors($errors);
        }
        $validatedPrio = $prioResult->getData();

        // Validate TTL
        $ttlResult = $this->ttlValidator->validate($ttl, $defaultTTL);
        if (!$ttlResult->isValid()) {
            return $ttlResult;
        }
        $ttlData = $ttlResult->getData();
        $validatedTtl = is_array($ttlData) && isset($ttlData['ttl']) ? $ttlData['ttl'] : $ttlData;

        // Add security warnings according to RFC 2230
        $warnings = [
            _('IMPORTANT: RFC 2230 requires KX records to be signed using DNSSEC. Unsigned KX records MUST be ignored for security reasons.'),
            _('Systems not implementing Secure DNS should ignore KX records entirely according to RFC 2230.')
        ];

        // Add operational warnings for the key exchanger
        if ($content !== $name) {
            $warnings[] = _('Ensure that the key exchanger host has appropriate forward and reverse DNS records configured.');
            $warnings[] = _('The key exchanger host should have appropriate A/AAAA records for type A/AAAA additional section processing.');
        }

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => $validatedPrio,
            'ttl' => $validatedTtl
        ], $warnings);
    }

    /**
     * Validate preference value for KX records (RFC 2230)
     *
     * KX records require a numeric preference value between 0 and 65535.
     * Lower values indicate higher preference, similar to MX record priorities.
     *
     * @param mixed $prio Preference value
     *
     * @return ValidationResult ValidationResult containing validated preference or error message
     *
     * @see https://datatracker.ietf.org/doc/html/rfc2230 RFC 2230, Section 3.1
     */
    private function validatePriority(mixed $prio): ValidationResult
    {
        // If preference value is not provided or empty, use default of 10
        // This follows the common practice for priority/preference fields in DNS
        if (!isset($prio) || $prio === "") {
            return ValidationResult::success(10);
        }

        // Preference must be a 16-bit unsigned integer (0-65535) per RFC 2230
        if (is_numeric($prio) && intval($prio) >= 0 && intval($prio) <= 65535) {
            return ValidationResult::success(intval($prio));
        }

        return ValidationResult::failure(_('Invalid value for KX preference field. Must be between 0 and 65535.'));
    }

    /**
     * Legacy adapter method for backward compatibility
     *
     * This method maintains compatibility with code that may still use
     * the legacy validation approach.
     *
     * @param mixed $prio Preference value for the KX record
     * @return int|bool The validated preference value or false if invalid
     *
     * @deprecated Use validatePriority() with ValidationResult pattern instead
     */
    private function validatePriorityLegacy(mixed $prio): int|bool
    {
        $result = $this->validatePriority($prio);
        if (!$result->isValid()) {
            return false;
        }
        return $result->getData();
    }
}
