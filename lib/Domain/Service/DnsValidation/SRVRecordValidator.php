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
    private MessageService $messageService;
    private HostnameValidator $hostnameValidator;
    private TTLValidator $ttlValidator;

    public function __construct(ConfigurationManager $config)
    {
        $this->config = $config;
        $this->messageService = new MessageService();
        $this->hostnameValidator = new HostnameValidator($config);
        $this->ttlValidator = new TTLValidator();
    }

    /**
     * Validates SRV record content
     *
     * @param string $content The content of the SRV record
     * @param string $name The name of the record
     * @param mixed $prio The priority (used for SRV records)
     * @param int|string $ttl The TTL value
     * @param int $defaultTTL The default TTL to use if not specified
     *
     * @return array|bool Array with validated data or false if validation fails
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, $defaultTTL): array|bool
    {
        // Validate SRV name
        $nameResult = $this->isValidSrvName($name);
        if ($nameResult === false) {
            return false;
        }
        $name = $nameResult['name'];

        // Validate SRV content
        $contentResult = $this->isValidSrvContent($content, $name);
        if ($contentResult === false) {
            return false;
        }
        $content = $contentResult['content'];

        // Validate priority (SRV records use priority)
        $validatedPrio = $this->isValidPriority($prio);
        if ($validatedPrio === false) {
            $this->messageService->addSystemError(_('Invalid value for the priority field of the SRV record.'));
            return false;
        }

        // Validate TTL
        $validatedTTL = $this->ttlValidator->isValidTTL($ttl, $defaultTTL);
        if ($validatedTTL === false) {
            return false;
        }

        return [
            'content' => $content,
            'name' => $name,
            'prio' => $validatedPrio,
            'ttl' => $validatedTTL
        ];
    }

    /**
     * Validate SRV record name format
     *
     * @param string $name SRV record name
     *
     * @return array|bool Returns array with formatted name if valid, false otherwise
     */
    private function isValidSrvName(string $name): array|bool
    {
        if (strlen($name) > 255) {
            $this->messageService->addSystemError(_('The hostname is too long.'));
            return false;
        }

        $fields = explode('.', $name, 3);

        // Check if we have all three parts required for an SRV record
        if (count($fields) < 3) {
            $this->messageService->addSystemError(_('SRV record name must be in format _service._protocol.domain'));
            return false;
        }

        if (!preg_match('/^_[\w\-]+$/i', $fields[0])) {
            $this->messageService->addSystemError(_('Invalid service value in name field of SRV record.'));
            return false;
        }
        if (!preg_match('/^_[\w]+$/i', $fields[1])) {
            $this->messageService->addSystemError(_('Invalid protocol value in name field of SRV record.'));
            return false;
        }
        if (!$this->hostnameValidator->isValidHostnameFqdn($fields[2], 0)) {
            $this->messageService->addSystemError(_('Invalid FQDN value in name field of SRV record.'));
            return false;
        }

        return ['name' => join('.', $fields)];
    }

    /**
     * Validate SRV record content format
     *
     * @param string $content SRV record content
     * @param string $name SRV record name
     *
     * @return array|bool Returns array with formatted content if valid, false otherwise
     */
    private function isValidSrvContent(string $content, string $name): array|bool
    {
        $fields = preg_split("/\s+/", trim($content));

        // Check if we have exactly 4 fields for an SRV record content
        // Format should be: <priority> <weight> <port> <target>
        if (count($fields) != 4) {
            $this->messageService->addSystemError(_('SRV record content must have priority, weight, port and target'));
            return false;
        }

        if (!is_numeric($fields[0]) || $fields[0] < 0 || $fields[0] > 65535) {
            $this->messageService->addSystemError(_('Invalid value for the priority field of the SRV record.'));
            return false;
        }
        if (!is_numeric($fields[1]) || $fields[1] < 0 || $fields[1] > 65535) {
            $this->messageService->addSystemError(_('Invalid value for the weight field of the SRV record.'));
            return false;
        }
        if (!is_numeric($fields[2]) || $fields[2] < 0 || $fields[2] > 65535) {
            $this->messageService->addSystemError(_('Invalid value for the port field of the SRV record.'));
            return false;
        }
        if ($fields[3] == "" || ($fields[3] != "." && !$this->hostnameValidator->isValidHostnameFqdn($fields[3], 0))) {
            $this->messageService->addSystemError(_('Invalid SRV target.'));
            return false;
        }

        return ['content' => join(' ', $fields)];
    }

    /**
     * Validate the priority field for SRV records
     *
     * @param mixed $prio The priority value to validate
     * @return int|bool The validated priority value or false if invalid
     */
    private function isValidPriority(mixed $prio): int|bool
    {
        // If priority is not provided or empty, use default of 10
        if (!isset($prio) || $prio === "") {
            return 10;
        }

        // Priority must be a number between 0 and 65535
        if (is_numeric($prio) && $prio >= 0 && $prio <= 65535) {
            return (int)$prio;
        }

        return false;
    }
}
