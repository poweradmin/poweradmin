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

namespace Poweradmin\Application\Service\Web;

/**
 * Renders the memory usage and elapsed time footer shown when misc.display_stats is on.
 */
final class StatsDisplayService
{
    private const UNITS = ['B', 'KB', 'MB', 'GB'];

    private int $startMemory;
    private float $startTime;

    public function __construct()
    {
        $this->startMemory = memory_get_usage();
        $this->startTime = microtime(true);
    }

    public function displayStats(): string
    {
        $memoryUsage = self::humanReadable(memory_get_usage() - $this->startMemory);
        $elapsedTime = sprintf("%.5f", microtime(true) - $this->startTime);

        return "<div class=\"container\"><samp>Memory usage: $memoryUsage, elapsed time: $elapsedTime</samp></div>";
    }

    private static function humanReadable(int $size): string
    {
        if ($size < 1024) {
            return $size . ' B';
        }

        $index = (int)floor(log($size, 1024));
        return round($size / pow(1024, $index), 2) . ' ' . self::UNITS[$index];
    }
}
