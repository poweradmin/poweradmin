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
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;
use Poweradmin\Infrastructure\Database\PDOLayer;
use Poweradmin\Infrastructure\Service\MessageService;

/**
 * Common DNS validation functions shared across record types
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class DnsCommonValidator
{
    private PDOLayer $db;
    private ConfigurationInterface $config;
    private MessageService $messageService;

    public function __construct(PDOLayer $db, ConfigurationInterface $config)
    {
        $this->db = $db;
        $this->config = $config;
        $this->messageService = new MessageService();
    }

    /**
     * Validate priority for DNS records
     *
     * Check if MX or SRV priority is within range
     *
     * @param mixed $prio Priority
     * @param string $type Record type
     *
     * @return ValidationResult ValidationResult with validated priority or error message
     */
    public function validatePriority(mixed $prio, string $type): ValidationResult
    {
        // For records that require priority: MX or SRV
        if ($type == "MX" || $type == "SRV") {
            // If not set or empty string, use default value of 10
            if (!isset($prio) || $prio === "") {
                return ValidationResult::success(10);
            }

            // For MX/SRV, priority must be 0-65535
            if (is_numeric($prio) && $prio >= 0 && $prio <= 65535) {
                return ValidationResult::success((int)$prio);
            } else {
                return ValidationResult::failure(_('Priority for MX/SRV records must be a number between 0 and 65535.'));
            }
        }

        // All other record types don't use priority, so return 0
        // We accept any input (including empty string) and convert to 0
        return ValidationResult::success(0);
    }

    /**
     * Check if target is not a CNAME
     *
     * @param string $target target to check
     *
     * @return ValidationResult ValidationResult indicating if target is valid
     */
    public function validateNonAliasTarget(string $target): ValidationResult
    {
        $pdns_db_name = $this->config->get('database', 'pdns_name');
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

        $query = "SELECT id FROM $records_table
				WHERE name = " . $this->db->quote($target, 'text') . "
				AND TYPE = " . $this->db->quote('CNAME', 'text');

        $response = $this->db->queryOne($query);
        if ($response) {
            return ValidationResult::failure(_('You can not point a NS or MX record to a CNAME record. Remove or rename the CNAME record first, or take another name.'));
        }
        return ValidationResult::success(true);
    }

    /**
     * Check if the priority value is valid
     *
     * @param mixed $prio The priority value to check
     * @return bool True if the priority is valid, false otherwise
     */
    public function isValidPriority(mixed $prio): bool
    {
        // Simple version that checks if priority is a valid number within range
        return is_numeric($prio) && $prio >= 0 && $prio <= 65535;
    }
}
