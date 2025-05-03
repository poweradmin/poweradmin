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
 * SMIMEA (S/MIME Certificate Association) record validator
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class SMIMEARecordValidator implements DnsRecordValidatorInterface
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
     * Validates SMIMEA record content
     *
     * @param string $content The content of the SMIMEA record
     * @param string $name The name of the record
     * @param mixed $prio The priority (unused for SMIMEA records)
     * @param int|string $ttl The TTL value
     * @param int $defaultTTL The default TTL to use if not specified
     *
     * @return array|bool Array with validated data or false if validation fails
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, $defaultTTL): array|bool
    {
        // For SMIMEA records with special format
        // Validate printable characters at minimum
        if (!StringValidator::isValidPrintable($name)) {
            return false;
        }

        // SMIMEA records are typically of the form: <hash-of-localpart>._smimecert.<domain>
        // But we'll allow regular hostnames too
        if (strpos($name, '._smimecert.') !== false) {
            // Accept the SMIMEA format directly
        } else {
            // For non-SMIMEA format names, use regular hostname validation
            $hostnameResult = $this->hostnameValidator->isValidHostnameFqdn($name, 1);
            if ($hostnameResult === false) {
                return false;
            }
            $name = $hostnameResult['hostname'];
        }

        // Validate content
        if (!$this->isValidSMIMEAContent($content)) {
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
            'prio' => 0, // SMIMEA records don't use priority
            'ttl' => $validatedTTL
        ];
    }

    /**
     * Validates the content of a SMIMEA record
     * Format: <usage> <selector> <matching-type> <certificate-data>
     * Very similar to TLSA records
     *
     * @param string $content The content to validate
     * @return bool True if valid, false otherwise
     */
    private function isValidSMIMEAContent(string $content): bool
    {
        // Check if empty
        if (empty(trim($content))) {
            $this->messageService->addSystemError(_('SMIMEA record content cannot be empty.'));
            return false;
        }

        // Check for valid printable characters
        if (!StringValidator::isValidPrintable($content)) {
            return false;
        }

        // Split the content into components
        $parts = preg_split('/\s+/', trim($content), 4);
        if (count($parts) !== 4) {
            $this->messageService->addSystemError(_('SMIMEA record must contain usage, selector, matching-type, and certificate-data separated by spaces.'));
            return false;
        }

        [$usage, $selector, $matchingType, $certificateData] = $parts;

        // Validate usage field (0-3)
        // 0 = PKIX-TA: CA constraint
        // 1 = PKIX-EE: Service certificate constraint
        // 2 = DANE-TA: Trust anchor assertion
        // 3 = DANE-EE: Domain-issued certificate
        if (!is_numeric($usage) || !in_array((int)$usage, range(0, 3))) {
            $this->messageService->addSystemError(_('SMIMEA usage field must be a number between 0 and 3.'));
            return false;
        }

        // Validate selector field (0-1)
        // 0 = Full certificate
        // 1 = SubjectPublicKeyInfo
        if (!is_numeric($selector) || !in_array((int)$selector, range(0, 1))) {
            $this->messageService->addSystemError(_('SMIMEA selector field must be 0 (Full certificate) or 1 (SubjectPublicKeyInfo).'));
            return false;
        }

        // Validate matching type field (0-2)
        // 0 = Exact match
        // 1 = SHA-256 hash
        // 2 = SHA-512 hash
        if (!is_numeric($matchingType) || !in_array((int)$matchingType, range(0, 2))) {
            $this->messageService->addSystemError(_('SMIMEA matching type field must be 0 (Exact match), 1 (SHA-256), or 2 (SHA-512).'));
            return false;
        }

        // Validate certificate data (must be a hexadecimal string)
        if (!preg_match('/^[0-9a-fA-F]+$/', $certificateData)) {
            $this->messageService->addSystemError(_('SMIMEA certificate data must be a hexadecimal string.'));
            return false;
        }

        // Additional validation based on the matching type
        $length = strlen($certificateData);
        if ((int)$matchingType === 1 && $length !== 64) { // SHA-256 is 32 bytes (64 hex chars)
            $this->messageService->addSystemError(_('SMIMEA SHA-256 certificate data must be 64 characters long.'));
            return false;
        } elseif ((int)$matchingType === 2 && $length !== 128) { // SHA-512 is 64 bytes (128 hex chars)
            $this->messageService->addSystemError(_('SMIMEA SHA-512 certificate data must be 128 characters long.'));
            return false;
        }

        return true;
    }
}
