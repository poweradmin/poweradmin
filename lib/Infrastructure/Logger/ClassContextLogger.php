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
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Stringable;

/**
 * PSR-3 decorator that tags every log call with the owning class's short name in the `classname` context key.
 */
final class ClassContextLogger extends AbstractLogger
{
    private function __construct(private readonly LoggerInterface $inner, private readonly string $classname)
    {
    }

    /**
     * Wrap $inner so its lines carry the short name of $class (a FQCN or `self::class`).
     * A NullLogger is returned as is, and an already wrapped logger is re-tagged rather
     * than stacked, since the outer tag is the one that reaches the line anyway.
     */
    public static function for(LoggerInterface $inner, string $class): LoggerInterface
    {
        if ($inner instanceof NullLogger) {
            return $inner;
        }
        if ($inner instanceof self) {
            $inner = $inner->inner;
        }

        return new self($inner, basename(str_replace('\\', '/', $class)));
    }

    public function log($level, Stringable|string $message, array $context = []): void
    {
        $this->inner->log($level, $message, $context + ['classname' => $this->classname]);
    }
}
