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

use Poweradmin\Infrastructure\Database\PDOLayer;
use Poweradmin\AppConfiguration;

class LegacyLogger
{
    private PDOLayer $db;
    private AppConfiguration $config;

    public function __construct($db) {
        $this->db = $db;
        $this->config = new AppConfiguration();
    }

    private function do_log($message, $priority, $zone_id = NULL): void
    {
        $syslog_use = $this->config->get('syslog_use');
        $syslog_ident = $this->config->get('syslog_ident');
        $syslog_facility = $this->config->get('syslog_facility');
        $dblog_use = $this->config->get('dblog_use');

        if ($syslog_use) {
            openlog($syslog_ident, LOG_PERROR, $syslog_facility);
            syslog($priority, $message);
            closelog();
        }

        if ($dblog_use) {
            // TODO: This distinction would be better handled with special type enum
            if ($zone_id) {
                $dbZoneLogger = new DbZoneLogger($this->db);
                $dbZoneLogger->do_log($message, $zone_id, $priority);
            } else {
                $dbUserLogger = new DbUserLogger($this->db);
                $dbUserLogger->do_log($message, $priority);
            }
        }
    }

    public function log_error($message, $zone_id = NULL): void
    {
        $this->do_log($message, LOG_ERR, $zone_id);
    }

    public function log_warn($message, $zone_id = NULL): void
    {
        $this->do_log($message, LOG_WARNING, $zone_id);
    }

    public function log_notice($message): void
    {
        $this->do_log($message, LOG_NOTICE);
    }

    public function log_info($message, $zone_id = NULL): void
    {
        $this->do_log($message, LOG_INFO, $zone_id);
    }
}