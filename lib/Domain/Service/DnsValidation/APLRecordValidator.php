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
 * Validator for APL (Address Prefix List) DNS records
 * RFC 3123: https://tools.ietf.org/html/rfc3123
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class APLRecordValidator implements DnsRecordValidatorInterface
{
    private HostnameValidator $hostnameValidator;
    private TTLValidator $ttlValidator;
    private IPAddressValidator $ipValidator;
    private ConfigurationManager $config;

    /**
     * Constructor
     *
     * @param ConfigurationManager $config
     */
    public function __construct(ConfigurationManager $config)
    {
        $this->hostnameValidator = new HostnameValidator($config);
        $this->ttlValidator = new TTLValidator();
        $this->ipValidator = new IPAddressValidator();
        $this->config = $config;
    }

    /**
     * Validate APL record
     *
     * @param string $content APL content in format "1:192.0.2.0/24 2:2001:db8::/32"
     * @param string $name Record hostname
     * @param mixed $prio Priority (not used for APL records)
     * @param int|string|null $ttl TTL value
     * @param int $defaultTTL Default TTL value
     *
     * @return ValidationResult ValidationResult containing validated data or error messages
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL): ValidationResult
    {
        // 1. Validate hostname
        $hostnameResult = $this->hostnameValidator->validate($name, true);
        if (!$hostnameResult->isValid()) {
            return $hostnameResult;
        }
        $hostnameData = $hostnameResult->getData();
        $name = $hostnameData['hostname'];

        // 2. Validate APL content
        $contentResult = $this->validateAPLContent($content);
        if (!$contentResult->isValid()) {
            return $contentResult;
        }

        // 3. Validate TTL
        $ttlResult = $this->ttlValidator->validate($ttl, $defaultTTL);
        if (!$ttlResult->isValid()) {
            return $ttlResult;
        }
        $ttlData = $ttlResult->getData();
        $validatedTtl = is_array($ttlData) && isset($ttlData['ttl']) ? $ttlData['ttl'] : $ttlData;

        // 4. Validate priority (should be 0 for APL records)
        $prioResult = $this->validatePriority($prio);
        if (!$prioResult->isValid()) {
            return $prioResult;
        }
        $validatedPrio = $prioResult->getData();

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => $validatedPrio,
            'ttl' => $validatedTtl
        ]);
    }

    /**
     * Validate priority for APL records
     * APL records don't use priority, so it should be 0
     *
     * @param mixed $prio Priority value
     *
     * @return ValidationResult ValidationResult containing validated priority or error message
     */
    private function validatePriority(mixed $prio): ValidationResult
    {
        // If priority is not provided or empty, set it to 0
        if (!isset($prio) || $prio === "") {
            return ValidationResult::success(0);
        }

        // If provided, ensure it's 0 for APL records
        if (is_numeric($prio) && intval($prio) === 0) {
            return ValidationResult::success(0);
        }

        return ValidationResult::failure(_('Invalid value for priority field. APL records must have priority value of 0.'));
    }

    /**
     * Validate APL content format
     * Examples: "1:192.0.2.0/24" or "2:2001:db8::/32" or "1:192.0.2.0/24 !2:2001:db8::/32"
     *
     * @param string $content The APL content to validate
     * @return ValidationResult ValidationResult containing validation status or error message
     */
    private function validateAPLContent(string $content): ValidationResult
    {
        // Handle empty content
        if (empty(trim($content))) {
            return ValidationResult::failure(_('APL record content cannot be empty.'));
        }

        // Split content by whitespace to handle multiple address prefix elements
        $prefixElements = preg_split('/\s+/', trim($content));

        foreach ($prefixElements as $element) {
            $elementResult = $this->validateAPLElement($element);
            if (!$elementResult->isValid()) {
                return $elementResult;
            }
        }

        return ValidationResult::success(true);
    }

    /**
     * Validate a single APL element
     * Format: [!]afi:address/prefix
     *
     * @param string $element The APL element to validate
     * @return ValidationResult ValidationResult containing validation status or error message
     */
    private function validateAPLElement(string $element): ValidationResult
    {
        // Check if element starts with negation
        $negation = false;
        if (str_starts_with($element, '!')) {
            $negation = true;
            $element = substr($element, 1);
        }

        // Check for afi:address/prefix format
        if (!preg_match('/^(\d+):([^\/]+)\/(\d+)$/', $element, $matches)) {
            return ValidationResult::failure(_('Invalid APL element format. Expected [!]afi:address/prefix.'));
        }

        $afi = (int)$matches[1];
        $address = $matches[2];
        $prefix = (int)$matches[3];

        // Validate Address Family Identifier (AFI)
        // 1 = IPv4, 2 = IPv6 (as per RFC 3123)
        if ($afi !== 1 && $afi !== 2) {
            return ValidationResult::failure(_('Invalid Address Family Identifier (AFI). Must be 1 for IPv4 or 2 for IPv6.'));
        }

        // Validate address and prefix based on AFI
        if ($afi === 1) {
            // IPv4
            $ipv4Result = $this->ipValidator->validateIPv4($address);
            if (!$ipv4Result->isValid()) {
                return ValidationResult::failure(_('Invalid IPv4 address in APL record.'));
            }

            // IPv4 prefix must be between 0 and 32
            if ($prefix < 0 || $prefix > 32) {
                return ValidationResult::failure(_('IPv4 prefix must be between 0 and 32.'));
            }
        } else {
            // IPv6
            $ipv6Result = $this->ipValidator->validateIPv6($address);
            if (!$ipv6Result->isValid()) {
                return ValidationResult::failure(_('Invalid IPv6 address in APL record.'));
            }

            // IPv6 prefix must be between 0 and 128
            if ($prefix < 0 || $prefix > 128) {
                return ValidationResult::failure(_('IPv6 prefix must be between 0 and 128.'));
            }
        }

        return ValidationResult::success(true);
    }
}
