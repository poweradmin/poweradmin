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
 * SSHFP (SSH Fingerprint) record validator
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class SSHFPRecordValidator implements DnsRecordValidatorInterface
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
     * Validates SSHFP record content
     *
     * @param string $content The content of the SSHFP record (algorithm fingerprint-type fingerprint)
     * @param string $name The name of the record
     * @param mixed $prio The priority (unused for SSHFP records)
     * @param int|string $ttl The TTL value
     * @param int $defaultTTL The default TTL to use if not specified
     *
     * @return array|bool Array with validated data or false if validation fails
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, $defaultTTL): array|bool
    {
        // Validate hostname/name
        $hostnameResult = $this->hostnameValidator->isValidHostnameFqdn($name, 1);
        if ($hostnameResult === false) {
            return false;
        }
        $name = $hostnameResult['hostname'];

        // Validate content
        if (!$this->isValidSSHFPContent($content)) {
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
            'prio' => 0, // SSHFP records don't use priority
            'ttl' => $validatedTTL
        ];
    }

    /**
     * Validates the content of an SSHFP record
     * Format: <algorithm> <fp-type> <fingerprint>
     *
     * Algorithm values:
     * 1 = RSA
     * 2 = DSA
     * 3 = ECDSA
     * 4 = Ed25519
     *
     * Fingerprint type values:
     * 1 = SHA-1
     * 2 = SHA-256
     *
     * @param string $content The content to validate
     * @return bool True if valid, false otherwise
     */
    private function isValidSSHFPContent(string $content): bool
    {
        // Split the content into components
        $parts = preg_split('/\s+/', trim($content), 3);
        if (count($parts) !== 3) {
            $this->messageService->addSystemError(_('SSHFP record must contain algorithm, fingerprint-type and fingerprint separated by spaces.'));
            return false;
        }

        [$algorithm, $fpType, $fingerprint] = $parts;

        // Validate algorithm (must be 1-4)
        if (!is_numeric($algorithm) || !in_array((int)$algorithm, [1, 2, 3, 4])) {
            $this->messageService->addSystemError(_('SSHFP algorithm must be 1 (RSA), 2 (DSA), 3 (ECDSA), or 4 (Ed25519).'));
            return false;
        }

        // Validate fingerprint type (must be 1 or 2)
        if (!is_numeric($fpType) || !in_array((int)$fpType, [1, 2])) {
            $this->messageService->addSystemError(_('SSHFP fingerprint type must be 1 (SHA-1) or 2 (SHA-256).'));
            return false;
        }

        // Validate fingerprint (must be hexadecimal)
        if (!preg_match('/^[0-9a-fA-F]+$/', $fingerprint)) {
            $this->messageService->addSystemError(_('SSHFP fingerprint must be a hexadecimal string.'));
            return false;
        }

        // Validate fingerprint length based on type
        $fpLength = strlen($fingerprint);
        if ((int)$fpType === 1 && $fpLength !== 40) { // SHA-1 is 40 hex chars
            $this->messageService->addSystemError(_('SSHFP SHA-1 fingerprint must be 40 characters long.'));
            return false;
        } elseif ((int)$fpType === 2 && $fpLength !== 64) { // SHA-256 is 64 hex chars
            $this->messageService->addSystemError(_('SSHFP SHA-256 fingerprint must be 64 characters long.'));
            return false;
        }

        return true;
    }
}
