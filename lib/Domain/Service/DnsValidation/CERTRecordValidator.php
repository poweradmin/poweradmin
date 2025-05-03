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
 * CERT record validator
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class CERTRecordValidator implements DnsRecordValidatorInterface
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
     * Validates CERT record content
     *
     * @param string $content The content of the CERT record (type key-tag algorithm cert-data)
     * @param string $name The name of the record
     * @param mixed $prio The priority (unused for CERT records)
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
        if (!$this->isValidCERTContent($content)) {
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
            'prio' => 0, // CERT records don't use priority
            'ttl' => $validatedTTL
        ];
    }

    /**
     * Validates the content of a CERT record
     * Format: <type> <key-tag> <algorithm> <certificate-data>
     *
     * @param string $content The content to validate
     * @return bool True if valid, false otherwise
     */
    private function isValidCERTContent(string $content): bool
    {
        // Split the content into components
        $parts = preg_split('/\s+/', trim($content), 4);
        if (count($parts) !== 4) {
            $this->messageService->addSystemError(_('CERT record must contain type, key-tag, algorithm and certificate-data separated by spaces.'));
            return false;
        }

        [$type, $keyTag, $algorithm, $certData] = $parts;

        // Validate type (must be a number or a known type mnemonic)
        $validTypes = [
            'PKIX' => 1,   // X.509 as per PKIX
            'SPKI' => 2,   // SPKI certificate
            'PGP' => 3,    // OpenPGP packet
            'IPKIX' => 4,  // URL of an X.509 data object
            'ISPKI' => 5,  // URL of an SPKI certificate
            'IPGP' => 6,   // Fingerprint and URL of OpenPGP packet
            'ACPKIX' => 7, // Attribute Certificate
            'IACPKIX' => 8, // URL of an AC
            'URI' => 253,  // URI private
            'OID' => 254   // OID private
        ];

        if (is_numeric($type)) {
            $typeValue = (int)$type;
            if ($typeValue < 0 || $typeValue > 65535) {
                $this->messageService->addSystemError(_('CERT type must be a number between 0 and 65535 or a valid mnemonic.'));
                return false;
            }
        } elseif (isset($validTypes[strtoupper($type)])) {
            // Type is a valid mnemonic, convert it to a value
            $typeValue = $validTypes[strtoupper($type)];
        } else {
            $this->messageService->addSystemError(_('CERT type must be a number between 0 and 65535 or a valid mnemonic (PKIX, SPKI, PGP, etc.).'));
            return false;
        }

        // Validate key tag (must be a number between 0 and 65535)
        if (!is_numeric($keyTag) || (int)$keyTag < 0 || (int)$keyTag > 65535) {
            $this->messageService->addSystemError(_('CERT key tag must be a number between 0 and 65535.'));
            return false;
        }

        // Validate algorithm (must be a number between 0 and 255 or valid algorithm mnemonic)
        $validAlgorithms = [
            'RSAMD5' => 1,
            'DH' => 2,
            'DSA' => 3,
            'ECC' => 4,
            'RSASHA1' => 5,
            'RSASHA256' => 8,
            'RSASHA512' => 10,
            'ECCGOST' => 12,
            'ECDSAP256SHA256' => 13,
            'ECDSAP384SHA384' => 14,
            'ED25519' => 15,
            'ED448' => 16
        ];

        if (is_numeric($algorithm)) {
            $algorithmValue = (int)$algorithm;
            if ($algorithmValue < 0 || $algorithmValue > 255) {
                $this->messageService->addSystemError(_('CERT algorithm must be a number between 0 and 255 or a valid mnemonic.'));
                return false;
            }
        } elseif (isset($validAlgorithms[strtoupper($algorithm)])) {
            // Algorithm is a valid mnemonic, convert it to a value
            $algorithmValue = $validAlgorithms[strtoupper($algorithm)];
        } else {
            $this->messageService->addSystemError(_('CERT algorithm must be a number between 0 and 255 or a valid mnemonic (RSASHA1, DSA, etc.).'));
            return false;
        }

        // Validate certificate data (must be base64-encoded data)
        if (!$this->isValidBase64($certData)) {
            $this->messageService->addSystemError(_('CERT certificate data must be valid base64-encoded data.'));
            return false;
        }

        return true;
    }

    /**
     * Check if a string is valid base64-encoded data
     *
     * @param string $data The data to check
     * @return bool True if valid base64, false otherwise
     */
    private function isValidBase64(string $data): bool
    {
        // Basic pattern for base64-encoded data (may allow some invalid base64, but is sufficient for basic validation)
        if (!preg_match('/^[A-Za-z0-9+\/=]+$/', $data)) {
            return false;
        }

        // Try to decode the base64 data
        $decoded = base64_decode($data, true);
        if ($decoded === false) {
            return false;
        }

        return true;
    }
}
