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
 * RP (Responsible Person) record validator
 *
 * Validates RP records according to:
 * - RFC 1183 Section 2.2: Responsible Person RR
 *
 * RP records specify the mailbox of the person responsible for a domain or host and
 * an optional TXT record containing additional contact information.
 *
 * Format: <mbox-dname> <txt-dname>
 *
 * Example: admin.example.com. info.example.com.
 *
 * Where:
 * - mbox-dname: Domain name with the @ replaced by a dot (.) that identifies
 *   the mailbox of the responsible person. This is the same convention as used for
 *   the SOA RNAME field. For example, "admin.example.com." corresponds to "admin@example.com".
 * - txt-dname: Domain name for a TXT record containing additional information.
 *   A single dot (.) indicates that no such TXT record exists.
 *
 * Both fields must be fully qualified domain names ending with a dot (.).
 *
 * Important notes:
 * - The RP record was defined in RFC 1183 (1990) and is less commonly used today
 * - Contact information exposed in DNS may create privacy and security concerns
 * - Multiple RP records can exist for the same domain name
 * - Type code: 17
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class RPRecordValidator implements DnsRecordValidatorInterface
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
     * Validates RP record content
     * The RP record contains the mailbox of the responsible person and a text record reference
     *
     * @param string $content The content of the RP record
     * @param string $name The name of the record
     * @param mixed $prio The priority (unused for RP records)
     * @param int|string|null $ttl The TTL value
     * @param int $defaultTTL The default TTL to use if not specified
     *
     * @return ValidationResult ValidationResult containing validated data or error messages
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL, ...$args): ValidationResult
    {
        $warnings = [];

        // Validate hostname
        if (!StringValidator::isValidPrintable($name)) {
            return ValidationResult::failure(_('Hostname contains invalid characters.'));
        }

        $hostnameResult = $this->hostnameValidator->validate($name, true);
        if (!$hostnameResult->isValid()) {
            return $hostnameResult;
        }
        $hostnameData = $hostnameResult->getData();
        $name = $hostnameData['hostname'];

        // Validate content
        $contentResult = $this->validateRPContent($content);
        if (!$contentResult->isValid()) {
            return $contentResult;
        }

        // Collect warnings from content validation
        if ($contentResult->hasWarnings()) {
            $warnings = array_merge($warnings, $contentResult->getWarnings());
        }

        // Validate TTL
        $ttlResult = $this->ttlValidator->validate($ttl, $defaultTTL);
        if (!$ttlResult->isValid()) {
            return $ttlResult;
        }

        // Handle both array format and direct value format
        $ttlData = $ttlResult->getData();
        $validatedTtl = is_array($ttlData) && isset($ttlData['ttl']) ? $ttlData['ttl'] : $ttlData;

        // Validate priority (should be 0 for RP records)
        if (!empty($prio) && $prio != 0) {
            return ValidationResult::failure(_('Priority field for RP records must be 0 or empty'));
        }

        // Add general RFC recommendations
        $warnings[] = _('RP records were defined in RFC 1183 (1990) and are not widely used in modern DNS configurations.');
        $warnings[] = _('Consider including other contact methods besides RP records for critical domains.');
        $warnings[] = _('Type code for RP records is 17.');

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => 0,
            'ttl' => $validatedTtl
        ], $warnings);
    }

    /**
     * Validates the content of an RP record
     * Format: <mbox-dname> <txt-dname>
     *
     * @param string $content The content to validate
     * @return ValidationResult ValidationResult with success or errors
     */
    private function validateRPContent(string $content): ValidationResult
    {
        $warnings = [];

        // Check if empty
        if (empty(trim($content))) {
            return ValidationResult::failure(_('RP record content cannot be empty.'));
        }

        // Check for valid printable characters
        if (!StringValidator::isValidPrintable($content)) {
            return ValidationResult::failure(_('RP record contains invalid characters.'));
        }

        // Split the content into components
        $parts = preg_split('/\s+/', trim($content));
        if (count($parts) !== 2) {
            return ValidationResult::failure(_('RP record must contain mailbox-domain (mbox-dname) and txt-record-domain (txt-dname).'));
        }

        [$mailboxDomain, $txtDomain] = $parts;

        // Validate mailbox domain
        $mailboxResult = $this->validateMailboxDomain($mailboxDomain);
        if (!$mailboxResult->isValid()) {
            return $mailboxResult;
        }

        // Get mailbox warnings if any
        if ($mailboxResult->hasWarnings()) {
            $warnings = array_merge($warnings, $mailboxResult->getWarnings());
        }

        // Validate TXT domain reference
        $txtResult = $this->validateTxtDomain($txtDomain);
        if (!$txtResult->isValid()) {
            return $txtResult;
        }

        // Get TXT domain warnings if any
        if ($txtResult->hasWarnings()) {
            $warnings = array_merge($warnings, $txtResult->getWarnings());
        }

        // Add general warnings about RP records
        $warnings[] = _('RP records expose contact information in DNS which can create privacy and security concerns.');
        $warnings[] = _('The RP record is less commonly used today. Consider using WHOIS records or other contact mechanisms instead.');
        $warnings[] = _('Ensure corresponding TXT records exist for the txt-dname field if not using a dot (.).');

        return ValidationResult::success(true, $warnings);
    }

    /**
     * Validates the mailbox domain part of an RP record
     * This should be a valid domain with a mailbox name or a "." for none
     *
     * @param string $mailboxDomain The mailbox domain to validate
     * @return ValidationResult ValidationResult with success or errors
     */
    private function validateMailboxDomain(string $mailboxDomain): ValidationResult
    {
        $warnings = [];

        // The mailbox domain can be a "." to indicate "none"
        if ($mailboxDomain === '.') {
            $warnings[] = _('Using "." for the mailbox domain indicates no responsible person is specified. This is not recommended for production domains.');
            return ValidationResult::success(true, $warnings);
        }

        // Check for valid FQDN by seeing if it ends with a dot
        if (!str_ends_with($mailboxDomain, '.')) {
            return ValidationResult::failure(_('RP mailbox domain must be a fully qualified domain name (end with a dot).'));
        }

        // Check for valid hostname format
        $mailboxDomain = rtrim($mailboxDomain, '.');
        $mailboxParts = explode('.', $mailboxDomain);

        // First part should represent the local part of the email
        if (count($mailboxParts) >= 2) {
            $localPart = $mailboxParts[0];
            $domainPart = implode('.', array_slice($mailboxParts, 1));

            // Verify localPart conforms to email username restrictions
            if (!preg_match('/^[a-zA-Z0-9!#$%&\'*+\-\/=?^_`{|}~.]+$/', $localPart)) {
                return ValidationResult::failure(_('RP mailbox local part contains invalid characters. Only characters valid in email addresses are allowed.'));
            }

            // Create email representation for warning message
            $emailFormat = $localPart . '@' . $domainPart;
            $warnings[] = sprintf(_('The mailbox domain represents the email address: %s'), $emailFormat);

            // Check if the domain part includes underscores which are not valid in domain names
            if (strpos($domainPart, '_') !== false) {
                return ValidationResult::failure(_('RP mailbox domain part contains underscores, which are not allowed in domain names.'));
            }
        } else {
            return ValidationResult::failure(_('RP mailbox domain must include both local part and domain sections.'));
        }

        // More detailed hostname format validation for each domain part
        foreach ($mailboxParts as $i => $part) {
            // Skip the first part (local part) which has different validation rules
            if ($i === 0) {
                continue;
            }

            if (empty($part)) {
                return ValidationResult::failure(_('RP mailbox domain contains empty label.'));
            }

            // Domain labels must be 1-63 characters
            if (strlen($part) > 63) {
                return ValidationResult::failure(_('RP mailbox domain label exceeds maximum length of 63 characters.'));
            }

            // Domain labels must start and end with alphanumeric and contain only alphanumeric and hyphen
            if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$/', $part)) {
                return ValidationResult::failure(_('RP mailbox domain contains invalid characters or format.'));
            }
        }

        // Max domain name length is 253 characters
        if (strlen($mailboxDomain) > 253) {
            return ValidationResult::failure(_('RP mailbox domain exceeds maximum length of 253 characters.'));
        }

        // Check if domain part seems valid
        if (!StringValidator::isValidDomain($domainPart)) {
            return ValidationResult::failure(_('RP mailbox domain part is not a valid domain name.'));
        }

        // Add warnings
        $warnings[] = _('The mailbox domain should use the same format as the RNAME field in SOA records, with @ replaced by a dot.');

        return ValidationResult::success(true, $warnings);
    }

    /**
     * Validates the TXT domain reference part of an RP record
     * This should be a valid domain reference or a "." for none
     *
     * @param string $txtDomain The TXT domain reference to validate
     * @return ValidationResult ValidationResult with success or errors
     */
    private function validateTxtDomain(string $txtDomain): ValidationResult
    {
        $warnings = [];

        // The TXT domain can be a "." to indicate "none"
        if ($txtDomain === '.') {
            $warnings[] = _('Using "." for the TXT domain indicates no additional information TXT record is available.');
            return ValidationResult::success(true, $warnings);
        }

        // Check for valid FQDN by seeing if it ends with a dot
        if (!str_ends_with($txtDomain, '.')) {
            return ValidationResult::failure(_('RP TXT domain must be a fully qualified domain name (end with a dot).'));
        }

        // Check for valid hostname format
        $txtDomain = rtrim($txtDomain, '.');

        // Check if domain seems valid using the new StringValidator method
        if (!StringValidator::isValidDomain($txtDomain)) {
            return ValidationResult::failure(_('RP TXT domain is not a valid domain name.'));
        }

        // More detailed validation
        $txtParts = explode('.', $txtDomain);
        foreach ($txtParts as $part) {
            if (empty($part)) {
                return ValidationResult::failure(_('RP TXT domain contains empty label.'));
            }

            // Domain labels must be 1-63 characters
            if (strlen($part) > 63) {
                return ValidationResult::failure(_('RP TXT domain label exceeds maximum length of 63 characters.'));
            }

            // Domain labels must start and end with alphanumeric and contain only alphanumeric and hyphen
            if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$/', $part)) {
                return ValidationResult::failure(_('RP TXT domain contains invalid characters or format.'));
            }
        }

        // Max domain name length is a function of DNS name which is 253 characters
        if (strlen($txtDomain) > 253) {
            return ValidationResult::failure(_('RP TXT domain exceeds maximum length of 253 characters.'));
        }

        // Add warnings about TXT record
        $warnings[] = _('Ensure a TXT record exists at this domain name containing contact information for the responsible person.');
        $warnings[] = sprintf(_('You should create a TXT record at domain: %s'), $txtDomain . '.');

        return ValidationResult::success(true, $warnings);
    }
}
