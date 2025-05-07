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
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL): ValidationResult
    {
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

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => 0, // RP records don't use priority
            'ttl' => $validatedTtl
        ]);
    }

    /**
     * Validates the content of an RP record
     * Format: <mailbox-domain> <txt-record-domain>
     *
     * @param string $content The content to validate
     * @return ValidationResult ValidationResult with success or errors
     */
    private function validateRPContent(string $content): ValidationResult
    {
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
            return ValidationResult::failure(_('RP record must contain mailbox-domain and txt-record-domain.'));
        }

        [$mailboxDomain, $txtDomain] = $parts;

        // Validate mailbox domain
        $mailboxResult = $this->validateMailboxDomain($mailboxDomain);
        if (!$mailboxResult->isValid()) {
            return $mailboxResult;
        }

        // Validate TXT domain reference
        $txtResult = $this->validateTxtDomain($txtDomain);
        if (!$txtResult->isValid()) {
            return $txtResult;
        }

        return ValidationResult::success(true);
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
        // The mailbox domain can be a "." to indicate "none"
        if ($mailboxDomain === '.') {
            return ValidationResult::success(true);
        }

        // Check for valid FQDN by seeing if it ends with a dot
        if (!str_ends_with($mailboxDomain, '.')) {
            return ValidationResult::failure(_('RP mailbox domain must be a fully qualified domain name (end with a dot).'));
        }

        // Check for valid hostname format
        $mailboxDomain = rtrim($mailboxDomain, '.');
        $mailboxParts = explode('.', $mailboxDomain);
        foreach ($mailboxParts as $part) {
            if (empty($part) || !preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$/', $part)) {
                return ValidationResult::failure(_('RP mailbox domain contains invalid characters.'));
            }
        }

        return ValidationResult::success(true);
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
        // The TXT domain can be a "." to indicate "none"
        if ($txtDomain === '.') {
            return ValidationResult::success(true);
        }

        // Check for valid FQDN by seeing if it ends with a dot
        if (!str_ends_with($txtDomain, '.')) {
            return ValidationResult::failure(_('RP TXT domain must be a fully qualified domain name (end with a dot).'));
        }

        // Check for valid hostname format
        $txtDomain = rtrim($txtDomain, '.');
        $txtParts = explode('.', $txtDomain);
        foreach ($txtParts as $part) {
            if (empty($part) || !preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$/', $part)) {
                return ValidationResult::failure(_('RP TXT domain contains invalid characters.'));
            }
        }

        return ValidationResult::success(true);
    }
}
