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
    private ConfigurationManager $config;
    private MessageService $messageService;

    public function __construct(PDOLayer $db, ConfigurationManager $config)
    {
        $this->db = $db;
        $this->config = $config;
        $this->messageService = new MessageService();
    }

    /** Check if Priority is valid
     *
     * Check if MX or SRV priority is within range
     *
     * @param mixed $prio Priority
     * @param string $type Record type
     *
     * @return int|bool Valid priority value or false if invalid
     */
    public function isValidPriority(mixed $prio, string $type): int|bool
    {
        // For backward compatibility, use the same logic
        if (!isset($prio) || $prio === "") {
            if ($type == "MX" || $type == "SRV") {
                return 10;
            }
            return 0;
        }

        if (($type == "MX" || $type == "SRV") && (is_numeric($prio) && $prio >= 0 && $prio <= 65535)) {
            return (int)$prio;
        } elseif (is_numeric($prio) && $prio == 0) {
            return 0;
        }
        $this->messageService->addSystemError(_('Invalid value for prio field.'));
        return false;
    }

    /** Check if target is not a CNAME
     *
     * @param string $target target to check
     *
     * @return boolean true if not alias, false if CNAME exists
     */
    public function isValidNonAliasTarget(string $target): bool
    {
        $pdns_db_name = $this->config->get('database', 'pdns_name');
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

        $query = "SELECT id FROM $records_table
			WHERE name = " . $this->db->quote($target, 'text') . "
			AND TYPE = " . $this->db->quote('CNAME', 'text');

        $response = $this->db->queryOne($query);
        if ($response) {
            $this->messageService->addSystemError(_('You can not point a NS or MX record to a CNAME record. Remove or rename the CNAME record first, or take another name.'));
            return false;
        }
        return true;
    }
}
