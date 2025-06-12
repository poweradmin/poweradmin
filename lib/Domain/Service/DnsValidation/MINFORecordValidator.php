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
 * MINFO Record Validator
 *
 * The MINFO (Mailbox Information) record type is used to specify mailboxes that are
 * responsible for a mailing list or mailbox, and to receive error messages related to them.
 *
 * Format: <responsible-mailbox> <error-mailbox>
 *
 * Where:
 * - responsible-mailbox: A domain name that specifies a mailbox that is responsible for
 *   the mailing list or mailbox. If this is the root, the owner of the record is
 *   responsible for itself. Many existing mailing lists use a mailbox "list-name-request"
 *   for this field.
 * - error-mailbox: A domain name that specifies a mailbox that is to receive error messages
 *   related to the mailing list or mailbox. If this is the root, errors should be
 *   returned to the sender of the message.
 *
 * Example: "admin.example.com errors.example.com"
 *
 * NOTE: The MINFO record type is explicitly marked as EXPERIMENTAL in RFC 1035.
 * Although still supported in many DNS implementations, it is rarely used and
 * might not be supported universally.
 *
 * @see https://www.rfc-editor.org/rfc/rfc1035 RFC 1035, Section 3.3.7
 */
class MINFORecordValidator implements DnsRecordValidatorInterface
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
     * Validate MINFO record
     * MINFO records contain responsible mailbox and error mailbox information
     *
     * @param string $content Content in format "rmailbx emailbx"
     * @param string $name Domain name for the MINFO record
     * @param mixed $prio Priority value (not used for MINFO)
     * @param int|string|null $ttl TTL value
     * @param int $defaultTTL Default TTL to use if not specified
     *
     * @return ValidationResult ValidationResult containing validated data or error messages
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL, ...$args): ValidationResult
    {
        $errors = [];
        $warnings = [];

        // Add warning about experimental status according to RFC 1035
        $warnings[] = _('NOTE: The MINFO record type is marked as EXPERIMENTAL in RFC 1035. It may not be universally supported.');

        // Validate name (domain name)
        $nameResult = $this->hostnameValidator->validate($name, true);
        if (!$nameResult->isValid()) {
            return $nameResult;
        }
        $nameData = $nameResult->getData();
        $name = $nameData['hostname'];

        // Validate content (responsible mailbox and error mailbox)
        if (empty($content)) {
            $errors[] = _('MINFO record content cannot be empty.');
            return ValidationResult::errors($errors);
        }

        $parts = explode(' ', $content, 2);
        if (count($parts) !== 2) {
            $errors[] = _('MINFO record must contain both responsible mailbox and error mailbox separated by a space (RFC 1035 Section 3.3.7).');
            return ValidationResult::errors($errors);
        }

        $rmailbx = $parts[0];
        $emailbx = $parts[1];

        // Validate responsible mailbox (RMAILBX)
        $rmailbxResult = $this->hostnameValidator->validate($rmailbx, false);
        if (!$rmailbxResult->isValid()) {
            return ValidationResult::errors(
                array_merge([_('Invalid responsible mailbox hostname (RMAILBX).')], $rmailbxResult->getErrors())
            );
        }
        $rmailbxData = $rmailbxResult->getData();
        $rmailbx = $rmailbxData['hostname'];

        // Check for the "request" naming convention mentioned in RFC 1035
        if (!str_contains($rmailbx, '-request') && !str_contains($rmailbx, 'request')) {
            $warnings[] = _('RFC 1035 notes that many mailing lists use a mailbox "list-name-request" for the responsible mailbox field.');
        }

        // Special case for root domain in RMAILBX
        if ($rmailbx === '.') {
            $warnings[] = _('Using "." (root) for RMAILBX indicates that the owner of the MINFO record is responsible for itself.');
        }

        // Validate error mailbox (EMAILBX)
        $emailbxResult = $this->hostnameValidator->validate($emailbx, false);
        if (!$emailbxResult->isValid()) {
            return ValidationResult::errors(
                array_merge([_('Invalid error mailbox hostname (EMAILBX).')], $emailbxResult->getErrors())
            );
        }
        $emailbxData = $emailbxResult->getData();
        $emailbx = $emailbxData['hostname'];

        // Special case for root domain in EMAILBX
        if ($emailbx === '.') {
            $warnings[] = _('Using "." (root) for EMAILBX indicates that errors should be returned to the sender of the message.');
        }

        // Check if both fields are identical
        if ($rmailbx === $emailbx) {
            $warnings[] = _('The responsible mailbox and error mailbox are identical. Consider using different mailboxes for these functions.');
        }

        // Validate TTL
        $ttlResult = $this->ttlValidator->validate($ttl, $defaultTTL);
        if (!$ttlResult->isValid()) {
            return $ttlResult;
        }
        $ttlData = $ttlResult->getData();
        $validatedTtl = is_array($ttlData) && isset($ttlData['ttl']) ? $ttlData['ttl'] : $ttlData;

        // Validate priority (should be 0 for MINFO records)
        if (!empty($prio) && $prio != 0) {
            $errors[] = _('Priority field for MINFO records must be 0 or empty. MINFO does not use priority values per RFC 1035.');
            return ValidationResult::errors($errors);
        }

        // Reconstruct the content with validated parts
        $validatedContent = $rmailbx . ' ' . $emailbx;

        return ValidationResult::success(['content' => $validatedContent,
            'name' => $name,
            'prio' => 0, // MINFO records don't use priority
            'ttl' => $validatedTtl], $warnings);
    }
}
