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
 * CAA record validator
 *
 * Validates CAA (Certification Authority Authorization) records according to:
 * - RFC 8659: DNS Certification Authority Authorization (CAA) Resource Record
 * - RFC 6844: DNS Certification Authority Authorization (CAA) Resource Record (obsoleted by RFC 8659)
 *
 * CAA records specify which Certificate Authorities (CAs) are allowed to issue
 * certificates for a domain. The format is:
 * <flags> <tag> <value>
 *
 * Where:
 * - flags: 8-bit integer (0-255) where bit 0 is the critical bit
 * - tag: one of "issue", "issuewild", "iodef", or others registered with IANA
 * - value: tag-specific value, enclosed in quotes
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class CAARecordValidator implements DnsRecordValidatorInterface
{
    private ConfigurationManager $config;
    private HostnameValidator $hostnameValidator;
    private TTLValidator $ttlValidator;

    public function __construct(ConfigurationManager $config)
    {
        $this->config = $config;
        $this->hostnameValidator = new HostnameValidator($config);
        $this->ttlValidator = new TTLValidator();
    }

    /**
     * Validates CAA record content
     *
     * @param string $content The content of the CAA record (flags tag value)
     * @param string $name The name of the record
     * @param mixed $prio The priority (unused for CAA records)
     * @param int|string|null $ttl The TTL value
     * @param int $defaultTTL The default TTL to use if not specified
     *
     * @return ValidationResult ValidationResult containing validated data or error messages
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL): ValidationResult
    {
        $errors = [];

        // Validate hostname/name
        $hostnameResult = $this->hostnameValidator->validate($name, true);
        if (!$hostnameResult->isValid()) {
            return $hostnameResult;
        }
        $hostnameData = $hostnameResult->getData();
        $name = $hostnameData['hostname'];

        // Validate content
        $contentResult = $this->validateCAAContent($content);
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

        // CAA records don't use priority
        if (!empty($prio) && $prio != 0) {
            $errors[] = _('Priority field for CAA records must be 0 or empty.');
            return ValidationResult::errors($errors);
        }

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => 0, // CAA records don't use priority
            'ttl' => $validatedTtl
        ]);
    }

    /**
     * Validates the content of a CAA record according to RFC 8659
     * Format: <flags> <tag> <value>
     *
     * @param string $content The content to validate
     * @return ValidationResult ValidationResult containing validation status or error message
     */
    private function validateCAAContent(string $content): ValidationResult
    {
        // Split the content into flags, tag, and value
        $parts = preg_split('/\s+/', trim($content), 3);
        if (count($parts) !== 3) {
            return ValidationResult::failure(_('CAA record must contain flags, tag, and value separated by spaces.'));
        }

        [$flags, $tag, $value] = $parts;

        // Validate flags (must be 0-255)
        if (!is_numeric($flags) || (int)$flags < 0 || (int)$flags > 255) {
            return ValidationResult::failure(_('CAA flags must be a number between 0 and 255.'));
        }

        // Check if critical flag is set (bit 0 = 1)
        $isCritical = ((int)$flags & 0x1) === 0x1;

        // Parse and validate tag according to RFC 8659
        // Tag must be a non-zero-length sequence of US-ASCII letters and numbers
        if (!preg_match('/^[a-zA-Z0-9]+$/', $tag)) {
            return ValidationResult::failure(_('CAA tag must only contain ASCII letters and numbers.'));
        }

        // According to RFC 8659, tags are case-insensitive but should be stored in lowercase
        $tag = strtolower($tag);

        // Complete list of valid tags as defined in RFC 8659 plus IANA registry additions
        $validTags = [
            'issue',       // CA Authorization for standard certificates
            'issuewild',   // CA Authorization for wildcard certificates
            'iodef',       // Incident reporting URI
            'contactemail', // Contact email address additional parameter
            'contactphone', // Contact phone number additional parameter
            'tbs'          // ACME "tbs" (To Be Signed) extension
        ];

        // If the tag is not in the standard list, validate if it meets RFC 8659 requirements
        // but only warn - RFC 8659 allows for future extensions, unknown tags should be ignored by CAs
        if (!in_array($tag, $validTags)) {
            if ($isCritical) {
                // If the tag is unknown AND the critical bit is set, this is problematic
                // According to RFC 8659 section 5, critical parameters MUST be understood
                return ValidationResult::failure(sprintf(
                    _('Critical flag set for unknown tag "%s". This may cause certificate issuance failures.'),
                    $tag
                ));
            }
        }

        // Validate value according to tag type
        if ($tag === 'issue' || $tag === 'issuewild') {
            // Per RFC 8659, value can be:
            // 1. Empty string (allowing all CAs) - issue ";", issuewild ";"
            // 2. A domain name (allowing specific CA)
            // 3. ";" (allowing all CAs if the value is the empty string)

            if ($value === ';') {
                // Valid - allows all CAs
                return ValidationResult::success(true);
            }

            // Value should be properly quoted per RFC 8659
            $quotedResult = $this->validateQuotedValue($value);
            if (!$quotedResult->isValid()) {
                return $quotedResult;
            }

            // Extract value to check if it's a domain (CA identifier)
            $unquoted = trim($value, '"');

            // Empty string inside quotes is not allowed - should be ";" instead
            if (empty($unquoted)) {
                return ValidationResult::failure(_('Empty value is not allowed. Use ";" to allow all CAs.'));
            }

            // Check for parameters using ASCII Control characters
            if ($tag === 'issue' && strpos($unquoted, ';') !== false) {
                // After validating domain, check for parameters
                $segments = explode(';', $unquoted);
                $domain = $segments[0];

                // Domain part must be a valid hostname
                if (!empty($domain) && !$this->hostnameValidator->isValid($domain)) {
                    return ValidationResult::failure(_('Invalid CA domain in issue tag.'));
                }

                // Validate parameters as specified in RFC 8659 Section 4.2
                // Parameters are semicolon-separated key-value pairs
                for ($i = 1; $i < count($segments); $i++) {
                    if (empty($segments[$i])) {
                        continue;
                    }

                    // Parameter should be in key=value format
                    if (!preg_match('/^([a-zA-Z0-9]+)=(.*)$/', $segments[$i], $matches)) {
                        return ValidationResult::failure(_('Issue tag parameters must be in key=value format.'));
                    }

                    // RFC 8659 section 4.3 defines "accounturi" parameter
                    if (strtolower($matches[1]) === 'accounturi' && empty($matches[2])) {
                        return ValidationResult::failure(_('accounturi parameter cannot be empty.'));
                    }

                    // RFC 8659 section 4.4 defines "validationmethods" parameter
                    if (strtolower($matches[1]) === 'validationmethods') {
                        $methods = explode(',', $matches[2]);
                        foreach ($methods as $method) {
                            $method = trim($method);
                            if (empty($method)) {
                                return ValidationResult::failure(_('Empty validation method specified.'));
                            }
                        }
                    }
                }
            } elseif ($tag === 'issuewild') {
                // For issuewild, RFC 8659 doesn't define parameters, so it should be just a domain
                if (!$this->hostnameValidator->isValid($unquoted) && $unquoted !== '') {
                    return ValidationResult::failure(_('Invalid CA domain in issuewild tag.'));
                }
            }
        } elseif ($tag === 'iodef') {
            // Value for iodef should be a properly quoted URL
            $quotedResult = $this->validateQuotedValue($value);
            if (!$quotedResult->isValid()) {
                return $quotedResult;
            }

            // According to RFC 8659 section 6.3, iodef URLs must start with http://, https://, or mailto:
            $unquoted = trim($value, '"');
            if (!preg_match('/^(https?:|mailto:)/', $unquoted)) {
                return ValidationResult::failure(_('CAA iodef value must be a URL starting with http://, https://, or mailto:.'));
            }
        } elseif ($tag === 'contactemail') { // For contactemail and contactphone, RFC 8659 doesn't define specific format requirements
            $quotedResult = $this->validateQuotedValue($value);
            if (!$quotedResult->isValid()) {
                return $quotedResult;
            }

            // Basic email format validation
            $unquoted = trim($value, '"');
            if (!filter_var($unquoted, FILTER_VALIDATE_EMAIL)) {
                return ValidationResult::failure(_('contactemail value must be a valid email address.'));
            }
        } elseif ($tag === 'contactphone') {
            $quotedResult = $this->validateQuotedValue($value);
            if (!$quotedResult->isValid()) {
                return $quotedResult;
            }

            // Basic phone format validation (very permissive, allowing international formats)
            $unquoted = trim($value, '"');
            if (!preg_match('/^[+0-9()\s.\-]+$/i', $unquoted)) {
                return ValidationResult::failure(_('contactphone value must contain only numbers, spaces, and common phone number punctuation.'));
            }
        } else {
            // For other tag types, at least validate that the value is properly quoted
            $quotedResult = $this->validateQuotedValue($value);
            if (!$quotedResult->isValid()) {
                return $quotedResult;
            }
        }

        return ValidationResult::success(true);
    }

    /**
     * Validate a quoted value according to RFC 8659 section 5.2
     *
     * RFC 8659 requires all non-empty values for CAA properties to be quoted strings.
     * Double quotes within the string must be escaped with backslashes.
     * Values containing a semicolon that is meant as a parameter separator must NOT be
     * quoted - they are parsed differently.
     *
     * @param string $value The value to validate
     * @return ValidationResult ValidationResult containing validation status or error message
     */
    private function validateQuotedValue(string $value): ValidationResult
    {
        // Check if the value is properly quoted
        if (
            !isset($value[0]) || $value[0] !== '"' ||
            !isset($value[strlen($value) - 1]) || $value[strlen($value) - 1] !== '"'
        ) {
            return ValidationResult::failure(_('Value must be enclosed in double quotes.'));
        }

        // Extract the content without quotes
        $subContent = substr($value, 1, -1);

        // Check for unescaped quotes in the content (RFC 8659 requires escaping)
        $pattern = '/(?<!\\\\)"/';
        if (preg_match($pattern, $subContent)) {
            return ValidationResult::failure(_('Backslashes must precede all quotes (") in value content.'));
        }

        // RFC 8659 section 5.2 indicates values are limited to 512 characters
        // This includes everything between (but not including) the enclosing quotes
        if (strlen($subContent) > 512) {
            return ValidationResult::failure(_('CAA property value exceeds maximum length of 512 characters.'));
        }

        // Check for invalid characters in property value
        // RFC 8659 allows any octet to appear in the property value except NUL (0)
        if (strpos($subContent, "\0") !== false) {
            return ValidationResult::failure(_('CAA property value cannot contain NUL (0) characters.'));
        }

        return ValidationResult::success(true);
    }
}
