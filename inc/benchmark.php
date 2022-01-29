<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2009  Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2022  Poweradmin Development Team
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
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * Benchmarking functions
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2022 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

global $display_stats;

if ($display_stats) {
    $start_memory = memory_get_usage();
    $start_time = microtime(true);
}

/** Get Human Readable Size
 *
 * Convert size to human readable units
 *
 * @param int $size Size to convert
 *
 * @return string $result Human readable size
 */
function get_human_readable_usage($size) {
    $units = array('B', 'KB', 'MB', 'GB');
    $result = $size . ' B';

    if ($size < 1024)
        return $result;

    $index = (int)floor(log($size, 1024));
    if ($index < sizeof($units)) {
        $result = round($size / pow(1024, ($index)), 2) . ' ' . $units[$index];
    }

    return $result;
}

/** Print Current Memory and Runtime Stats
 */
function display_current_stats() {
    global $start_time, $start_memory;
    $memory_usage = get_human_readable_usage(memory_get_usage() - $start_memory);
    $elapsed_time = sprintf("%.5f", microtime(true) - $start_time);
    echo "<div class=\"debug\">Memory usage: {$memory_usage}, elapsed time: {$elapsed_time}</div>";
}
