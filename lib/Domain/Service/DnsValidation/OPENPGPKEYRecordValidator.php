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
 * OPENPGPKEY record validator
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class OPENPGPKEYRecordValidator implements DnsRecordValidatorInterface
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
     * Validates OPENPGPKEY record content
     *
     * @param string $content The content of the OPENPGPKEY record (base64 encoded PGP public key)
     * @param string $name The name of the record
     * @param mixed $prio The priority (unused for OPENPGPKEY records)
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

        // Hostname validation for OPENPGPKEY records
        // OPENPGPKEY records are typically of the form: <hash-of-localpart>._openpgpkey.<domain>
        // But we'll allow regular FQDNs too
        $hostnameResult = $this->hostnameValidator->isValidHostnameFqdn($name, 1);
        if ($hostnameResult === false) {
            return false;
        }
        $name = $hostnameResult['hostname'];

        // Validate content
        if (!$this->isValidOpenPGPKeyContent($content)) {
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
            'prio' => 0, // OPENPGPKEY records don't use priority
            'ttl' => $validatedTTL
        ];
    }

    /**
     * Validates the content of an OPENPGPKEY record
     * Content should be base64 encoded data
     *
     * @param string $content The content to validate
     * @return bool True if valid, false otherwise
     */
    private function isValidOpenPGPKeyContent(string $content): bool
    {
        // Check if empty
        if (empty(trim($content))) {
            $this->messageService->addSystemError(_('OPENPGPKEY record content cannot be empty.'));
            return false;
        }

        // Check for valid printable characters
        if (!StringValidator::isValidPrintable($content)) {
            return false;
        }

        // OPENPGPKEY records store data in base64 format
        // We'll do a basic validation that it consists of valid base64 characters
        if (!preg_match('/^[A-Za-z0-9+\/=]+$/', $content)) {
            $this->messageService->addSystemError(_('OPENPGPKEY records must contain valid base64-encoded data.'));
            return false;
        }

        // Optionally verify that it's valid base64
        $decoded = base64_decode($content, true);
        if ($decoded === false) {
            $this->messageService->addSystemError(_('OPENPGPKEY record contains invalid base64 data.'));
            return false;
        }

        return true;
    }
}
