<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2023 Poweradmin Development Team
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

use Poweradmin\Application\Service\UserService;
use Poweradmin\Infrastructure\Database\DbCompat;
use Poweradmin\Infrastructure\Repository\DbUserRepository;

/** Print paging menu
 *
 * @param int $amount Total number of items
 * @param int $rowamount Per page number of items
 * @param int $id Page specific ID (Zone ID, Template ID, etc.)
 *
 * @return string $result
 */
function show_pages($amount, $rowamount, $id = '') {
    if ($amount <= $rowamount || $rowamount == 0) {
        return "";
    }

    $num = 8;
    $result = '';
    $lastpage = ceil($amount / $rowamount);
    $startpage = 1;

    if (!isset($_GET["start"])) {
        $_GET["start"] = 1;
    }
    $start = $_GET["start"];

    if ($lastpage > $num & $start > ($num / 2)) {
        $startpage = ($start - ($num / 2));
    }

    $result .= "<nav><ul class=\"pagination\">";

    if ($lastpage > $num & $start > 1) {
        $result .= '<li class="page-item"><a class="page-link" href=" ';
        $result .= '?start=' . ($start - 1);
        if ($id != '') {
            $result .= '&id=' . $id;
        }
        $result .= '">';
        $result .= _('Previous');
        $result .= '</a></li>';
    }

    if ($start != 1) {
        $result .= '<li class="page-item"><a class="page-link" href=" ';
        $result .= '?start=1';
        if ($id != '') {
            $result .= '&id=' . $id;
        }
        $result .= '">';
        $result .= '1';
        $result .= '</a></li>';
        if ($startpage > 2) {
            $result .= '<li class="page-item disabled"><a class="page-link" href="#" tabindex="-1">..</a></li>';
        }
    }

    for ($i = $startpage; $i <= min(($startpage + $num), $lastpage); $i++) {
        if ($start == $i) {
            $result .= '<li class="page-item active"><span class="page-link">' . $i . '</span></li>';
        } elseif ($i != $lastpage & $i != 1) {
            $result .= '<li class="page-item"><a class="page-link" href="';
            $result .= '?start=' . $i;
            if ($id != '') {
                $result .= '&id=' . $id;
            }
            $result .= '">';
            $result .= $i;
            $result .= '</a></li>';
        }
    }

    if ($start != $lastpage) {
        if (min(($startpage + $num), $lastpage) < ($lastpage - 1)) {
            $result .= '<li class="page-item disabled"><a class="page-link" href="#" tabindex="-1">..</a></li>';
        }
        $result .= '<li class="page-item"><a class="page-link" href="';
        $result .= '?start=' . $lastpage;
        if ($id != '') {
            $result .= '&id=' . $id;
        }
        $result .= '">';
        $result .= $lastpage;
        $result .= '</a></li>';
    }

    if ($lastpage > $num & $start < $lastpage) {
        $result .= '<li class="page-item"><a class="page-link" href="';
        $result .= '?start=' . ($start + 1);
        if ($id != '') {
            $result .= '&id=' . $id;
        }
        $result .= '">';
        $result .= _('Next');
        $result .= '</a></li>';
    }

    $result .= "</ul></nav>";

    return $result;
}
