<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2026 Poweradmin Development Team
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
 * BRID (Broadcast Remote ID) record validator
 *
 * BRID is one of the DRIP (Drone Remote ID Protocol) DNS record types supported
 * by PowerDNS Authoritative 5.1+. The RDATA is an opaque binary blob represented
 * in zone-file form as base64.
 */
class BRIDRecordValidator implements DnsRecordValidatorInterface
{
    private HostnameValidator $hostnameValidator;
    private TTLValidator $ttlValidator;

    public function __construct(ConfigurationManager $config)
    {
        $this->hostnameValidator = new HostnameValidator($config);
        $this->ttlValidator = new TTLValidator();
    }

    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL, ...$args): ValidationResult
    {
        $hostnameResult = $this->hostnameValidator->validate($name, true);
        if (!$hostnameResult->isValid()) {
            return $hostnameResult;
        }
        $validatedName = $hostnameResult->getData()['hostname'];

        $contentResult = Base64BlobValidator::validate($content, _('BRID record content must be a non-empty base64-encoded blob.'));
        if (!$contentResult->isValid()) {
            return $contentResult;
        }
        $normalisedContent = $contentResult->getData()['compact'];

        $ttlResult = $this->ttlValidator->validate($ttl, $defaultTTL);
        if (!$ttlResult->isValid()) {
            return $ttlResult;
        }
        $ttlData = $ttlResult->getData();
        $validatedTtl = is_array($ttlData) && isset($ttlData['ttl']) ? $ttlData['ttl'] : $ttlData;

        if ($prio !== null && $prio !== '' && !(is_numeric($prio) && (int) $prio === 0)) {
            return ValidationResult::failure(_('Priority must be 0 for BRID records.'));
        }

        return ValidationResult::success([
            'content' => $normalisedContent,
            'name' => $validatedName,
            'prio' => 0,
            'ttl' => $validatedTtl,
        ]);
    }
}
