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
 * MINFO Record Validator
 */
class MINFORecordValidator implements DnsRecordValidatorInterface
{
    private ConfigurationManager $config;
    private MessageService $messageService;
    private TTLValidator $ttlValidator;
    private HostnameValidator $hostnameValidator;

    public function __construct(ConfigurationManager $config)
    {
        $this->config = $config;
        $this->messageService = new MessageService();
        $this->ttlValidator = new TTLValidator();
        $this->hostnameValidator = new HostnameValidator($config);
    }

    /**
     * Validate MINFO record
     * MINFO records contain responsible mailbox and error mailbox information
     *
     * @param string $content Content in format "rmailbx emailbx"
     * @param string $name Domain name for the MINFO record
     * @param mixed $prio Priority value (not used for MINFO)
     * @param int|string $ttl TTL value
     * @param int $defaultTTL Default TTL to use if not specified
     *
     * @return array|bool Array with validated data or false if validation fails
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, $defaultTTL): array|bool
    {
        // Validate name (domain name)
        $nameResult = $this->hostnameValidator->isValidHostnameFqdn($name, 1);
        if ($nameResult === false) {
            return false;
        }
        $name = $nameResult['hostname'];

        // Validate content (responsible mailbox and error mailbox)
        if (empty($content)) {
            $this->messageService->addSystemError(_('MINFO record content cannot be empty.'));
            return false;
        }

        $parts = explode(' ', $content, 2);
        if (count($parts) !== 2) {
            $this->messageService->addSystemError(_('MINFO record must contain both responsible mailbox and error mailbox separated by a space.'));
            return false;
        }

        $rmailbx = $parts[0];
        $emailbx = $parts[1];

        // Validate responsible mailbox
        $rmailbxResult = $this->hostnameValidator->isValidHostnameFqdn($rmailbx, 0);
        if ($rmailbxResult === false) {
            $this->messageService->addSystemError(_('Invalid responsible mailbox hostname.'));
            return false;
        }
        $rmailbx = $rmailbxResult['hostname'];

        // Validate error mailbox
        $emailbxResult = $this->hostnameValidator->isValidHostnameFqdn($emailbx, 0);
        if ($emailbxResult === false) {
            $this->messageService->addSystemError(_('Invalid error mailbox hostname.'));
            return false;
        }
        $emailbx = $emailbxResult['hostname'];

        // Validate TTL
        $validatedTtl = $this->ttlValidator->isValidTTL($ttl, $defaultTTL);
        if ($validatedTtl === false) {
            return false;
        }

        // Reconstruct the content with validated parts
        $validatedContent = $rmailbx . ' ' . $emailbx;

        return [
            'content' => $validatedContent,
            'name' => $name,
            'prio' => 0, // MINFO records don't use priority
            'ttl' => $validatedTtl
        ];
    }
}
