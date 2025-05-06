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
 * CAA record validator
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class CAARecordValidator implements DnsRecordValidatorInterface
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
     * Validates CAA record content
     *
     * @param string $content The content of the CAA record (flags tag value)
     * @param string $name The name of the record
     * @param mixed $prio The priority (unused for CAA records)
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
        $contentResult = $this->validateCAAContent($content);
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

        // CAA records don't use priority
        if (!empty($prio) && $prio != 0) {
            $errors[] = _('Priority field for CAA records must be 0 or empty.');
            return ValidationResult::errors($errors);
        }

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => 0, // CAA records don't use priority
            'ttl' => $validatedTtl
        ]);
    }

    /**
     * Validates the content of a CAA record
     * Format: <flags> <tag> <value>
     *
     * @param string $content The content to validate
     * @return ValidationResult ValidationResult containing validation status or error message
     */
    private function validateCAAContent(string $content): ValidationResult
    {
        // Split the content into flags, tag, and value
        $parts = preg_split('/\s+/', trim($content), 3);
        if (count($parts) !== 3) {
            return ValidationResult::failure(_('CAA record must contain flags, tag, and value separated by spaces.'));
        }

        [$flags, $tag, $value] = $parts;

        // Validate flags (must be 0-255)
        if (!is_numeric($flags) || (int)$flags < 0 || (int)$flags > 255) {
            return ValidationResult::failure(_('CAA flags must be a number between 0 and 255.'));
        }

        // Validate tag (must be one of: issue, issuewild, iodef)
        $validTags = ['issue', 'issuewild', 'iodef'];
        if (!in_array($tag, $validTags)) {
            return ValidationResult::failure(_('CAA tag must be one of: issue, issuewild, iodef.'));
        }

        // Validate value (should be properly quoted for issue/issuewild with CA domain or URL for iodef)
        if ($tag === 'issue' || $tag === 'issuewild') {
            // Value for issue/issuewild should be a properly quoted domain
            $quotedResult = $this->validateQuotedValue($value);
            if (!$quotedResult->isValid()) {
                return $quotedResult;
            }
        } elseif ($tag === 'iodef') {
            // Value for iodef should be a properly quoted URL
            $quotedResult = $this->validateQuotedValue($value);
            if (!$quotedResult->isValid()) {
                return $quotedResult;
            }

            // If it's a URL, it should start with http://, https://, or mailto:
            $unquoted = trim($value, '"');
            if (!preg_match('/^(https?:|mailto:)/', $unquoted)) {
                return ValidationResult::failure(_('CAA iodef value must be a URL starting with http://, https://, or mailto:.'));
            }
        }

        return ValidationResult::success(true);
    }

    /**
     * Validate a quoted value
     *
     * @param string $value The value to validate
     * @return ValidationResult ValidationResult containing validation status or error message
     */
    private function validateQuotedValue(string $value): ValidationResult
    {
        if (
            !isset($value[0]) || $value[0] !== '"' ||
            !isset($value[strlen($value) - 1]) || $value[strlen($value) - 1] !== '"'
        ) {
            return ValidationResult::failure(_('Value must be enclosed in quotes.'));
        }

        $subContent = substr($value, 1, -1);
        $pattern = '/(?<!\\\\)"/';

        if (preg_match($pattern, $subContent)) {
            return ValidationResult::failure(_('Backslashes must precede all quotes (") in value content.'));
        }

        return ValidationResult::success(true);
    }
}
