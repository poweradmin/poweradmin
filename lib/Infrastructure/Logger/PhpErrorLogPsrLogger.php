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
use Stringable;

/**
 * PSR-3 logger that writes every message to PHP's error_log().
 *
 * Used as a default when a class accepts an optional LoggerInterface and the
 * caller does not inject one. This preserves the legacy error_log() behavior
 * while letting tests substitute a mock to capture or silence messages.
 */
class PhpErrorLogPsrLogger extends AbstractLogger
{
    public function log($level, Stringable|string $message, array $context = []): void
    {
        error_log(Logger::interpolatePlaceholders((string) $message, $context));
    }
}
