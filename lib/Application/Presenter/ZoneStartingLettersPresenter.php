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

namespace Poweradmin\Application\Presenter;

class ZoneStartingLettersPresenter {
    public function present(array $availableChars, bool $digitsAvailable, string $letterStart): string {
        $html = '<span class="text-secondary">' . _('Show zones beginning with') . "</span><br>";
        $html .= '<nav>';
        $html .= '<ul class="pagination pagination-sm">';

        if ($letterStart === "1") {
            $html .= '<li class="page-item active"><span class="page-link" tabindex="-1">0-9</span></li>';
        } elseif ($digitsAvailable) {
            $html .= "<li class=\"page-item\"><a class=\"page-link\" href=\"index.php?page=list_zones&letter=1\">0-9</a></li>";
        } else {
            $html .= '<li class="page-item disabled"><span class="page-link" tabindex="-1">0-9</span></li>';
        }

        foreach (range('a', 'z') as $letter) {
            if ($letter === $letterStart) {
                $html .= '<li class="page-item active"><span class="page-link" tabindex="-1">' . $letter . '</span></li>';
            } elseif (in_array($letter, $availableChars)) {
                $html .= "<li class=\"page-item\"><a class=\"page-link\" href=\"index.php?page=list_zones&letter=" . $letter . "\">" . $letter . "</a></li>";
            } else {
                $html .= '<li class="page-item disabled"><span class="page-link" tabindex="-1">' . $letter . '</span></li>';
            }
        }

        if ($letterStart === 'all') {
            $html .= '<li class="page-item active"><span class="page-link" href="#">' . _('Show all') . '</span></li>';
        } else {
            $html .= "<li class=\"page-item\"><a class=\"page-link\" href=\"index.php?page=list_zones&letter=all\">" . _('Show all') . '</a></li>';
        }

        $html .= "</ul>";
        $html .= "</nav>";

        return $html;
    }
}
