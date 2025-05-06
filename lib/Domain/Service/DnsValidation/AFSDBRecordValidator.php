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
 * AFSDB record validator
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class AFSDBRecordValidator implements DnsRecordValidatorInterface
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
     * Validates AFSDB record content
     *
     * @param string $content The content of the AFSDB record
     * @param string $name The name of the record
     * @param mixed $prio The subtype value for AFSDB record
     * @param int|string|null $ttl The TTL value
     * @param int $defaultTTL The default TTL to use if not specified
     *
     * @return ValidationResult<array> ValidationResult containing validated data or error messages
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL): ValidationResult
    {
        $errors = [];

        // Validate name (domain name)
        $nameResult = $this->hostnameValidator->validate($name, true);
        if (!$nameResult->isValid()) {
            return $nameResult;
        }
        $nameData = $nameResult->getData();
        $name = $nameData['hostname'];

        // Validate AFSDB content (hostname)
        $contentResult = $this->hostnameValidator->validate($content, false);
        if (!$contentResult->isValid()) {
            return ValidationResult::errors(
                array_merge([_('Invalid AFSDB hostname.')], $contentResult->getErrors())
            );
        }
        $contentData = $contentResult->getData();
        $content = $contentData['hostname'];

        // Validate subtype (stored in priority field)
        $subtypeResult = $this->validateSubtype($prio);
        if (!$subtypeResult->isValid()) {
            return $subtypeResult;
        }
        $validatedSubtype = $subtypeResult->getData();

        // Validate TTL
        $ttlResult = $this->ttlValidator->validate($ttl, $defaultTTL);
        if (!$ttlResult->isValid()) {
            return ValidationResult::errors(array_merge($errors, $ttlResult->getErrors()));
        }
        $ttlData = $ttlResult->getData();
        $validatedTtl = is_array($ttlData) && isset($ttlData['ttl']) ? $ttlData['ttl'] : $ttlData;

        if (!empty($errors)) {
            return ValidationResult::errors($errors);
        }

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => $validatedSubtype,
            'ttl' => $validatedTtl
        ]);
    }

    /**
     * Validate subtype for AFSDB records
     * AFSDB records accept subtypes 1 (AFS cell database server) or 2 (DCE authenticated name server)
     *
     * @param mixed $subtype Subtype value
     *
     * @return ValidationResult<int> ValidationResult with validated subtype or error message
     */
    private function validateSubtype(mixed $subtype): ValidationResult
    {
        // If subtype is not provided or empty, use default of 1
        if (!isset($subtype) || $subtype === "") {
            return ValidationResult::success(1);
        }

        // Subtype should be either 1 or 2 for AFSDB
        if (is_numeric($subtype) && ($subtype == 1 || $subtype == 2)) {
            return ValidationResult::success((int)$subtype);
        }

        return ValidationResult::failure(_('Invalid AFSDB subtype. Must be 1 (AFS cell database server) or 2 (DCE authenticated name server).'));
    }
}
