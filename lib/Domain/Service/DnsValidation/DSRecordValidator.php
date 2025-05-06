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
 * Validator for DS (Delegation Signer) DNS records
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class DSRecordValidator implements DnsRecordValidatorInterface
{
    private HostnameValidator $hostnameValidator;
    private TTLValidator $ttlValidator;

    /**
     * Constructor
     *
     * @param ConfigurationManager $config
     */
    public function __construct(ConfigurationManager $config)
    {
        $this->hostnameValidator = new HostnameValidator($config);
        $this->ttlValidator = new TTLValidator();
    }

    /**
     * Validate DS record
     *
     * @param string $content Content part of record
     * @param string $name Name part of record
     * @param mixed $prio Priority
     * @param mixed $ttl TTL value
     * @param int $defaultTTL Default TTL to use if TTL is empty
     *
     * @return ValidationResult<array> ValidationResult containing validated data or error messages
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL): ValidationResult
    {
        $errors = [];

        // Validate the hostname
        $hostnameResult = $this->hostnameValidator->validate($name, true);
        if (!$hostnameResult->isValid()) {
            return $hostnameResult;
        }
        $hostnameData = $hostnameResult->getData();
        $name = $hostnameData['hostname'];

        // Validate DS record content
        $contentResult = $this->validateDSContent($content);
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

        // Priority for DS records should be 0
        if (!empty($prio) && $prio != 0) {
            $errors[] = _('Priority field for DS records must be 0 or empty.');
            return ValidationResult::errors($errors);
        }

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => 0,
            'ttl' => $validatedTtl
        ]);
    }

    /**
     * Validate DS record content format
     *
     * @param string $content DS record content
     *
     * @return ValidationResult<bool> ValidationResult containing validation status or error message
     */
    private function validateDSContent(string $content): ValidationResult
    {
        // DS record format: <key-tag> <algorithm> <digest-type> <digest>
        if (!preg_match('/^([0-9]+) ([0-9]+) ([0-9]+) ([a-f0-9]+)$/i', $content)) {
            return ValidationResult::failure(_('DS record must be in the format: <key-tag> <algorithm> <digest-type> <digest>'));
        }

        // Split content into components
        $parts = explode(' ', $content);
        if (count($parts) !== 4) {
            return ValidationResult::failure(_('DS record must contain exactly 4 fields'));
        }

        list($keyTag, $algorithm, $digestType, $digest) = $parts;

        // Validate key tag (1-65535)
        if (!is_numeric($keyTag) || $keyTag < 1 || $keyTag > 65535) {
            return ValidationResult::failure(_('Key tag must be a number between 1 and 65535'));
        }

        // Validate algorithm (known DNSSEC algorithms 1-16)
        $validAlgorithms = [1, 2, 3, 5, 6, 7, 8, 10, 12, 13, 14, 15, 16];
        if (!in_array((int)$algorithm, $validAlgorithms)) {
            return ValidationResult::failure(_('Algorithm must be one of: 1, 2, 3, 5, 6, 7, 8, 10, 12, 13, 14, 15, 16'));
        }

        // Validate digest type (1 = SHA-1, 2 = SHA-256, 4 = SHA-384)
        $validDigestTypes = [1, 2, 4];
        if (!in_array((int)$digestType, $validDigestTypes)) {
            return ValidationResult::failure(_('Digest type must be one of: 1 (SHA-1), 2 (SHA-256), 4 (SHA-384)'));
        }

        // Validate digest length based on type
        $digestLength = strlen($digest);
        switch ((int)$digestType) {
            case 1: // SHA-1
                if ($digestLength !== 40) {
                    return ValidationResult::failure(_('SHA-1 digest must be exactly 40 hexadecimal characters'));
                }
                break;
            case 2: // SHA-256
                if ($digestLength !== 64) {
                    return ValidationResult::failure(_('SHA-256 digest must be exactly 64 hexadecimal characters'));
                }
                break;
            case 4: // SHA-384
                if ($digestLength !== 96) {
                    return ValidationResult::failure(_('SHA-384 digest must be exactly 96 hexadecimal characters'));
                }
                break;
        }

        return ValidationResult::success(true);
    }

    /**
     * Validate DS record content format for public use
     *
     * @param string $content DS record content
     *
     * @return ValidationResult<bool> ValidationResult containing validation status or error message
     */
    public function validateDSRecordContent(string $content): ValidationResult
    {
        return $this->validateDSContent($content);
    }
}
