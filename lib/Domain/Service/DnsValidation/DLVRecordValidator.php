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

use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Service\MessageService;

/**
 * Validator for DLV (DNSSEC Lookaside Validation) DNS records
 * Similar to DS records but for a different purpose
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class DLVRecordValidator implements DnsRecordValidatorInterface
{
    private HostnameValidator $hostnameValidator;
    private TTLValidator $ttlValidator;
    private MessageService $messageService;

    /**
     * Constructor
     *
     * @param ConfigurationManager $config
     */
    public function __construct(ConfigurationManager $config)
    {
        $this->hostnameValidator = new HostnameValidator($config);
        $this->ttlValidator = new TTLValidator();
        $this->messageService = new MessageService();
    }

    /**
     * Validate DLV record
     *
     * @param string $content Content part of record
     * @param string $name Name part of record
     * @param mixed $prio Priority
     * @param mixed $ttl TTL value
     * @param int $defaultTTL Default TTL to use if TTL is empty
     *
     * @return array|bool Returns array with validated data or false if validation fails
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, $defaultTTL): array|bool
    {
        // Validate the hostname
        $hostnameResult = $this->hostnameValidator->isValidHostnameFqdn($name, 1);
        if ($hostnameResult === false) {
            return false;
        }

        // Validate DLV record content (same format as DS)
        if (!$this->isValidDLVContent($content)) {
            $this->messageService->addSystemError(_('Invalid DLV record content.'));
            return false;
        }

        // Validate TTL
        $validatedTtl = $this->ttlValidator->isValidTTL($ttl, $defaultTTL);
        if ($validatedTtl === false) {
            return false;
        }

        // Priority for DLV records should be 0
        if (!empty($prio) && $prio != 0) {
            $this->messageService->addSystemError(_('Priority field for DLV records must be 0 or empty.'));
            return false;
        }

        return [
            'content' => $content,
            'name' => $hostnameResult['hostname'],
            'prio' => 0,
            'ttl' => $validatedTtl
        ];
    }

    /**
     * Validate DLV record content format
     * DLV has the same format as DS records: <key-tag> <algorithm> <digest-type> <digest>
     *
     * @param string $content DLV record content
     * @return bool True if valid, false otherwise
     */
    public function isValidDLVContent(string $content): bool
    {
        // DLV record format: <key-tag> <algorithm> <digest-type> <digest>
        if (!preg_match('/^([0-9]+) ([0-9]+) ([0-9]+) ([a-f0-9]+)$/i', $content)) {
            return false;
        }

        // Split content into components
        $parts = explode(' ', $content);
        if (count($parts) !== 4) {
            return false;
        }

        list($keyTag, $algorithm, $digestType, $digest) = $parts;

        // Validate key tag (1-65535)
        if (!is_numeric($keyTag) || $keyTag < 1 || $keyTag > 65535) {
            return false;
        }

        // Validate algorithm (known DNSSEC algorithms 1-16)
        $validAlgorithms = [1, 2, 3, 5, 6, 7, 8, 10, 12, 13, 14, 15, 16];
        if (!in_array((int)$algorithm, $validAlgorithms)) {
            return false;
        }

        // Validate digest type (1 = SHA-1, 2 = SHA-256, 4 = SHA-384)
        $validDigestTypes = [1, 2, 4];
        if (!in_array((int)$digestType, $validDigestTypes)) {
            return false;
        }

        // Validate digest length based on type
        $digestLength = strlen($digest);
        switch ((int)$digestType) {
            case 1: // SHA-1
                if ($digestLength !== 40) {
                    return false;
                }
                break;
            case 2: // SHA-256
                if ($digestLength !== 64) {
                    return false;
                }
                break;
            case 4: // SHA-384
                if ($digestLength !== 96) {
                    return false;
                }
                break;
        }

        return true;
    }
}
