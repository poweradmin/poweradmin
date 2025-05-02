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
use Poweradmin\Infrastructure\Database\PDOLayer;
use Poweradmin\Infrastructure\Service\MessageService;
use Poweradmin\Domain\Service\Validator;

/**
 * SOA record validator
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class SOARecordValidator implements DnsRecordValidatorInterface
{
    private ConfigurationManager $config;
    private MessageService $messageService;
    private HostnameValidator $hostnameValidator;
    private TTLValidator $ttlValidator;
    private PDOLayer $db;

    public function __construct(ConfigurationManager $config, PDOLayer $db)
    {
        $this->config = $config;
        $this->db = $db;
        $this->messageService = new MessageService();
        $this->hostnameValidator = new HostnameValidator($config);
        $this->ttlValidator = new TTLValidator();
    }

    private string $dns_hostmaster;
    private string $zone;

    /**
     * Set SOA-specific validation parameters
     *
     * @param string $dns_hostmaster Hostmaster email address
     * @param string $zone Zone name
     */
    public function setSOAParams(string $dns_hostmaster, string $zone): void
    {
        $this->dns_hostmaster = $dns_hostmaster;
        $this->zone = $zone;
    }

    /**
     * Validates SOA record
     *
     * @param string $content SOA record content
     * @param string $name SOA name
     * @param mixed $prio Priority (not used for SOA records)
     * @param int|string $ttl TTL value
     * @param int $defaultTTL Default TTL value
     *
     * @return array|bool Array with validated data or false if validation fails
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, $defaultTTL): array|bool
    {
        // Check if SOA params have been set
        if (!isset($this->dns_hostmaster) || !isset($this->zone)) {
            $this->messageService->addSystemError(_('SOA validation parameters not set. Call setSOAParams() first.'));
            return false;
        }

        // Validate zone name
        if (!$this->isValidSoaName($name, $this->zone)) {
            return false;
        }

        // Validate hostname
        $hostnameResult = $this->hostnameValidator->isValidHostnameFqdn($name, 1);
        if ($hostnameResult === false) {
            return false;
        }
        $name = $hostnameResult['hostname'];

        // Validate SOA content
        $soaResult = $this->isValidSoaContent($content, $this->dns_hostmaster);
        if ($soaResult === false) {
            $this->messageService->addSystemError(_('Your content field doesnt have a legit value.'));
            return false;
        }
        $content = $soaResult['content'];

        // Validate TTL
        $validatedTTL = $this->ttlValidator->isValidTTL($ttl, $defaultTTL);
        if ($validatedTTL === false) {
            return false;
        }

        return [
            'content' => $content,
            'name' => $name,
            'prio' => 0,
            'ttl' => $validatedTTL
        ];
    }

    /**
     * Check if SOA name is valid
     *
     * Checks if SOA name = zone name
     *
     * @param string $name SOA name
     * @param string $zone Zone name
     *
     * @return boolean true if valid, false otherwise
     */
    private function isValidSoaName(string $name, string $zone): bool
    {
        if ($name != $zone) {
            $this->messageService->addSystemError(_('Invalid value for name field of SOA record. It should be the name of the zone.'));
            return false;
        }
        return true;
    }

    /**
     * Check if SOA content is valid
     *
     * @param string $content SOA record content
     * @param string $dns_hostmaster Hostmaster email address
     *
     * @return array|bool Returns array with formatted content if valid, false otherwise
     */
    private function isValidSoaContent(string $content, string $dns_hostmaster): array|bool
    {
        $fields = preg_split("/\s+/", trim($content));
        $field_count = count($fields);

        if ($field_count == 0 || $field_count > 7) {
            return false;
        } else {
            if (!$this->hostnameValidator->isValidHostnameFqdn($fields[0], 0) || preg_match('/\.arpa\.?$/', $fields[0])) {
                return false;
            }
            $final_soa = $fields[0];

            $addr_input = $fields[1] ?? $dns_hostmaster;
            if (!str_contains($addr_input, "@")) {
                $addr_input = preg_split('/(?<!\\\)\./', $addr_input, 2);
                if (count($addr_input) == 2) {
                    $addr_to_check = str_replace("\\", "", $addr_input[0]) . "@" . $addr_input[1];
                } else {
                    $addr_to_check = str_replace("\\", "", $addr_input[0]);
                }
            } else {
                $addr_to_check = $addr_input;
            }

            $validation = new Validator($this->db, $this->config);
            if (!$validation->is_valid_email($addr_to_check)) {
                return false;
            } else {
                $addr_final = explode('@', $addr_to_check, 2);
                $final_soa .= " " . str_replace(".", "\\.", $addr_final[0]) . "." . $addr_final[1];
            }

            if (isset($fields[2])) {
                if (!is_numeric($fields[2])) {
                    return false;
                }
                $final_soa .= " " . $fields[2];
            } else {
                $final_soa .= " 0";
            }

            if ($field_count != 7) {
                return false;
            } else {
                for ($i = 3; ($i < 7); $i++) {
                    if (!is_numeric($fields[$i])) {
                        return false;
                    } else {
                        $final_soa .= " " . $fields[$i];
                    }
                }
            }
        }
        return ['content' => $final_soa];
    }
}
