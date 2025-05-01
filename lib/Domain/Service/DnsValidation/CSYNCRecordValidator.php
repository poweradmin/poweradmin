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

use Poweradmin\Domain\Model\RecordType;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Service\MessageService;

/**
 * Validator for CSYNC DNS records
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class CSYNCRecordValidator implements DnsRecordValidatorInterface
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
     * Validate CSYNC record
     *
     * @param string $content CSYNC record content in format: "SOA_SERIAL FLAGS TYPE1 [TYPE2...]"
     * @param string $name Hostname
     * @param mixed $prio Priority (not used for CSYNC records)
     * @param int|string $ttl TTL value
     * @param int $defaultTTL Default TTL value
     *
     * @return array|bool Array with validated data or false if validation fails
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, $defaultTTL): array|bool
    {
        // Validate hostname
        $hostnameResult = $this->hostnameValidator->isValidHostnameFqdn($name, 1);
        if ($hostnameResult === false) {
            return false;
        }
        $name = $hostnameResult['hostname'];

        // Validate CSYNC content format
        if (!$this->isValidCSYNCContent($content)) {
            return false;
        }

        // Validate TTL
        $validatedTtl = $this->ttlValidator->isValidTTL($ttl, $defaultTTL);
        if ($validatedTtl === false) {
            return false;
        }

        // Validate priority (should be 0 for CSYNC records)
        $validatedPrio = $this->validatePriority($prio);
        if ($validatedPrio === false) {
            $this->messageService->addSystemError(_('Invalid value for prio field.'));
            return false;
        }

        return [
            'content' => $content,
            'name' => $name,
            'prio' => $validatedPrio,
            'ttl' => $validatedTtl
        ];
    }

    /**
     * Validate priority for CSYNC records
     * CSYNC records don't use priority, so it should be 0
     *
     * @param mixed $prio Priority value
     *
     * @return int|bool 0 if valid, false otherwise
     */
    private function validatePriority(mixed $prio): int|bool
    {
        // If priority is not provided or empty, set it to 0
        if (!isset($prio) || $prio === "") {
            return 0;
        }

        // If provided, ensure it's 0 for CSYNC records
        if (is_numeric($prio) && intval($prio) === 0) {
            return 0;
        }

        return false;
    }

    /**
     * Check if CSYNC content is valid
     *
     * @param string $content CSYNC record content
     *
     * @return boolean true if valid, false otherwise
     */
    public function isValidCSYNCContent(string $content): bool
    {
        $fields = preg_split("/\s+/", trim($content));

        // Validate SOA Serial (first field)
        if (!isset($fields[0]) || !is_numeric($fields[0]) || $fields[0] < 0 || $fields[0] > 4294967295) {
            $this->messageService->addSystemError(_('Invalid SOA Serial in CSYNC record.'));
            return false;
        }

        // Validate Flags (second field)
        if (!isset($fields[1]) || !is_numeric($fields[1]) || $fields[1] < 0 || $fields[1] > 3) {
            $this->messageService->addSystemError(_('Invalid Flags in CSYNC record.'));
            return false;
        }

        // Validate Type Bit Map (remaining fields)
        if (count($fields) <= 2) {
            // At least one type must be specified
            $this->messageService->addSystemError(_('CSYNC record must specify at least one record type.'));
            return false;
        }

        // Valid record types that can be synchronized
        // RFC 7477 mentions A, AAAA, and NS as the most common
        // But other record types can be synchronized as well
        $validTypes = [
            RecordType::A, RecordType::AAAA, RecordType::CNAME, RecordType::DNAME, RecordType::MX, RecordType::NS,
            RecordType::PTR, RecordType::SRV, RecordType::TXT
        ];

        for ($i = 2; $i < count($fields); $i++) {
            if (!in_array(strtoupper($fields[$i]), $validTypes)) {
                $this->messageService->addSystemError(_('Invalid Type in CSYNC record Type Bit Map.'));
                return false;
            }
        }

        return true;
    }
}
