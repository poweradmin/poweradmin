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

namespace Poweradmin\Application\Service;

use Poweradmin\Infrastructure\Logger\Logger;

abstract class LoggingService
{
    protected Logger $logger;
    private string $className;

    public function __construct(Logger $logger, string $className = '')
    {
        $this->logger = $logger;
        $this->className = $className;
    }

    protected function logDebug(string $message, array $context = []): void
    {
        if (!empty($this->className)) {
            $context['classname'] = $this->className;
        }
        $this->logger->debug($message, $context);
    }

    protected function logInfo(string $message, array $context = []): void
    {
        if (!empty($this->className)) {
            $context['classname'] = $this->className;
        }
        $this->logger->info($message, $context);
    }

    protected function logWarning(string $message, array $context = []): void
    {
        if (!empty($this->className)) {
            $context['classname'] = $this->className;
        }
        $this->logger->warning($message, $context);
    }

    protected function logError(string $message, array $context = []): void
    {
        if (!empty($this->className)) {
            $context['classname'] = $this->className;
        }
        $this->logger->error($message, $context);
    }
}
