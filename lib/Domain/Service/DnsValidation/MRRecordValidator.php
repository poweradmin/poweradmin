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
 * MR record validator
 *
 * MR (Mail Rename) records specify a domain name which is the new name for the mailbox
 * specified in the record name. It is an obsolete record type, but still supported.
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class MRRecordValidator implements DnsRecordValidatorInterface
{
    private TTLValidator $ttlValidator;
    private HostnameValidator $hostnameValidator;

    public function __construct(ConfigurationManager $config)
    {
        $this->ttlValidator = new TTLValidator();
        $this->hostnameValidator = new HostnameValidator($config);
    }

    /**
     * Validate an MR record
     *
     * @param string $content The content part of the record (new mailbox name)
     * @param string $name The name part of the record (old mailbox name)
     * @param mixed $prio The priority value (not used for MR records)
     * @param int|string $ttl The TTL value
     * @param int $defaultTTL The default TTL to use if not specified
     *
     * @return ValidationResult<array> Validation result with data or errors
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL): ValidationResult
    {
        // Validate content - ensure it's not empty
        if (empty(trim($content))) {
            return ValidationResult::failure(_('MR record content cannot be empty.'));
        }

        // Validate that content is a valid hostname
        $hostnameResult = $this->hostnameValidator->validate($content, false);
        if (!$hostnameResult->isValid()) {
            return ValidationResult::failure(_('MR record content must be a valid domain name.'));
        }

        // Validate TTL
        $ttlResult = $this->ttlValidator->validate($ttl, $defaultTTL);
        if (!$ttlResult->isValid()) {
            return $ttlResult;
        }
        $ttlData = $ttlResult->getData();
        $validatedTtl = is_array($ttlData) && isset($ttlData['ttl']) ? $ttlData['ttl'] : $ttlData;

        // MR records don't use priority, so it's always 0
        $priority = 0;

        return ValidationResult::success([
            'content' => $content,
            'ttl' => $validatedTtl,
            'priority' => $priority,
            'name' => $name
        ]);
    }
}
