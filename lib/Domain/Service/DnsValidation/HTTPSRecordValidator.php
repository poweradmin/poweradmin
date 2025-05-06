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
 * HTTPS record validator
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class HTTPSRecordValidator implements DnsRecordValidatorInterface
{
    private HostnameValidator $hostnameValidator;
    private TTLValidator $ttlValidator;

    public function __construct(ConfigurationManager $config)
    {
        $this->hostnameValidator = new HostnameValidator($config);
        $this->ttlValidator = new TTLValidator();
    }

    /**
     * Validates HTTPS record content
     *
     * @param string $content The content of the HTTPS record (priority target [key=value...])
     * @param string $name The name of the record
     * @param mixed $prio The priority (unused for HTTPS records, priority is part of content)
     * @param int|string $ttl The TTL value
     * @param int $defaultTTL The default TTL to use if not specified
     *
     * @return ValidationResult Validation result with data or errors
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
        $contentResult = $this->validateHTTPSContent($content);
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

        // Check if priority was provided separately (it shouldn't be for HTTPS records)
        if (!empty($prio) && $prio != 0) {
            return ValidationResult::failure(_('Priority field should not be used for HTTPS records as priority is part of the content.'));
        }

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => 0, // Priority is included in the content for HTTPS records
            'ttl' => $validatedTtl
        ]);
    }

    /**
     * Validates HTTPS record content
     * Format: <priority> <target> [key=value...]
     *
     * @param string $content The content to validate
     * @return ValidationResult Validation result with success or error message
     */
    private function validateHTTPSContent(string $content): ValidationResult
    {
        // Split the content into parts
        $parts = preg_split('/\s+/', trim($content), 3);

        // Must have at least priority and target
        if (count($parts) < 2) {
            return ValidationResult::failure(_('HTTPS record must contain at least priority and target values.'));
        }

        [$priority, $target] = $parts;

        // Validate priority (must be a number between 0 and 65535)
        if (!is_numeric($priority) || (int)$priority < 0 || (int)$priority > 65535) {
            return ValidationResult::failure(_('HTTPS record priority must be a number between 0 and 65535.'));
        }

        // Validate target (must be either "." or a valid hostname)
        if ($target !== ".") {
            $targetResult = $this->hostnameValidator->validate($target, true);
            if (!$targetResult->isValid()) {
                return ValidationResult::failure(_('HTTPS record target must be either "." or a valid fully-qualified domain name.'));
            }
        }

        // If there are key-value parameters, validate them
        if (count($parts) > 2) {
            $params = $parts[2];

            // Basic check for parameter format
            $paramsResult = $this->validateHTTPSParams($params);
            if (!$paramsResult->isValid()) {
                return $paramsResult;
            }
        }

        return ValidationResult::success(true);
    }

    /**
     * Validate HTTPS parameters
     *
     * @param string $params The parameter string to validate
     * @return ValidationResult Validation result with success or error message
     */
    private function validateHTTPSParams(string $params): ValidationResult
    {
        // Split the params string by space
        $paramsList = preg_split('/\s+/', trim($params));

        foreach ($paramsList as $param) {
            // Each parameter should be in key=value format
            if (!preg_match('/^[a-z0-9]+=[^=\s]+$/i', $param)) {
                return ValidationResult::failure(_('HTTPS record parameters must be in key=value format separated by spaces.'));
            }
        }

        return ValidationResult::success(true);
    }
}
