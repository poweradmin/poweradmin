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

    private const DEFAULT_LEVEL = 'info';

    /**
     * Level names accepted by the logging.level setting, most severe first.
     *
     * @return string[]
     */
    public static function levelNames(): array
    {
        return array_keys(self::LEVELS);
    }

    public function __construct(LogHandlerInterface $handler, string $logLevel)
    {
        $this->logHandler = $handler;
        $this->logLevel = $logLevel;

        // Falling back silently would hide the typo; shouldLog() resolves the
        // threshold to the default, so this record is emitted rather than gated out.
        if (!isset(self::LEVELS[$logLevel])) {
            $this->warning(
                'Unrecognised logging.level {level}; falling back to {fallback}. Valid levels: {valid}',
                [
                    'level' => $logLevel,
                    'fallback' => self::DEFAULT_LEVEL,
                    'valid' => implode(', ', self::levelNames()),
                ]
            );
        }
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

        // Format context data for logging
        $contextString = '';
        if (!empty($context)) {
            // Remove classname from context as it's handled separately
            $loggableContext = $context;
            unset($loggableContext['classname']);

            if (!empty($loggableContext)) {
                $contextString = ' [' . json_encode($loggableContext, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ']';
            }
        }

        $this->logHandler->handle([
            'message' => self::interpolateMessage((string)$message, $context) . $contextString,
            'level' => strtoupper($level),
            'timestamp' => ($timestamp),
            'classname' => $classname,
        ]);
    }

    private function interpolateMessage(string $message, array $context = []): string
    {
        return self::interpolatePlaceholders($message, $context);
    }

    /**
     * Substitute PSR-3 `{key}` placeholders in a log message with values from the context array.
     *
     * Shared by Logger and the lightweight PhpErrorLogPsrLogger fallback so the
     * substitution rules stay in one place.
     */
    public static function interpolatePlaceholders(string $message, array $context): string
    {
        if ($context === []) {
            return $message;
        }

        $replace = [];
        foreach ($context as $key => $val) {
            if (!is_array($val) && (!is_object($val) || method_exists($val, '__toString'))) {
                $replace['{' . $key . '}'] = (string) $val;
            }
        }

        return strtr($message, $replace);
    }

    private function shouldLog(string $level): bool
    {
        // An unrecognised configured level must not silence logging; a missing key
        // would coerce to 0 and suppress everything below emergency.
        $threshold = self::LEVELS[$this->logLevel] ?? self::LEVELS[self::DEFAULT_LEVEL];
        $severity = self::LEVELS[$level] ?? self::LEVELS['debug'];

        return $severity <= $threshold;
    }
}
