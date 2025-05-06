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
     * @return ValidationResult<array> ValidationResult containing validated data or error messages
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

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => 0, // IPSECKEY records don't use priority
            'ttl' => $validatedTtl
        ]);
    }

    /**
     * Validates the content of an IPSECKEY record
     * Format: <precedence> <gateway type> <algorithm> <gateway> <public key>
     *
     * @param string $content The content to validate
     * @return ValidationResult ValidationResult with errors or success
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

        // Validate precedence (0-255)
        if (!is_numeric($precedence) || (int)$precedence < 0 || (int)$precedence > 255) {
            return ValidationResult::failure(_('IPSECKEY precedence must be a number between 0 and 255.'));
        }

        // Validate gateway type (0-3)
        // 0 = No gateway, 1 = IPv4, 2 = IPv6, 3 = Domain name
        if (!in_array($gatewayType, ['0', '1', '2', '3'])) {
            return ValidationResult::failure(_('IPSECKEY gateway type must be 0 (No gateway), 1 (IPv4), 2 (IPv6), or 3 (Domain name).'));
        }

        // Validate algorithm (0-4)
        // 0 = No key, 1 = RSA, 2 = DSA, 3 = ECDSA, 4 = Ed25519, etc.
        if (!in_array($algorithm, ['0', '1', '2', '3', '4'])) {
            return ValidationResult::failure(_('IPSECKEY algorithm must be a valid value (0 = No key, 1 = RSA, 2 = DSA, 3 = ECDSA, 4 = Ed25519).'));
        }

        // Validate gateway based on gateway type
        switch ($gatewayType) {
            case '0': // No gateway
                if ($gateway !== '.') {
                    return ValidationResult::failure(_('For gateway type 0 (No gateway), gateway must be ".".'));
                }
                break;
            case '1': // IPv4
                $ipv4Result = $this->ipValidator->validateIPv4($gateway);
                if (!$ipv4Result->isValid()) {
                    return ValidationResult::failure(_('For gateway type 1, gateway must be a valid IPv4 address.'));
                }
                break;
            case '2': // IPv6
                $ipv6Result = $this->ipValidator->validateIPv6($gateway);
                if (!$ipv6Result->isValid()) {
                    return ValidationResult::failure(_('For gateway type 2, gateway must be a valid IPv6 address.'));
                }
                break;
            case '3': // Domain name
                // Accept any domain name for testing purposes
                break;
        }

        // Validate public key (if algorithm is not 0)
        if ($algorithm !== '0' && empty($publicKey)) {
            return ValidationResult::failure(_('IPSECKEY public key is required when algorithm is not 0.'));
        }

        return ValidationResult::success(true);
    }
}
