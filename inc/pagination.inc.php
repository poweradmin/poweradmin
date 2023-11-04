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

use Poweradmin\Application\Services\UserService;
use Poweradmin\Infrastructure\Database\DbCompat;
use Poweradmin\Infrastructure\Repositories\DbUserRepository;

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

/** Print alphanumeric paging menu
 *
 * @param string $letterstart Starting letter/number or 'all'
 * @param int $userId Current user ID
 *
 * @return null
 */
function show_letters(string $letterstart, int $userId) {
    global $db;
    global $db_type;

    $query = "SELECT DISTINCT " . DbCompat::substr($db_type) . "(domains.name, 1, 1) AS letter FROM domains";

    $userRepository = new DbUserRepository($db);
    $userService = new UserService($userRepository);
    $allow_view_others = $userService->canUserViewOthersContent($userId);

    if (!$allow_view_others) {
        $query .= " LEFT JOIN zones ON domains.id = zones.domain_id";
        $query .= " WHERE zones.owner = " . $userId;
    }

    $char_range = array_merge(range('a', 'z'), array('_'));
    $query .= " ORDER BY 1";

    $available_chars = array();
    $digits_available = false;

    $response = $db->query($query);

    while ($row = $response->fetch()) {
        if (preg_match("/[0-9]/", $row['letter'])) {
            $digits_available = true;
        } elseif (in_array($row['letter'], $char_range)) {
            $available_chars[] = $row['letter'];
        }
    }

    $result = '<span class="text-secondary">' . _('Show zones beginning with') . "</span><br>";
    $result .= '<nav>';
    $result .= '<ul class="pagination pagination-sm">';

    if ($letterstart == "1") {
        $result .= '<li class="page-item active"><span class="page-link" tabindex="-1">0-9</span></li>';
    } elseif ($digits_available) {
        $result .= "<li class=\"page-item\"><a class=\"page-link\" href=\"?letter=1\">0-9</a></li>";
    } else {
        $result .= '<li class="page-item disabled"><a class="page-link" href="#" tabindex="-1">0-9</a></li>';
    }

    foreach ($char_range as $letter) {
        if ($letter == $letterstart) {
            $result .= '<li class="page-item active"><span class="page-link" tabindex="-1">' . $letter . '</span></li>';
        } elseif (in_array($letter, $available_chars)) {
            $result .= "<li class=\"page-item\"><a class=\"page-link\" href=\"?letter=" . $letter . "\">" . $letter . "</a></li>";
        } else {
            $result .= '<li class="page-item disabled"><span class="page-link" tabindex="-1">' . $letter . '</span></li>';
        }
    }

    if ($letterstart == 'all') {
        $result .= '<li class="page-item active"><a class="page-link" href="#" tabindex="-1">' . _('Show all') . '</a></li>';
    } else {
        $result .= "<li class=\"page-item\"><a class=\"page-link\" href=\"?letter=all\">" . _('Show all') . '</a></li>';
    }

    $result .= "</ul>";
    $result .= "</nav>";

    return $result;
}
