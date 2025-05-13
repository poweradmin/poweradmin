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
 * TXT record validator
 *
 * Validates TXT records according to:
 * - RFC 1035: Domain Names - Implementation and Specification
 * - RFC 7208: Sender Policy Framework (SPF) for Authorizing Use of Domains in Email
 * - RFC 7489: Domain-based Message Authentication, Reporting, and Conformance (DMARC)
 *
 * TXT records are limited to 255 bytes per string as per DNS message format.
 * Multiple strings can be concatenated for longer TXT records.
 *
 * Special handling is provided for specialized TXT record formats:
 * - DMARC records at _dmarc.<domain> with v=DMARC1 content
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class TXTRecordValidator implements DnsRecordValidatorInterface
{
    private ConfigurationManager $config;
    private HostnameValidator $hostnameValidator;
    private TTLValidator $ttlValidator;
    private DMARCRecordValidator $dmarcValidator;

    public function __construct(ConfigurationManager $config)
    {
        $this->config = $config;
        $this->hostnameValidator = new HostnameValidator($config);
        $this->ttlValidator = new TTLValidator();
        $this->dmarcValidator = new DMARCRecordValidator($config);
    }

    /**
     * Validates TXT record content
     *
     * @param string $content The content of the TXT record
     * @param string $name The name of the record
     * @param mixed $prio The priority (unused for TXT records)
     * @param int|string|null $ttl The TTL value
     * @param int $defaultTTL The default TTL to use if not specified
     *
     * @return ValidationResult ValidationResult containing validated data or error messages
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL): ValidationResult
    {
        // Check if this is a DMARC record
        $isDmarc = str_starts_with(strtolower($name), '_dmarc.') &&
                  preg_match('/^"?v=DMARC1\b/i', trim($content));

        if ($isDmarc) {
            // If it's a DMARC record, use the DMARC validator
            $dmarcResult = $this->dmarcValidator->validate($content, $name, $prio, $ttl, $defaultTTL);

            // Process warnings - update them to indicate this is a DMARC record
            if ($dmarcResult->hasWarnings()) {
                $warnings = $dmarcResult->getWarnings();
                $warnings[] = _('This is a DMARC record being processed through TXT record type. DMARC records should use TXT record type with content starting with "v=DMARC1".');
                return ValidationResult::success($dmarcResult->getData(), $warnings);
            }

            return $dmarcResult;
        }

        // Validate hostname/name
        $hostnameResult = $this->hostnameValidator->validate($name, true);
        if (!$hostnameResult->isValid()) {
            return $hostnameResult;
        }
        $hostnameData = $hostnameResult->getData();
        $name = $hostnameData['hostname'];

        // Validate content
        $contentResult = $this->validateContent($content);
        if (!$contentResult->isValid()) {
            return $contentResult;
        }

        // Validate TTL
        $ttlResult = $this->ttlValidator->validate($ttl, $defaultTTL);
        if (!$ttlResult->isValid()) {
            return $ttlResult;
        }
        $ttlData = $ttlResult->getData();
        $validatedTtl = is_array($ttlData) && isset($ttlData['ttl']) ? $ttlData['ttl'] : $ttlData;

        // Validate priority (should be 0 for TXT records)
        $prioResult = $this->validatePriority($prio);
        if (!$prioResult->isValid()) {
            return $prioResult;
        }

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => $prioResult->getData(),
            'ttl' => $validatedTtl
        ]);
    }

    /**
     * Validate TXT record content
     *
     * According to RFC 7208, TXT record strings are limited to 255 bytes each.
     * If the content exceeds this limit, it should be split into multiple strings.
     *
     * @param string $content Content to validate
     * @return ValidationResult ValidationResult containing validation status or error message
     */
    private function validateContent(string $content): ValidationResult
    {
        if (!preg_match('/^[[:print:]]+$/', trim($content))) {
            return ValidationResult::failure(_('Invalid characters in content field.'));
        }

        if (preg_match('/[<>]/', trim($content))) {
            return ValidationResult::failure(_('HTML tags are not allowed in content field.'));
        }

        // Check if we have multiple quoted strings (for long TXT records)
        $multipleTxtStrings = $this->parseMultipleQuotedStrings($content);

        if ($multipleTxtStrings === false) {
            // Not properly formatted multiple strings, treat as a single string

            // Make sure content is properly quoted
            $startsWithQuote = isset($content[0]) && $content[0] === '"';
            $endsWithQuote = isset($content[strlen($content) - 1]) && $content[strlen($content) - 1] === '"';

            if (!($startsWithQuote && $endsWithQuote)) {
                return ValidationResult::failure(_('TXT record content must be enclosed in quotes.'));
            }

            $subContent = substr($content, 1, -1);

            // Check for unescaped quotes
            $pattern = '/(?<!\\\\)"/';
            if (preg_match($pattern, $subContent)) {
                return ValidationResult::failure(_('Backslashes must precede all quotes (") in TXT content'));
            }

            // Check string length - RFC 7208 limits TXT strings to 255 bytes
            if (strlen($subContent) > 255) {
                return ValidationResult::failure(
                    _('TXT record string exceeds 255 bytes. TXT strings must be 255 bytes or less. ' .
                      'For longer content, split into multiple quoted strings separated by spaces.')
                );
            }
        } else {
            // We have properly formatted multiple strings, check each one
            foreach ($multipleTxtStrings as $stringPart) {
                // Remove the quotes for length calculation
                $unquoted = substr($stringPart, 1, -1);

                // Check for unescaped quotes
                $pattern = '/(?<!\\\\)"/';
                if (preg_match($pattern, $unquoted)) {
                    return ValidationResult::failure(_('Backslashes must precede all quotes (") in TXT content'));
                }

                // Check string length - RFC 7208 limits TXT strings to 255 bytes
                if (strlen($unquoted) > 255) {
                    return ValidationResult::failure(
                        _('Each TXT record string must be 255 bytes or less. Split long content into multiple strings.')
                    );
                }
            }
        }

        return ValidationResult::success(true);
    }

    /**
     * Parse TXT content into multiple quoted strings if they exist
     *
     * TXT records can consist of multiple quoted strings which are concatenated
     * by the DNS resolver. This is used to bypass the 255 byte limitation.
     *
     * @param string $content The TXT record content
     * @return array|false Array of quoted strings or false if invalid format
     */
    private function parseMultipleQuotedStrings(string $content): array|false
    {
        $content = trim($content);

        // Check if we have multiple quoted strings
        if (!preg_match('/^"[^"]*"(\s+"[^"]*")+$/', $content)) {
            return false;
        }

        // Split by space but respect quoted content
        preg_match_all('/"([^"\\\\]|\\\\.)*"/', $content, $matches);

        if (empty($matches[0])) {
            return false;
        }

        return $matches[0];
    }


    /**
     * Validate priority for TXT records
     * TXT records don't use priority, so it should be 0
     *
     * @param mixed $prio Priority value
     *
     * @return ValidationResult ValidationResult containing validated priority or error message
     */
    private function validatePriority(mixed $prio): ValidationResult
    {
        // If priority is not provided or empty, set it to 0
        if (!isset($prio) || $prio === "") {
            return ValidationResult::success(0);
        }

        // If provided, ensure it's 0 for TXT records
        if (is_numeric($prio) && intval($prio) === 0) {
            return ValidationResult::success(0);
        }

        return ValidationResult::failure(_('Invalid value for priority field. TXT records must have priority value of 0.'));
    }
}
