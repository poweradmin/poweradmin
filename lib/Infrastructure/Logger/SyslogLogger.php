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

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
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

    public function __construct(string $ident = 'poweradmin', int $facility = LOG_USER)
    {
        openlog($ident, LOG_PERROR, $facility);
    }

    public function log($level, Stringable|string $message, array $context = []): void
    {
        syslog(self::PRIORITIES[$level] ?? LOG_INFO, Logger::interpolatePlaceholders((string)$message, $context));
    }

    public function __destruct()
    {
        closelog();
    }
}
