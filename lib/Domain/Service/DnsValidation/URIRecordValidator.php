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
 * URI record validator
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class URIRecordValidator implements DnsRecordValidatorInterface
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
     * Validates URI record content
     *
     * URI records have the format: <priority> <weight> "<target URI>"
     * Example: 10 1 "https://example.com/"
     *
     * @param string $content The content of the URI record
     * @param string $name The name of the record
     * @param mixed $prio The priority value
     * @param int|string|null $ttl The TTL value
     * @param int $defaultTTL The default TTL to use if not specified
     *
     * @return ValidationResult ValidationResult containing validated data or error messages
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL): ValidationResult
    {
        // Validate hostname/name
        $nameResult = StringValidator::validatePrintable($name);
        if (!$nameResult->isValid()) {
            return ValidationResult::failure(_('Invalid characters in name field.'));
        }

        // Validate content
        $contentResult = StringValidator::validatePrintable($content);
        if (!$contentResult->isValid()) {
            return ValidationResult::failure(_('Invalid characters in content field.'));
        }

        // Parse URI record parts: <priority> <weight> "<target URI>"
        $validationResult = $this->isValidURIRecordFormat($content);
        if (!$validationResult['isValid']) {
            return ValidationResult::errors($validationResult['errors']);
        }

        // Validate TTL
        $ttlResult = $this->ttlValidator->validate($ttl, $defaultTTL);
        if (!$ttlResult->isValid()) {
            return $ttlResult;
        }
        $ttlData = $ttlResult->getData();
        $validatedTtl = is_array($ttlData) && isset($ttlData['ttl']) ? $ttlData['ttl'] : $ttlData;

        // Use the provided priority if available, otherwise the priority from the content
        $priority = ($prio !== '' && $prio !== null) ? (int)$prio : $this->extractPriorityFromContent($content);

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => $priority,
            'ttl' => $validatedTtl
        ]);
    }

    /**
     * Check if content follows URI record format: <priority> <weight> "<target URI>"
     *
     * @param string $content The content to validate
     * @return array Array with 'isValid' (bool) and 'errors' (array) keys
     */
    private function isValidURIRecordFormat(string $content): array
    {
        $errors = [];

        // Simple regex to match URI record format
        if (!preg_match('/^(\d+)\s+(\d+)\s+"(.*)"$/', $content, $matches)) {
            $errors[] = _('URI record must be in the format: <priority> <weight> "<target URI>"');
            return ['isValid' => false, 'errors' => $errors];
        }

        $priority = (int)$matches[1];
        $weight = (int)$matches[2];
        $uri = $matches[3];

        // Validate priority (0-65535)
        if ($priority < 0 || $priority > 65535) {
            $errors[] = _('URI priority must be between 0 and 65535.');
            return ['isValid' => false, 'errors' => $errors];
        }

        // Validate weight (0-65535)
        if ($weight < 0 || $weight > 65535) {
            $errors[] = _('URI weight must be between 0 and 65535.');
            return ['isValid' => false, 'errors' => $errors];
        }

        // Validate URI format (must start with a protocol)
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:/', $uri)) {
            $errors[] = _('URI must start with a valid protocol (like http:, https:, mailto:, etc).');
            return ['isValid' => false, 'errors' => $errors];
        }

        // If protocol requires //, verify it's present (except for special protocols like mailto:)
        $requiresSlashes = !preg_match('/^(mailto|tel|sms|bitcoin):/i', $uri);
        if ($requiresSlashes && !preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:\/\//', $uri)) {
            $errors[] = _('URI with this protocol must include "://" after the protocol name.');
            return ['isValid' => false, 'errors' => $errors];
        }

        return ['isValid' => true, 'errors' => []];
    }

    /**
     * Extract priority value from URI record content
     *
     * @param string $content The URI record content
     * @return int The priority value
     */
    private function extractPriorityFromContent(string $content): int
    {
        preg_match('/^(\d+)\s+/', $content, $matches);
        return isset($matches[1]) ? (int)$matches[1] : 0;
    }
}
