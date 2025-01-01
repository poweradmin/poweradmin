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

use DateTimeImmutable;
use Psr\Log\AbstractLogger;
use Stringable;

class Logger extends AbstractLogger
{
    private const ISO8601_DATETIME_FORMAT = 'c';

    private LogHandlerInterface $logHandler;
    private string $logLevel;

    private const LEVELS = [
        'emergency' => 0,
        'alert' => 1,
        'critical' => 2,
        'error' => 3,
        'warning' => 4,
        'notice' => 5,
        'info' => 6,
        'debug' => 7,
    ];

    public function __construct(LogHandlerInterface $handler, string $logLevel)
    {
        $this->logHandler = $handler;
        $this->logLevel = $logLevel;
    }

    public function log($level, Stringable|string $message, array $context = []): void
    {
        if (!$this->shouldLog($level)) {
            return;
        }

        $timestamp = (new DateTimeImmutable())->format(self::ISO8601_DATETIME_FORMAT);

        $classname = '';
        if (array_key_exists('classname', $context)) {
            $classname = "[{$context['classname']}]";
        }

        $this->logHandler->handle([
            'message' => self::interpolateMessage((string)$message, $context),
            'level' => strtoupper($level),
            'timestamp' => ($timestamp),
            'classname' => $classname,
        ]);
    }

    function interpolateMessage(string $message, array $context = []): string
    {
        $replace = array();
        foreach ($context as $key => $val) {
            if (!is_array($val) && (!is_object($val) || method_exists($val, '__toString'))) {
                $replace['{' . $key . '}'] = $val;
            }
        }

        return strtr($message, $replace);
    }

    private function shouldLog(string $level)
    {
        return self::LEVELS[$level] <= self::LEVELS[$this->logLevel];
    }
}