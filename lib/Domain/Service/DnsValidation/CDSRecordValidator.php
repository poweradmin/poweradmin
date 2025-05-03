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
 * CDS record validator
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class CDSRecordValidator implements DnsRecordValidatorInterface
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
     * Validates CDS record content
     *
     * @param string $content The content of the CDS record (key-tag algorithm digest-type digest)
     * @param string $name The name of the record
     * @param mixed $prio The priority (unused for CDS records)
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
        if (!$this->isValidCDSContent($content)) {
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
            'prio' => 0, // CDS records don't use priority
            'ttl' => $validatedTTL
        ];
    }

    /**
     * Validates the content of a CDS record
     * Format: <key-tag> <algorithm> <digest-type> <digest>
     *
     * @param string $content The content to validate
     * @return bool True if valid, false otherwise
     */
    private function isValidCDSContent(string $content): bool
    {
        // Special case for CDS deletion record
        if (trim($content) === '0 0 0 00') {
            return true;
        }

        // Split the content into components
        $parts = preg_split('/\s+/', trim($content), 4);
        if (count($parts) !== 4) {
            $this->messageService->addSystemError(_('CDS record must contain key-tag, algorithm, digest-type and digest separated by spaces.'));
            return false;
        }

        [$keyTag, $algorithm, $digestType, $digest] = $parts;

        // Validate key tag (must be a number between 0 and 65535)
        if (!is_numeric($keyTag) || (int)$keyTag < 0 || (int)$keyTag > 65535) {
            $this->messageService->addSystemError(_('CDS key tag must be a number between 0 and 65535.'));
            return false;
        }

        // Validate algorithm (must be a number between 1 and 16)
        $validAlgorithms = range(1, 16);
        if (!is_numeric($algorithm) || !in_array((int)$algorithm, $validAlgorithms)) {
            $this->messageService->addSystemError(_('CDS algorithm must be a number between 1 and 16.'));
            return false;
        }

        // Validate digest type (must be 1, 2, or 4)
        $validDigestTypes = [1, 2, 4];
        if (!is_numeric($digestType) || !in_array((int)$digestType, $validDigestTypes)) {
            $this->messageService->addSystemError(_('CDS digest type must be 1 (SHA-1), 2 (SHA-256), or 4 (SHA-384).'));
            return false;
        }

        // Validate digest (hex string)
        $expectedLength = 0;

        // Set expected length based on digest type
        if ((int)$digestType === 1) {
            // SHA-1: 40 hex chars
            $expectedLength = 40;
        } elseif ((int)$digestType === 2) {
            // SHA-256: 64 hex chars
            $expectedLength = 64;
        } elseif ((int)$digestType === 4) {
            // SHA-384: 96 hex chars
            $expectedLength = 96;
        }

        // Check if digest is a valid hex string of the expected length
        if (!ctype_xdigit($digest) || strlen($digest) !== $expectedLength) {
            $this->messageService->addSystemError(sprintf(_('CDS digest must be a valid hex string of length %d for the selected digest type.'), $expectedLength));
            return false;
        }

        return true;
    }
}
