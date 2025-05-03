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

use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Service\MessageService;

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
    private MessageService $messageService;
    private HostnameValidator $hostnameValidator;
    private TTLValidator $ttlValidator;

    public function __construct(ConfigurationManager $config)
    {
        $this->config = $config;
        $this->messageService = new MessageService();
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
     * @param int|string $ttl The TTL value
     * @param int $defaultTTL The default TTL to use if not specified
     *
     * @return array|bool Array with validated data or false if validation fails
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, $defaultTTL): array|bool
    {
        // Validate the hostname format
        if (!StringValidator::isValidPrintable($name)) {
            return false;
        }

        $hostnameResult = $this->hostnameValidator->isValidHostnameFqdn($name, 1);
        if ($hostnameResult === false) {
            return false;
        }
        $name = $hostnameResult['hostname'];

        // Validate content
        if (!$this->isValidRPContent($content)) {
            return false;
        }

        // Validate TTL
        $validatedTTL = $this->ttlValidator->isValidTTL($ttl, $defaultTTL);
        if ($validatedTTL === false) {
            return false;
        }

        return [
            'content' => $content,
            'name' => $name,
            'prio' => 0, // RP records don't use priority
            'ttl' => $validatedTTL
        ];
    }

    /**
     * Validates the content of an RP record
     * Format: <mailbox-domain> <txt-record-domain>
     *
     * @param string $content The content to validate
     * @return bool True if valid, false otherwise
     */
    private function isValidRPContent(string $content): bool
    {
        // Check if empty
        if (empty(trim($content))) {
            $this->messageService->addSystemError(_('RP record content cannot be empty.'));
            return false;
        }

        // Check for valid printable characters
        if (!StringValidator::isValidPrintable($content)) {
            return false;
        }

        // Split the content into components
        $parts = preg_split('/\s+/', trim($content));
        if (count($parts) !== 2) {
            $this->messageService->addSystemError(_('RP record must contain mailbox-domain and txt-record-domain.'));
            return false;
        }

        [$mailboxDomain, $txtDomain] = $parts;

        // Validate mailbox domain
        if (!$this->isValidMailboxDomain($mailboxDomain)) {
            return false;
        }

        // Validate TXT domain reference
        if (!$this->isValidTxtDomain($txtDomain)) {
            return false;
        }

        return true;
    }

    /**
     * Validates the mailbox domain part of an RP record
     * This should be a valid domain with a mailbox name or a "." for none
     *
     * @param string $mailboxDomain The mailbox domain to validate
     * @return bool True if valid, false otherwise
     */
    private function isValidMailboxDomain(string $mailboxDomain): bool
    {
        // The mailbox domain can be a "." to indicate "none"
        if ($mailboxDomain === '.') {
            return true;
        }

        // Check for valid FQDN by seeing if it ends with a dot
        if (!str_ends_with($mailboxDomain, '.')) {
            $this->messageService->addSystemError(_('RP mailbox domain must be a fully qualified domain name (end with a dot).'));
            return false;
        }

        // Check for valid hostname format
        $mailboxDomain = rtrim($mailboxDomain, '.');
        $mailboxParts = explode('.', $mailboxDomain);
        foreach ($mailboxParts as $part) {
            if (empty($part) || !preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$/', $part)) {
                $this->messageService->addSystemError(_('RP mailbox domain contains invalid characters.'));
                return false;
            }
        }

        return true;
    }

    /**
     * Validates the TXT domain reference part of an RP record
     * This should be a valid domain reference or a "." for none
     *
     * @param string $txtDomain The TXT domain reference to validate
     * @return bool True if valid, false otherwise
     */
    private function isValidTxtDomain(string $txtDomain): bool
    {
        // The TXT domain can be a "." to indicate "none"
        if ($txtDomain === '.') {
            return true;
        }

        // Check for valid FQDN by seeing if it ends with a dot
        if (!str_ends_with($txtDomain, '.')) {
            $this->messageService->addSystemError(_('RP TXT domain must be a fully qualified domain name (end with a dot).'));
            return false;
        }

        // Check for valid hostname format
        $txtDomain = rtrim($txtDomain, '.');
        $txtParts = explode('.', $txtDomain);
        foreach ($txtParts as $part) {
            if (empty($part) || !preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$/', $part)) {
                $this->messageService->addSystemError(_('RP TXT domain contains invalid characters.'));
                return false;
            }
        }

        return true;
    }
}
