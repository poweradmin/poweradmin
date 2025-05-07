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
 * SRV record validator
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class SRVRecordValidator implements DnsRecordValidatorInterface
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
     * Validates SRV record content
     *
     * @param string $content The content of the SRV record
     * @param string $name The name of the record
     * @param mixed $prio The priority (used for SRV records)
     * @param int|string|null $ttl The TTL value
     * @param int $defaultTTL The default TTL to use if not specified
     *
     * @return ValidationResult ValidationResult containing validated data or error messages
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL): ValidationResult
    {
        // Validate SRV name
        $nameResult = $this->validateSrvName($name);
        if (!$nameResult->isValid()) {
            return $nameResult;
        }
        $nameData = $nameResult->getData();
        $name = $nameData['name'];

        // Validate SRV content
        $contentResult = $this->validateSrvContent($content, $name);
        if (!$contentResult->isValid()) {
            return $contentResult;
        }
        $contentData = $contentResult->getData();
        $content = $contentData['content'];

        // Validate priority (SRV records use priority)
        $prioResult = $this->validatePriority($prio);
        if (!$prioResult->isValid()) {
            return $prioResult;
        }
        $validatedPrio = $prioResult->getData();

        // Validate TTL
        $ttlResult = $this->ttlValidator->validate($ttl, $defaultTTL);
        if (!$ttlResult->isValid()) {
            return $ttlResult;
        }
        $ttlData = $ttlResult->getData();
        $validatedTtl = is_array($ttlData) && isset($ttlData['ttl']) ? $ttlData['ttl'] : $ttlData;

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => $validatedPrio,
            'ttl' => $validatedTtl
        ]);
    }

    /**
     * Validate SRV record name format
     *
     * @param string $name SRV record name
     *
     * @return ValidationResult ValidationResult containing name data or error message
     */
    private function validateSrvName(string $name): ValidationResult
    {
        if (strlen($name) > 255) {
            return ValidationResult::failure(_('The hostname is too long.'));
        }

        $fields = explode('.', $name, 3);

        // Check if we have all three parts required for an SRV record
        if (count($fields) < 3) {
            return ValidationResult::failure(_('SRV record name must be in format _service._protocol.domain'));
        }

        if (!preg_match('/^_[\w\-]+$/i', $fields[0])) {
            return ValidationResult::failure(_('Invalid service value in name field of SRV record.'));
        }
        if (!preg_match('/^_[\w]+$/i', $fields[1])) {
            return ValidationResult::failure(_('Invalid protocol value in name field of SRV record.'));
        }

        $domainResult = $this->hostnameValidator->validate($fields[2], false);
        if (!$domainResult->isValid()) {
            return ValidationResult::failure(_('Invalid FQDN value in name field of SRV record.'));
        }

        return ValidationResult::success(['name' => join('.', $fields)]);
    }

    /**
     * Validate SRV record content format
     *
     * @param string $content SRV record content
     * @param string $name SRV record name
     *
     * @return ValidationResult ValidationResult containing content data or error message
     */
    private function validateSrvContent(string $content, string $name): ValidationResult
    {
        $fields = preg_split("/\s+/", trim($content));

        // Check if we have exactly 4 fields for an SRV record content
        // Format should be: <priority> <weight> <port> <target>
        if (count($fields) != 4) {
            return ValidationResult::failure(_('SRV record content must have priority, weight, port and target'));
        }

        if (!is_numeric($fields[0]) || $fields[0] < 0 || $fields[0] > 65535) {
            return ValidationResult::failure(_('Invalid value for the priority field of the SRV record.'));
        }
        if (!is_numeric($fields[1]) || $fields[1] < 0 || $fields[1] > 65535) {
            return ValidationResult::failure(_('Invalid value for the weight field of the SRV record.'));
        }
        if (!is_numeric($fields[2]) || $fields[2] < 0 || $fields[2] > 65535) {
            return ValidationResult::failure(_('Invalid value for the port field of the SRV record.'));
        }

        if ($fields[3] == "") {
            return ValidationResult::failure(_('SRV target cannot be empty.'));
        }

        if ($fields[3] != ".") {
            $targetResult = $this->validateTarget($fields[3]);
            if (!$targetResult->isValid()) {
                return $targetResult;
            }
        }

        return ValidationResult::success(['content' => join(' ', $fields)]);
    }

    /**
     * Validate the SRV target hostname
     *
     * @param string $target The target hostname
     *
     * @return ValidationResult ValidationResult containing validation result
     */
    private function validateTarget(string $target): ValidationResult
    {
        $targetResult = $this->hostnameValidator->validate($target, false);
        if (!$targetResult->isValid()) {
            return ValidationResult::failure(_('Invalid SRV target.'));
        }
        return ValidationResult::success(true);
    }

    /**
     * Validate the priority field for SRV records
     *
     * @param mixed $prio The priority value to validate
     * @return ValidationResult ValidationResult containing the validated priority value or error
     */
    private function validatePriority(mixed $prio): ValidationResult
    {
        // If priority is not provided or empty, use default of 10
        if (!isset($prio) || $prio === "") {
            return ValidationResult::success(10);
        }

        // Priority must be a number between 0 and 65535
        if (is_numeric($prio) && $prio >= 0 && $prio <= 65535) {
            return ValidationResult::success((int)$prio);
        }

        return ValidationResult::failure(_('Invalid value for the priority field of the SRV record.'));
    }
}
