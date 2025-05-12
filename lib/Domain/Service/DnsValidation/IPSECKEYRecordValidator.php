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
 * IPSECKEY record validator
 *
 * The IPSECKEY record type is used to store IPsec keying material in DNS. This allows
 * devices to set up IPsec tunnels with each other by looking up the required keying material
 * in the DNS. The record structure provides a flexible format for storing various types of
 * keying material for different IPsec protocols.
 *
 * Format: <precedence> <gateway type> <algorithm> <gateway> <public key>
 *
 * Where:
 * - precedence: An 8-bit unsigned integer priority value (0-255)
 * - gateway type: Indicates gateway format (0=No gateway, 1=IPv4, 2=IPv6, 3=Domain name)
 * - algorithm: Indicates public key format (0=No key, 1=RSA, 2=DSA, 3=ECDSA, 4=Ed25519)
 * - gateway: The gateway element formatted according to gateway type
 * - public key: Base64 encoded key material (optional if algorithm=0)
 *
 * Examples:
 * - 10 1 2 192.0.2.38 AQNRU3mG7TVTO2BkR47usntb102uFJtugbo6BSGvgqt4AQ==
 * - 10 0 2 . AQNRU3mG7TVTO2BkR47usntb102uFJtugbo6BSGvgqt4AQ==
 * - 10 2 2 2001:db8:0:8002::2000:1 AQNRU3mG7TVTO2BkR47usntb102uFJtugbo6BSGvgqt4AQ==
 * - 10 3 2 mygateway.example.com. AQNRU3mG7TVTO2BkR47usntb102uFJtugbo6BSGvgqt4AQ==
 *
 * @see https://www.rfc-editor.org/rfc/rfc4025.html RFC 4025: A Method for Storing IPsec Keying Material in DNS
 * @see https://www.iana.org/assignments/dns-parameters IANA DNS Parameters
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class IPSECKEYRecordValidator implements DnsRecordValidatorInterface
{
    private ConfigurationManager $config;
    private HostnameValidator $hostnameValidator;
    private TTLValidator $ttlValidator;
    private IPAddressValidator $ipValidator;

    public function __construct(ConfigurationManager $config)
    {
        $this->config = $config;
        $this->hostnameValidator = new HostnameValidator($config);
        $this->ttlValidator = new TTLValidator();
        $this->ipValidator = new IPAddressValidator();
    }

    /**
     * Validates IPSECKEY record content
     *
     * IPSECKEY format: <precedence> <gateway type> <algorithm> <gateway> <public key>
     * Example: 10 1 2 192.0.2.1 AQNRU3mG7TVTO2BkR47usntb102uFJtugbo6BSGvgqt4AQ==
     *
     * @param string $content The content of the IPSECKEY record
     * @param string $name The name of the record
     * @param mixed $prio The priority (unused for IPSECKEY records)
     * @param int|string|null $ttl The TTL value
     * @param int $defaultTTL The default TTL to use if not specified
     *
     * @return ValidationResult ValidationResult containing validated data or error messages
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL): ValidationResult
    {
        // Validate hostname/name
        $hostnameResult = $this->hostnameValidator->validate($name, true);
        if (!$hostnameResult->isValid()) {
            return $hostnameResult;
        }
        $hostnameData = $hostnameResult->getData();
        $name = $hostnameData['hostname'];

        // Validate content
        $contentResult = $this->validateIPSECKEYContent($content);
        if (!$contentResult->isValid()) {
            return $contentResult;
        }

        // Get warnings from content validation
        $contentData = $contentResult->getData();
        $contentWarnings = [];
        if (isset($contentData['warnings']) && is_array($contentData['warnings'])) {
            $contentWarnings = $contentData['warnings'];
        }

        // Validate TTL
        $ttlResult = $this->ttlValidator->validate($ttl, $defaultTTL);
        if (!$ttlResult->isValid()) {
            return $ttlResult;
        }
        $ttlData = $ttlResult->getData();
        $validatedTtl = is_array($ttlData) && isset($ttlData['ttl']) ? $ttlData['ttl'] : $ttlData;

        // Validate priority (should be 0 for IPSECKEY records)
        if (!empty($prio) && $prio != 0) {
            return ValidationResult::failure(_('Priority field for IPSECKEY records must be 0 or empty'));
        }

        // Add warnings about security considerations per RFC 4025
        $warnings = [
            _('IPSECKEY records should be used with DNSSEC to ensure secure transmission of keying material (RFC 4025 Section 4).'),
            _('The public key in IPSECKEY records should be regularly rotated for improved security.')
        ];

        // Analyze the content for gateway type and add specific warnings if needed
        $parts = preg_split('/\s+/', trim($content));
        $gatewayType = $parts[1] ?? '';

        if ($gatewayType === '3') {
            // Domain name gateway
            $warnings[] = _('When using domain name gateways, ensure both forward and reverse DNS records exist and are properly secured with DNSSEC.');
        }

        // Add any warnings from the content validation
        $warnings = array_merge($warnings, $contentWarnings);

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => 0,
            'ttl' => $validatedTtl
        ], $warnings);
    }

    /**
     * Validates the content of an IPSECKEY record according to RFC 4025
     * Format: <precedence> <gateway type> <algorithm> <gateway> <public key>
     *
     * @param string $content The content to validate
     * @return ValidationResult ValidationResult with errors or success
     *
     * @see https://www.rfc-editor.org/rfc/rfc4025.html RFC 4025 Section 2
     */
    private function validateIPSECKEYContent(string $content): ValidationResult
    {
        // Basic validation of printable characters
        $printableResult = StringValidator::validatePrintable($content);
        if (!$printableResult->isValid()) {
            return ValidationResult::failure(_('Invalid characters in IPSECKEY record content.'));
        }

        // Split the content into parts
        $parts = preg_split('/\s+/', trim($content));
        if (count($parts) < 5) {
            return ValidationResult::failure(_('IPSECKEY record must contain precedence, gateway type, algorithm, gateway, and public key.'));
        }

        [$precedence, $gatewayType, $algorithm, $gateway] = array_slice($parts, 0, 4);
        $publicKey = implode(' ', array_slice($parts, 4));
        $warnings = [];

        // Validate precedence (0-255) - RFC 4025 Section 2.1
        if (!is_numeric($precedence) || (int)$precedence < 0 || (int)$precedence > 255) {
            return ValidationResult::failure(_('IPSECKEY precedence must be a number between 0 and 255.'));
        }

        // Validate gateway type (0-3) - RFC 4025 Section 2.2
        // 0 = No gateway, 1 = IPv4, 2 = IPv6, 3 = Domain name
        if (!in_array($gatewayType, ['0', '1', '2', '3'])) {
            return ValidationResult::failure(_('IPSECKEY gateway type must be 0 (No gateway), 1 (IPv4), 2 (IPv6), or 3 (Domain name)'));
        }

        // Validate algorithm (0-4) - RFC 4025 Section 2.4
        // 0 = No key, 1 = RSA (RFC 3110), 2 = DSA (RFC 2536), 3 = ECDSA, 4 = Ed25519
        if (!in_array($algorithm, ['0', '1', '2', '3', '4'])) {
            return ValidationResult::failure(_('IPSECKEY algorithm must be 0 (No key), 1 (RSA), 2 (DSA), 3 (ECDSA), or 4 (Ed25519).'));
        }

        // Validate gateway based on gateway type
        switch ($gatewayType) {
            case '0': // No gateway - RFC 4025 Section 2.5.1
                if ($gateway !== '.') {
                    return ValidationResult::failure(_('For gateway type 0 (No gateway), gateway must be ".".'));
                }
                break;

            case '1': // IPv4 - RFC 4025 Section 2.5.2
                $ipv4Result = $this->ipValidator->validateIPv4($gateway);
                if (!$ipv4Result->isValid()) {
                    return ValidationResult::failure(_('For gateway type 1, gateway must be a valid IPv4 address.'));
                }
                break;

            case '2': // IPv6 - RFC 4025 Section 2.5.3
                $ipv6Result = $this->ipValidator->validateIPv6($gateway);
                if (!$ipv6Result->isValid()) {
                    return ValidationResult::failure(_('For gateway type 2, gateway must be a valid IPv6 address.'));
                }
                break;

            case '3': // Domain name - RFC 4025 Section 2.5.4
                // Validate the domain name as per RFC 1035
                // DNS labels must be 63 octets or less and the whole name must be 255 octets or less
                $gateway = rtrim($gateway, '.');
                $gatewayResult = $this->hostnameValidator->validate($gateway, false);
                if (!$gatewayResult->isValid()) {
                    return ValidationResult::failure(_('For gateway type 3, gateway must be a valid domain name.'));
                }
                break;
        }

        // Check for public key format validity (Base64 encoding)
        if ($algorithm !== '0') {
            // Public key is required when algorithm is not 0
            if (empty($publicKey)) {
                return ValidationResult::failure(_('IPSECKEY public key is required when algorithm is not 0.'));
            }

            // Validate Base64 encoding
            if (!preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $publicKey)) {
                return ValidationResult::failure(_('IPSECKEY public key must be properly Base64 encoded.'));
            }

            // Algorithm-specific key format validation could be added here
            switch ($algorithm) {
                case '1': // RSA
                    // RSA key format is defined in RFC 3110
                    $warnings[] = _('RSA keys should follow the format defined in RFC 3110.');
                    break;
                case '2': // DSA
                    // DSA key format is defined in RFC 2536
                    $warnings[] = _('DSA keys should follow the format defined in RFC 2536.');
                    break;
            }
        } else {
            // Algorithm is 0 (No key)
            $warnings[] = _('When algorithm is 0 (No key), the public key field is typically empty or ignored.');
        }

        return ValidationResult::success([
            'valid' => true,
            'warnings' => $warnings
        ]);
    }
}
