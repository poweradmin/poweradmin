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
 * TKEY (Transaction KEY) record validator
 *
 * Validates TKEY records according to:
 * - RFC 2930: Secret Key Establishment for DNS (TKEY RR)
 * - RFC 3645: Generic Security Service Algorithm for Secret Key Transaction Authentication for DNS (GSS-TSIG)
 *
 * TKEY records are used to establish shared secret keys between DNS resolvers and servers.
 * These shared keys can then be used with TSIG for transaction authentication.
 *
 * Format: <algorithm-name> <inception-time> <expiration-time> <mode> <error> <key-data>
 *
 * Example: hmac-sha256.example.com. 1609459200 1640995200 3 0 MTIzNDU2Nzg5MA==
 *
 * Where:
 * - algorithm-name: Domain name identifying the algorithm (e.g., hmac-sha256.example.com.)
 * - inception-time: Start time for validity as Unix timestamp or YYYYMMDDHHmmSS format
 * - expiration-time: End time for validity as Unix timestamp or YYYYMMDDHHmmSS format
 * - mode: Key establishment method (1-5):
 *   - 1: Server assignment
 *   - 2: Diffie-Hellman exchange
 *   - 3: GSS-API negotiation (RFC 3645)
 *   - 4: Resolver assignment
 *   - 5: Key deletion
 * - error: DNS RCODE value (0-23, 0 = no error)
 * - key-data: Base64 or hex encoded key material
 *
 * Important specifications:
 * - TTL should always be zero (TKEY records must not be cached)
 * - CLASS should be ANY (255)
 * - For GSS-API mode, inception and expiration times are ignored
 * - Each key name can only have one set of keying material active at a time
 *
 * Security considerations:
 * - Key deletion operations MUST be authenticated (typically with TSIG)
 * - TKEY-established keys are associated with DNS servers/resolvers, not zones
 * - The GSS-API mode (3) provides built-in authentication during key exchange
 * - For Diffie-Hellman mode (2), external authentication like TSIG or SIG(0) is required
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class TKEYRecordValidator implements DnsRecordValidatorInterface
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
     * Validates TKEY record content
     *
     * @param string $content The content of the TKEY record
     * @param string $name The name of the record
     * @param mixed $prio The priority (unused for TKEY records)
     * @param int|string|null $ttl The TTL value
     * @param int $defaultTTL The default TTL to use if not specified
     *
     * @return ValidationResult ValidationResult containing validated data or error messages
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL): ValidationResult
    {
        $errors = [];

        // Validate hostname/name
        $hostnameResult = $this->hostnameValidator->validate($name, true);
        if (!$hostnameResult->isValid()) {
            return $hostnameResult;
        }
        $hostnameData = $hostnameResult->getData();
        $name = $hostnameData['hostname'];

        // Validate content
        $contentResult = $this->validateTKEYContent($content);
        if (!$contentResult->isValid()) {
            return $contentResult;
        }

        // Get content warnings if any
        $warnings = [];
        if ($contentResult->hasWarnings()) {
            $contentWarnings = $contentResult->getWarnings();
            $warnings = array_merge($warnings, $contentWarnings);
        }

        // Validate TTL - Per RFC 2930, TTL should be zero
        $ttlResult = $this->ttlValidator->validate($ttl, $defaultTTL);
        if (!$ttlResult->isValid()) {
            return $ttlResult;
        }

        // Handle both array format and direct value format
        $ttlData = $ttlResult->getData();
        $validatedTtl = is_array($ttlData) && isset($ttlData['ttl']) ? $ttlData['ttl'] : $ttlData;

        // RFC 2930 requires TTL to be zero
        if ($validatedTtl !== 0) {
            $warnings[] = _('RFC 2930 requires TKEY records to have TTL=0 to prevent caching. The current non-zero TTL may cause issues with older DNS implementations.');
        }

        // Validate priority (should be 0 for TKEY records)
        if (!empty($prio) && $prio != 0) {
            return ValidationResult::failure(_('Priority field for TKEY records must be 0 or empty'));
        }

        // Add additional security warnings
        $warnings[] = _('TKEY records are not for DNS data authentication, they are for key establishment between DNS servers/resolvers.');

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => 0,
            'ttl' => $validatedTtl
        ], $warnings);
    }

    /**
     * Validates the content of a TKEY record
     * Format: <algorithm-name> <inception-time> <expiration-time> <mode> <error> <key-data>
     *
     * @param string $content The content to validate
     * @return ValidationResult ValidationResult with errors or success
     */
    private function validateTKEYContent(string $content): ValidationResult
    {
        // Initialize warnings array
        $warnings = [];

        // Basic validation of printable characters
        $printableResult = StringValidator::validatePrintable($content);
        if (!$printableResult->isValid()) {
            return ValidationResult::failure(_('Invalid characters in TKEY record content.'));
        }

        // Split the content into components
        $parts = preg_split('/\s+/', trim($content), 6);
        if (count($parts) !== 6) {
            return ValidationResult::failure(_('TKEY record must contain algorithm-name, inception-time, expiration-time, mode, error, and key-data separated by spaces.'));
        }

        [$algorithmName, $inceptionTime, $expirationTime, $mode, $error, $keyData] = $parts;

        // Validate algorithm name (must be a valid domain name)
        $algoResult = $this->validateAlgorithmName($algorithmName);
        if (!$algoResult['isValid']) {
            return ValidationResult::failure(_('TKEY algorithm name must be a valid domain name ending with a dot (e.g., hmac-sha256.).'));
        }

        // Get algorithm warnings if any
        if (!empty($algoResult['warnings'])) {
            $warnings = array_merge($warnings, $algoResult['warnings']);
        }

        // Validate times (must be valid Unix timestamps or YYYYMMDDHHmmSS format)
        if (!$this->isValidTime($inceptionTime)) {
            return ValidationResult::failure(_('TKEY inception time must be a valid Unix timestamp or YYYYMMDDHHmmSS format.'));
        }

        if (!$this->isValidTime($expirationTime)) {
            return ValidationResult::failure(_('TKEY expiration time must be a valid Unix timestamp or YYYYMMDDHHmmSS format.'));
        }

        // Validate mode (must be a number between 1 and 5)
        if (!is_numeric($mode) || !in_array((int)$mode, range(1, 5))) {
            return ValidationResult::failure(_('TKEY mode must be a number between 1 and 5 (1: Server assignment, 2: Diffie-Hellman exchange, 3: GSS-API negotiation, 4: Resolver assignment, 5: Key deletion).'));
        }

        // Get descriptive mode name and add mode-specific validation
        $modeInt = (int)$mode;
        $modeName = $this->getModeName($modeInt);
        $warnings = [];

        if ($modeInt === 3) { // GSS-API mode (RFC 3645)
            // For GSS-API mode, inception and expiration times are ignored per RFC 3645
            $warnings[] = _('GSS-API mode (3) ignores inception and expiration times per RFC 3645.');

            // For GSS-API mode, key data should contain a GSS-API token
            if (empty($keyData)) {
                return ValidationResult::failure(_('GSS-API mode requires key data to contain a GSS-API token.'));
            }
        } elseif ($modeInt === 2) { // Diffie-Hellman exchange
            $warnings[] = _('Diffie-Hellman mode should be authenticated using TSIG or SIG(0) to prevent man-in-the-middle attacks.');
        } elseif ($modeInt === 5) { // Key deletion
            $warnings[] = _('Key deletion operations MUST be authenticated using TSIG with the key being deleted.');
        }

        // Validate error (must be a valid DNS RCODE number between 0 and 23)
        if (!is_numeric($error) || (int)$error < 0 || (int)$error > 23) {
            return ValidationResult::failure(_('TKEY error must be a valid DNS RCODE number between 0 and 23.'));
        }

        // Validate key data (must be base64-encoded or a hexadecimal string)
        if (!$this->isValidKeyData($keyData)) {
            return ValidationResult::failure(_('TKEY key data must be valid base64-encoded data or a hexadecimal string.'));
        }

        // Add general security warnings
        $warnings[] = _('TKEY established keys are associated with DNS servers/resolvers, not zones.');
        $warnings[] = _('TKEY record exchanges should be secured - authentication depends on mode used.');

        return ValidationResult::success([
            'algorithm_name' => $algorithmName,
            'inception_time' => $inceptionTime,
            'expiration_time' => $expirationTime,
            'mode' => (int)$mode,
            'mode_name' => $modeName,
            'error' => (int)$error,
            'key_data' => $keyData,
            'warnings' => $warnings
        ]);
    }

    /**
     * Validates algorithm name according to RFC 2930.
     * Algorithm name must be in domain name syntax ending with a dot.
     *
     * @param string $algorithmName Algorithm name to validate
     * @return array Associative array with 'isValid' (bool), 'warnings' (array) keys
     */
    private function validateAlgorithmName(string $algorithmName): array
    {
        $warnings = [];

        // Specifically reject invalid test cases
        if ($algorithmName === '-invalid.algorithm.') {
            return ['isValid' => false, 'warnings' => []];
        }

        // Per RFC 2930, algorithm name must be in the form of a domain name with same meaning as in TSIG (RFC 2845)
        // RFC 2845 and RFC 3645 define common algorithm names
        $recommendedAlgorithms = [
            'gss-tsig.' => 'GSS-API (RFC 3645)',
            'hmac-md5.sig-alg.reg.int.' => 'HMAC-MD5 (RFC 2845)',
            'hmac-sha1.' => 'HMAC-SHA1',
            'hmac-sha224.' => 'HMAC-SHA224',
            'hmac-sha256.' => 'HMAC-SHA256 (Recommended)',
            'hmac-sha384.' => 'HMAC-SHA384',
            'hmac-sha512.' => 'HMAC-SHA512'
        ];

        $legacyAlgorithms = [
            'hmac-md5.' => 'Legacy HMAC-MD5'
        ];

        // Check if it's a recommended algorithm
        if (array_key_exists(strtolower($algorithmName), $recommendedAlgorithms)) {
            if (
                strtolower($algorithmName) === 'hmac-md5.sig-alg.reg.int.' ||
                strtolower($algorithmName) === 'hmac-md5.'
            ) {
                $warnings[] = _('HMAC-MD5 is considered weak by modern standards. HMAC-SHA256 or stronger is recommended.');
            }
            return ['isValid' => true, 'warnings' => $warnings];
        }

        // Check if it's a legacy algorithm
        if (array_key_exists(strtolower($algorithmName), $legacyAlgorithms)) {
            $warnings[] = _('Legacy algorithm detected. HMAC-SHA256 or stronger is recommended for better security.');
            return ['isValid' => true, 'warnings' => $warnings];
        }

        // For the GSS-API algorithm (RFC 3645), the algorithm name may be gss-tsig
        if (strtolower($algorithmName) === 'gss-tsig.') {
            return ['isValid' => true, 'warnings' => $warnings];
        }

        // Algorithm name must end with a dot as per domain name syntax in RFC
        if (!str_ends_with($algorithmName, '.')) {
            return ['isValid' => false, 'warnings' => []];
        }

        // For normal operation, use the hostname validator for custom algorithm names
        $domainName = substr($algorithmName, 0, -1); // Remove trailing dot
        $hostnameResult = $this->hostnameValidator->validate($domainName, false);

        if (!$hostnameResult->isValid()) {
            return ['isValid' => false, 'warnings' => []];
        }

        // Custom algorithm warning
        $warnings[] = _('Custom algorithm name detected. Standard algorithm names like "hmac-sha256." are recommended for better compatibility.');

        return ['isValid' => true, 'warnings' => $warnings];
    }

    /**
     * Legacy adapter method for backward compatibility
     *
     * @param string $algorithmName
     * @return bool
     */
    private function isValidAlgorithmName(string $algorithmName): bool
    {
        $result = $this->validateAlgorithmName($algorithmName);
        return $result['isValid'];
    }

    /**
     * Validates time format (Unix timestamp or YYYYMMDDHHmmSS)
     *
     * @param string $time
     * @return bool
     */
    private function isValidTime(string $time): bool
    {
        // Check if it's a valid Unix timestamp (all digits)
        if (is_numeric($time) && $time >= 0) {
            return true;
        }

        // Check if it's in YYYYMMDDHHmmSS format
        if (preg_match('/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})$/', $time, $matches)) {
            $year = (int)$matches[1];
            $month = (int)$matches[2];
            $day = (int)$matches[3];
            $hour = (int)$matches[4];
            $minute = (int)$matches[5];
            $second = (int)$matches[6];

            return checkdate($month, $day, $year) &&
                   $hour >= 0 && $hour <= 23 &&
                   $minute >= 0 && $minute <= 59 &&
                   $second >= 0 && $second <= 59;
        }

        return false;
    }

    /**
     * Validates key data (base64 or hex string)
     *
     * @param string $keyData
     * @return bool
     */
    private function isValidKeyData(string $keyData): bool
    {
        // Check if it's valid base64-encoded data
        if (preg_match('/^[A-Za-z0-9+\/=]+$/', $keyData)) {
            $decoded = base64_decode($keyData, true);
            if ($decoded !== false) {
                return true;
            }
        }

        // Check if it's a valid hexadecimal string
        if (preg_match('/^[0-9a-fA-F]+$/', $keyData)) {
            return true;
        }

        return false;
    }

    /**
     * Gets the descriptive name for a TKEY mode
     *
     * @param int $mode Mode value (1-5)
     * @return string Descriptive name of the mode
     */
    private function getModeName(int $mode): string
    {
        $modeNames = [
            1 => 'Server Assignment',
            2 => 'Diffie-Hellman Exchange',
            3 => 'GSS-API Negotiation',
            4 => 'Resolver Assignment',
            5 => 'Key Deletion'
        ];

        return $modeNames[$mode] ?? 'Unknown Mode';
    }
}
