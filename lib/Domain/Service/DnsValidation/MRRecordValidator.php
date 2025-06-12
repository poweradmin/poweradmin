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
 * MR (Mail Rename) record validator
 *
 * Validates MR records according to:
 * - RFC 1035: Domain Names - Implementation and Specification
 *
 * MR records specify a domain name which is the new name for the mailbox
 * specified in the record name. The MR record is intended as a forwarding
 * entry for a user who has moved to a different mailbox.
 *
 * Format: <new-mailbox>
 *
 * Example: new-mailbox.example.com
 *
 * Where:
 * - new-mailbox: A domain name specifying a mailbox which is the proper
 *   rename of the specified mailbox in the record name.
 *
 * Usage:
 * When a mail server query returns an MR record, the mailer should replace
 * the old mailbox (in the record name) with the new one (in the record content)
 * and retry the mail operation.
 *
 * Important notes:
 * - MR records were marked as EXPERIMENTAL in RFC 1035
 * - Type code: 9
 * - Modern email systems rarely use MR records for mail forwarding
 * - The content should be a fully qualified domain name
 * - No additional section processing is required for MR records
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class MRRecordValidator implements DnsRecordValidatorInterface
{
    private TTLValidator $ttlValidator;
    private HostnameValidator $hostnameValidator;

    public function __construct(ConfigurationManager $config)
    {
        $this->ttlValidator = new TTLValidator();
        $this->hostnameValidator = new HostnameValidator($config);
    }

    /**
     * Validate an MR record
     *
     * @param string $content The content part of the record (new mailbox name)
     * @param string $name The name part of the record (old mailbox name)
     * @param mixed $prio The priority value (not used for MR records)
     * @param int|string $ttl The TTL value
     * @param int $defaultTTL The default TTL to use if not specified
     *
     * @return ValidationResult Validation result with data or errors
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL, ...$args): ValidationResult
    {
        $warnings = [];

        // Validate name - the current mailbox
        if (empty(trim($name))) {
            return ValidationResult::failure(_('MR record name (old mailbox) cannot be empty.'));
        }

        // Validate current mailbox name (should be a valid hostname)
        $nameResult = $this->hostnameValidator->validate($name, false);
        if (!$nameResult->isValid()) {
            return ValidationResult::failure(_('MR record name must be a valid domain name.'));
        }

        // Get the validated hostname
        $nameData = $nameResult->getData();
        $name = is_array($nameData) && isset($nameData['hostname']) ? $nameData['hostname'] : $name;

        // Validate content - ensure it's not empty (new mailbox)
        if (empty(trim($content))) {
            return ValidationResult::failure(_('MR record content (new mailbox) cannot be empty.'));
        }

        // Validate that content is a valid hostname
        $hostnameResult = $this->hostnameValidator->validate($content, false);
        if (!$hostnameResult->isValid()) {
            return ValidationResult::failure(_('MR record content must be a valid domain name.'));
        }

        // Get the validated hostname
        $contentData = $hostnameResult->getData();
        $content = is_array($contentData) && isset($contentData['hostname']) ? $contentData['hostname'] : $content;

        // Check if content is a fully qualified domain name (should end with a dot)
        if (!str_ends_with($content, '.')) {
            $warnings[] = _('MR record content (new mailbox) should be a fully qualified domain name (end with a dot).');
            $content = $content . '.';
        }

        // Validate that content and name are different (after normalization)
        $normalizedContent = rtrim($content, '.');
        $normalizedName = rtrim($name, '.');
        if ($normalizedContent === $normalizedName) {
            $warnings[] = _('The new mailbox is the same as the old mailbox. MR records are intended for redirecting to a different mailbox.');
        }

        // Validate TTL
        $ttlResult = $this->ttlValidator->validate($ttl, $defaultTTL);
        if (!$ttlResult->isValid()) {
            return $ttlResult;
        }
        $ttlData = $ttlResult->getData();
        $validatedTtl = is_array($ttlData) && isset($ttlData['ttl']) ? $ttlData['ttl'] : $ttlData;

        // Validate priority (should be 0 for MR records)
        if (!empty($prio) && $prio != 0) {
            return ValidationResult::failure(_('Priority field for MR records must be 0 or empty.'));
        }

        // Add warnings about MR record usage
        $warnings[] = _('MR records were marked as EXPERIMENTAL in RFC 1035 and are rarely used in modern mail systems.');
        $warnings[] = _('Consider using standard mail forwarding mechanisms instead of MR records.');
        $warnings[] = _('The MR record indicates that the mail user has moved from the old mailbox to the new mailbox.');

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => 0,
            'ttl' => $validatedTtl
        ], $warnings);
    }
}
