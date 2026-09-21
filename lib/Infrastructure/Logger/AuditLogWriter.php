<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2026 Poweradmin Development Team
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

use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Port\DnsBackendProviderInterface;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * Sink for audit lines: hands each one to the syslog logger and, when database
 * logging is on, to the log_users, log_zones, log_groups or log_api table.
 */
class AuditLogWriter
{
    private PDO $db;
    private ConfigurationInterface $config;
    private ?DnsBackendProviderInterface $backendProvider;
    private LoggerInterface $syslog;

    public function __construct(PDO $db, ConfigurationInterface $config, ?DnsBackendProviderInterface $backendProvider = null)
    {
        $this->db = $db;
        $this->config = $config;
        $this->backendProvider = $backendProvider;
        $this->syslog = SyslogLogger::fromConfig($this->config);
    }

    /**
     * Writes one audit line to syslog and, through $dbWrite, to the matching log
     * table when logging.database_enabled is on.
     */
    private function write(string $message, int $priority, callable $dbWrite): void
    {
        $this->syslog->log(SyslogLogger::levelFor($priority), $message);

        if ($this->config->get('logging', 'database_enabled')) {
            $dbWrite();
        }
    }

    private function doLog(string $message, int $priority, ?int $zone_id = null): void
    {
        $this->write($message, $priority, fn() => $zone_id !== null
            ? (new DbZoneLogger($this->db, $this->config, $this->backendProvider))->doLog($message, $zone_id, $priority)
            : (new DbUserLogger($this->db))->doLog($message, $priority));
    }

    public function logError(string $message, ?int $zone_id = null): void
    {
        $this->doLog($message, LOG_ERR, $zone_id);
    }

    public function logWarn(string $message, ?int $zone_id = null): void
    {
        $this->doLog($message, LOG_WARNING, $zone_id);
    }

    public function logNotice(string $message): void
    {
        $this->doLog($message, LOG_NOTICE);
    }

    public function logInfo(string $message, ?int $zone_id = null): void
    {
        $this->doLog($message, LOG_INFO, $zone_id);
    }

    public function logGroupInfo(string $message, ?int $group_id): void
    {
        $this->doLogWithGroup($message, LOG_INFO, $group_id);
    }

    public function logGroupWarning(string $message, ?int $group_id): void
    {
        $this->doLogWithGroup($message, LOG_WARNING, $group_id);
    }

    public function logApiInfo(string $message): void
    {
        $this->doLogWithApi($message, LOG_INFO);
    }

    private function doLogWithApi(string $message, int $priority): void
    {
        $this->write($message, $priority, fn() => (new DbApiLogger($this->db))->doLog($message, $priority));
    }

    private function doLogWithGroup(string $message, int $priority, ?int $group_id): void
    {
        $this->write($message, $priority, fn() => (new DbGroupLogger($this->db))->doLog($message, $group_id, $priority));
    }
}
