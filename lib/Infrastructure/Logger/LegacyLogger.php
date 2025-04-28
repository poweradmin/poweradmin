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

namespace Poweradmin\Infrastructure\Logger;

use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOLayer;
use Poweradmin\Infrastructure\Logger\LogType;

class LegacyLogger
{
    private PDOLayer $db;
    private ConfigurationManager $config;

    public function __construct($db)
    {
        $this->db = $db;
        $this->config = ConfigurationManager::getInstance();
        $this->config->initialize();
    }

    private function do_log(string $message, int $priority, ?int $zone_id = null): void
    {
        $syslog_use = $this->config->get('logging', 'syslog_enabled');
        $syslog_ident = $this->config->get('logging', 'syslog_identity');
        $syslog_facility = $this->config->get('logging', 'syslog_facility');
        $dblog_use = $this->config->get('logging', 'database_enabled');

        if ($syslog_use) {
            openlog($syslog_ident, LOG_PERROR, $syslog_facility);
            syslog($priority, $message);
            closelog();
        }

        if ($dblog_use) {
            $logType = $zone_id !== null ? LogType::ZONE : LogType::USER;

            if ($logType === LogType::ZONE) {
                $dbZoneLogger = new DbZoneLogger($this->db);
                $dbZoneLogger->do_log($message, $zone_id, $priority);
            } else {
                $dbUserLogger = new DbUserLogger($this->db);
                $dbUserLogger->do_log($message, $priority);
            }
        }
    }

    public function log_error(string $message, ?int $zone_id = null): void
    {
        $this->do_log($message, LOG_ERR, $zone_id);
    }

    public function log_warn(string $message, ?int $zone_id = null): void
    {
        $this->do_log($message, LOG_WARNING, $zone_id);
    }

    public function log_notice(string $message): void
    {
        $this->do_log($message, LOG_NOTICE);
    }

    public function log_info(string $message, ?int $zone_id = null): void
    {
        $this->do_log($message, LOG_INFO, $zone_id);
    }
}
