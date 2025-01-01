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

class EchoLegacyLogger implements LegacyLoggerInterface
{
    public function info(string $message): void
    {
        $this->output('INFO', $message);
    }

    public function warn(string $message): void
    {
        $this->output('WARN', $message);
    }

    public function error(string $message): void
    {
        $this->output('ERROR', $message);
    }

    public function notice(string $message): void
    {
        $this->output('NOTICE', $message);
    }

    private function output(string $level, string $message): void
    {
        $date = date('Y-m-d H:i:s');
        echo "<div class=\"container\"><pre>[$date] [$level] $message</pre></div>";
    }
}
