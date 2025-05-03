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
     * Validates CAA record content
     *
     * @param string $content The content of the CAA record (flags tag value)
     * @param string $name The name of the record
     * @param mixed $prio The priority (unused for CAA records)
     * @param int|string $ttl The TTL value
     * @param int $defaultTTL The default TTL to use if not specified
     *
     * @return array|bool Array with validated data or false if validation fails
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, $defaultTTL): array|bool
    {
        // Validate hostname/name
        $hostnameResult = $this->hostnameValidator->isValidHostnameFqdn($name, 1);
        if ($hostnameResult === false) {
            return false;
        }
        $name = $hostnameResult['hostname'];

        // Validate content
        if (!$this->isValidCAAContent($content)) {
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
            'prio' => 0, // CAA records don't use priority
            'ttl' => $validatedTTL
        ];
    }

    /**
     * Validates the content of a CAA record
     * Format: <flags> <tag> <value>
     *
     * @param string $content The content to validate
     * @return bool True if valid, false otherwise
     */
    private function isValidCAAContent(string $content): bool
    {
        // Split the content into flags, tag, and value
        $parts = preg_split('/\s+/', trim($content), 3);
        if (count($parts) !== 3) {
            $this->messageService->addSystemError(_('CAA record must contain flags, tag, and value separated by spaces.'));
            return false;
        }

        [$flags, $tag, $value] = $parts;

        // Validate flags (must be 0-255)
        if (!is_numeric($flags) || (int)$flags < 0 || (int)$flags > 255) {
            $this->messageService->addSystemError(_('CAA flags must be a number between 0 and 255.'));
            return false;
        }

        // Validate tag (must be one of: issue, issuewild, iodef)
        $validTags = ['issue', 'issuewild', 'iodef'];
        if (!in_array($tag, $validTags)) {
            $this->messageService->addSystemError(_('CAA tag must be one of: issue, issuewild, iodef.'));
            return false;
        }

        // Validate value (should be properly quoted for issue/issuewild with CA domain or URL for iodef)
        if ($tag === 'issue' || $tag === 'issuewild') {
            // Value for issue/issuewild should be a properly quoted domain
            if (!StringValidator::hasQuotesAround($value)) {
                $this->messageService->addSystemError(_('CAA value must be enclosed in quotes.'));
                return false;
            }
            if (!StringValidator::isProperlyQuoted($value)) {
                $this->messageService->addSystemError(_('CAA value must be properly quoted.'));
                return false;
            }
        } elseif ($tag === 'iodef') {
            // Value for iodef should be a properly quoted URL
            if (!StringValidator::hasQuotesAround($value)) {
                $this->messageService->addSystemError(_('CAA iodef value must be enclosed in quotes.'));
                return false;
            }
            if (!StringValidator::isProperlyQuoted($value)) {
                $this->messageService->addSystemError(_('CAA iodef value must be properly quoted.'));
                return false;
            }

            // If it's a URL, it should start with http://, https://, or mailto:
            $unquoted = trim($value, '"');
            if (!preg_match('/^(https?:|mailto:)/', $unquoted)) {
                $this->messageService->addSystemError(_('CAA iodef value must be a URL starting with http://, https://, or mailto:.'));
                return false;
            }
        }

        return true;
    }
}
