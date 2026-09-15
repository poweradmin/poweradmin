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

use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Stringable;

/**
 * PSR-3 logger that writes every message to syslog under the configured identity and facility.
 */
class SyslogLogger extends AbstractLogger
{
    private const PRIORITIES = [
        LogLevel::EMERGENCY => LOG_EMERG,
        LogLevel::ALERT => LOG_ALERT,
        LogLevel::CRITICAL => LOG_CRIT,
        LogLevel::ERROR => LOG_ERR,
        LogLevel::WARNING => LOG_WARNING,
        LogLevel::NOTICE => LOG_NOTICE,
        LogLevel::INFO => LOG_INFO,
        LogLevel::DEBUG => LOG_DEBUG,
    ];

    private bool $opened = false;

    public function __construct(private readonly string $ident = 'poweradmin', private readonly int $facility = LOG_USER)
    {
    }

    /**
     * The PSR-3 level for a LOG_* priority, for sinks that still speak syslog priorities.
     */
    public static function levelFor(int $priority): string
    {
        return array_search($priority, self::PRIORITIES, true) ?: LogLevel::INFO;
    }

    /**
     * The syslog sink the logging.* settings ask for: a NullLogger when
     * syslog_enabled is off, otherwise one opened with the configured identity and facility.
     */
    public static function fromConfig(ConfigurationInterface $config): LoggerInterface
    {
        if (!$config->get('logging', 'syslog_enabled')) {
            return new NullLogger();
        }

        return new self(
            $config->get('logging', 'syslog_identity') ?: 'poweradmin',
            (int)($config->get('logging', 'syslog_facility') ?: LOG_USER)
        );
    }

    public function log($level, Stringable|string $message, array $context = []): void
    {
        // Opened on first use so a writer that never logs costs no syscall.
        if (!$this->opened) {
            openlog($this->ident, LOG_PERROR, $this->facility);
            $this->opened = true;
        }
        syslog(self::PRIORITIES[$level] ?? LOG_INFO, Logger::interpolatePlaceholders((string)$message, $context));
    }

    public function __destruct()
    {
        if ($this->opened) {
            closelog();
        }
    }
}
