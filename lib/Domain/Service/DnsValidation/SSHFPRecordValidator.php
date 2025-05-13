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
 * SSHFP (SSH Fingerprint) record validator
 *
 * Validates SSHFP records according to:
 * - RFC 4255: Using DNS to Securely Publish Secure Shell (SSH) Key Fingerprints
 * - RFC 6594: Use of the SHA-256 Algorithm with RSA, DSA, and ECDSA in SSHFP Resource Records
 * - RFC 7479: Using Ed25519 in SSHFP Resource Records
 * - RFC 8709: Ed25519 and Ed448 Public Key Algorithms for the Secure Shell (SSH) Protocol
 *
 * SSHFP records store SSH host key fingerprints in DNS, enabling SSH clients to verify
 * host keys without manual verification by the user.
 *
 * Format: <algorithm> <fp-type> <fingerprint>
 *
 * Example: 4 2 a87f1b687ac0e57d2a081a2f2826723234d90ed316d2b818ca9580ea384d92401
 *
 * Where:
 * - algorithm: SSH key algorithm type
 *   - 1 = RSA
 *   - 2 = DSA
 *   - 3 = ECDSA
 *   - 4 = Ed25519
 *   - 6 = Ed448
 *
 * - fp-type: Fingerprint hash algorithm
 *   - 1 = SHA-1 (Less secure, 40 hex chars)
 *   - 2 = SHA-256 (Recommended, 64 hex chars)
 *
 * - fingerprint: Hexadecimal representation of the fingerprint
 *
 * Security considerations:
 * - SSHFP records REQUIRE DNSSEC for any security benefit
 * - Without DNSSEC validation, SSHFP offers no security advantage
 * - SHA-256 fingerprints (fp-type 2) are strongly preferred over SHA-1
 * - If both SHA-1 and SHA-256 exist for a host, SSH should prefer SHA-256
 * - If SHA-256 verification fails, SSH should reject the key rather than fall back to SHA-1
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class SSHFPRecordValidator implements DnsRecordValidatorInterface
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
     * Validates SSHFP record content according to RFCs 4255, 6594, 7479, and 8709
     *
     * @param string $content The content of the SSHFP record (algorithm fingerprint-type fingerprint)
     * @param string $name The name of the record
     * @param mixed $prio The priority (unused for SSHFP records)
     * @param int|string|null $ttl The TTL value
     * @param int $defaultTTL The default TTL to use if not specified
     *
     * @return ValidationResult ValidationResult containing validated data or error messages
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL): ValidationResult
    {
        // Validate hostname
        $hostnameResult = $this->hostnameValidator->validate($name, true);
        if (!$hostnameResult->isValid()) {
            return $hostnameResult;
        }
        $hostnameData = $hostnameResult->getData();
        $name = $hostnameData['hostname'];

        // Validate content
        $contentResult = $this->validateSSHFPContent($content);
        if (!$contentResult->isValid()) {
            return $contentResult;
        }

        // Get content data and warnings
        $contentData = $contentResult->getData();
        $warnings = $contentResult->hasWarnings() ? $contentResult->getWarnings() : [];

        // Validate TTL
        $ttlResult = $this->ttlValidator->validate($ttl, $defaultTTL);
        if (!$ttlResult->isValid()) {
            return $ttlResult;
        }

        // Handle both array format and direct value format
        $ttlData = $ttlResult->getData();
        $validatedTtl = is_array($ttlData) && isset($ttlData['ttl']) ? $ttlData['ttl'] : $ttlData;

        // Validate priority (should be 0 for SSHFP records)
        if (!empty($prio) && $prio != 0) {
            return ValidationResult::failure(_('Priority field for SSHFP records must be 0 or empty'));
        }

        // Add recommendation about generating SSHFP records
        $warnings[] = _('SSHFP records can be generated with "ssh-keygen -r hostname" command on systems with OpenSSH.');

        // Add security best practice for multiple fingerprint types
        $warnings[] = _('For maximum security, publish both SHA-1 and SHA-256 fingerprints for compatibility, but prefer SHA-256 in clients.');

        return ValidationResult::success(['content' => $content,
            'name' => $name,
            'prio' => 0, // SSHFP records don't use priority
            'ttl' => $validatedTtl,
            'algorithm' => $contentData['algorithm'] ?? null,
            'algorithm_name' => $contentData['algorithm_name'] ?? null,
            'fingerprint_type' => $contentData['fingerprint_type'] ?? null,
            'fingerprint' => $contentData['fingerprint'] ?? null], $warnings);
    }

    /**
     * Validates the content of an SSHFP record
     * Format: <algorithm> <fp-type> <fingerprint>
     *
     * Algorithm values per RFCs 4255, 6594, 7479, and 8709:
     * 1 = RSA (RFC 4255)
     * 2 = DSA (RFC 4255)
     * 3 = ECDSA (RFC 6594)
     * 4 = Ed25519 (RFC 7479)
     * 6 = Ed448 (RFC 8709)
     *
     * Fingerprint type values per RFCs 4255 and 6594:
     * 1 = SHA-1 (RFC 4255, less secure)
     * 2 = SHA-256 (RFC 6594, recommended)
     *
     * @param string $content The content to validate
     * @return ValidationResult ValidationResult with success or errors/warnings
     */
    private function validateSSHFPContent(string $content): ValidationResult
    {
        // Initialize warnings array
        $warnings = [];

        // Split the content into components
        $parts = preg_split('/\s+/', trim($content), 3);
        if (count($parts) !== 3) {
            return ValidationResult::failure(_('SSHFP record must contain algorithm, fingerprint-type and fingerprint separated by spaces.'));
        }

        [$algorithm, $fpType, $fingerprint] = $parts;

        // Validate algorithm (must be 1-4 or 6)
        $validAlgorithms = [1, 2, 3, 4, 6];
        if (!is_numeric($algorithm) || !in_array((int)$algorithm, $validAlgorithms)) {
            return ValidationResult::failure(_('SSHFP algorithm must be 1 (RSA), 2 (DSA), 3 (ECDSA), 4 (Ed25519), or 6 (Ed448).'));
        }

        // Get algorithm-specific warnings and information
        $algorithmInt = (int)$algorithm;
        $algorithmName = $this->getAlgorithmName($algorithmInt);

        if ($algorithmInt === 2) { // DSA
            $warnings[] = _('DSA keys are considered weak and are deprecated in many SSH implementations. Consider using RSA, Ed25519, or Ed448 instead.');
        } elseif ($algorithmInt === 4) { // Ed25519
            $warnings[] = _('Ed25519 keys are recommended for good security and performance with modern SSH implementations.');
        } elseif ($algorithmInt === 6) { // Ed448
            $warnings[] = _('Ed448 keys offer very high security but may not be supported by all SSH implementations.');
        }

        // Validate fingerprint type (must be 1 or 2)
        if (!is_numeric($fpType) || !in_array((int)$fpType, [1, 2])) {
            return ValidationResult::failure(_('SSHFP fingerprint type must be 1 (SHA-1) or 2 (SHA-256).'));
        }

        // Add fingerprint type warnings
        $fpTypeInt = (int)$fpType;
        if ($fpTypeInt === 1) { // SHA-1
            $warnings[] = _('SHA-1 fingerprints are deprecated. RFC 6594 recommends using SHA-256 fingerprints (type 2) for better security.');
        } else { // SHA-256
            $warnings[] = _('SHA-256 fingerprints are recommended by RFC 6594 and provide better security than SHA-1.');
        }

        // Validate fingerprint (must be hexadecimal)
        if (!preg_match('/^[0-9a-fA-F]+$/', $fingerprint)) {
            return ValidationResult::failure(_('SSHFP fingerprint must be a hexadecimal string.'));
        }

        // Validate fingerprint length based on type
        $fpLength = strlen($fingerprint);
        if ($fpTypeInt === 1 && $fpLength !== 40) { // SHA-1 is 40 hex chars
            return ValidationResult::failure(_('SSHFP SHA-1 fingerprint must be 40 characters long.'));
        } elseif ($fpTypeInt === 2 && $fpLength !== 64) { // SHA-256 is 64 hex chars
            return ValidationResult::failure(_('SSHFP SHA-256 fingerprint must be 64 characters long.'));
        }

        // Add DNSSEC warning (critical for security)
        $warnings[] = _('CRITICAL: SSHFP records REQUIRE DNSSEC validation to provide any security benefits. Without DNSSEC, they offer no protection against man-in-the-middle attacks.');

        // Add usage guidance
        $warnings[] = _('Configure SSH clients with "VerifyHostKeyDNS yes" to utilize SSHFP records for server verification.');

        return ValidationResult::success(['algorithm' => $algorithmInt,
            'algorithm_name' => $algorithmName,
            'fingerprint_type' => $fpTypeInt,
            'fingerprint_type_name' => $fpTypeInt === 1 ? 'SHA-1' : 'SHA-256',
            'fingerprint' => $fingerprint], $warnings);
    }

    /**
     * Gets the descriptive name for an SSHFP algorithm type
     *
     * @param int $algorithm Algorithm type value (1-4, 6)
     * @return string Human-readable name of the algorithm
     */
    private function getAlgorithmName(int $algorithm): string
    {
        $algorithmNames = [
            1 => 'RSA',
            2 => 'DSA',
            3 => 'ECDSA',
            4 => 'Ed25519',
            6 => 'Ed448'
        ];

        return $algorithmNames[$algorithm] ?? 'Unknown Algorithm';
    }
}
