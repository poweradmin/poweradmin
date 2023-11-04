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

/** Print paging menu
 *
 * @param int $amount Total number of items
 * @param int $rowAmount Per page number of items
 * @param string $id Page specific ID (Zone ID, Template ID, etc.)
 *
 * @return string $result
 */
function show_pages(int $amount, int $rowAmount, string $id = ''): string
{
    if ($amount <= $rowAmount || $rowAmount == 0) {
        return "";
    }

    $num = 8;
    $result = '';
    $lastPage = ceil($amount / $rowAmount);
    $startPage = 1;

    if (!isset($_GET["start"])) {
        $_GET["start"] = 1;
    }
    $start = $_GET["start"];

    if ($lastPage > $num & $start > ($num / 2)) {
        $startPage = ($start - ($num / 2));
    }

    $result .= "<nav><ul class=\"pagination\">";

    if ($lastPage > $num & $start > 1) {
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
        if ($startPage > 2) {
            $result .= '<li class="page-item disabled"><a class="page-link" href="#" tabindex="-1">..</a></li>';
        }
    }

    for ($i = $startPage; $i <= min(($startPage + $num), $lastPage); $i++) {
        if ($start == $i) {
            $result .= '<li class="page-item active"><span class="page-link">' . $i . '</span></li>';
        } elseif ($i != $lastPage & $i != 1) {
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

    if ($start != $lastPage) {
        if (min(($startPage + $num), $lastPage) < ($lastPage - 1)) {
            $result .= '<li class="page-item disabled"><a class="page-link" href="#" tabindex="-1">..</a></li>';
        }
        $result .= '<li class="page-item"><a class="page-link" href="';
        $result .= '?start=' . $lastPage;
        if ($id != '') {
            $result .= '&id=' . $id;
        }
        $result .= '">';
        $result .= $lastPage;
        $result .= '</a></li>';
    }

    if ($lastPage > $num & $start < $lastPage) {
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
