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

namespace Poweradmin\Application\Presenter;

/**
 * Describes the 0-9, a-z and "Show all" filter links on the forward zone list page.
 *
 * items() feeds templates/default/_partials/letters.html; present() is the pre-rendered
 * string still exposed as the `letters` template variable for theme forks.
 */
final class ZoneStartingLettersPresenter
{
    /**
     * The digits bucket, a-z, any non-ASCII initials (IDN zones) and "Show all", in display order.
     * `letter` is the raw filter value ('1' for the digits bucket, 'all' for the last item).
     *
     * @return list<array{letter: string, label: string, url: ?string, active: bool, disabled: bool}>
     */
    public function items(array $availableChars, bool $digitsAvailable, string $letterStart, string $baseUrlPrefix = '', ?int $rowsPerPage = null): array
    {
        $rowsPerPageParam = $rowsPerPage !== null ? '&rows_per_page=' . $rowsPerPage : '';
        $url = fn(string $letter): string => $baseUrlPrefix . '/zones/forward?letter=' . $letter . $rowsPerPageParam;

        $items = [];

        if ($letterStart === '1') {
            $items[] = $this->item('1', '0-9', null, true, false);
        } elseif ($digitsAvailable) {
            $items[] = $this->item('1', '0-9', $url('1'), false, false);
        } else {
            $items[] = $this->item('1', '0-9', null, false, true);
        }

        foreach (range('a', 'z') as $letter) {
            if ($letter === $letterStart) {
                $items[] = $this->item($letter, $letter, null, true, false);
            } elseif (in_array($letter, $availableChars)) {
                $items[] = $this->item($letter, $letter, $url($letter), false, false);
            } else {
                $items[] = $this->item($letter, $letter, null, false, true);
            }
        }

        // Non-ASCII initials only exist once IDN zones are present; they follow the alphabet.
        foreach ($availableChars as $letter) {
            if ($letter === '' || preg_match('/^[a-z0-9]$/', $letter)) {
                continue;
            }
            if ($letter === $letterStart) {
                $items[] = $this->item($letter, $letter, null, true, false);
            } else {
                $items[] = $this->item($letter, $letter, $url(rawurlencode($letter)), false, false);
            }
        }

        $items[] = $this->item('all', _('Show all'), $letterStart === 'all' ? null : $url('all'), $letterStart === 'all', false);

        return $items;
    }

    public function present(array $availableChars, bool $digitsAvailable, string $letterStart, string $baseUrlPrefix = '', ?int $rowsPerPage = null): string
    {
        $html = '<span class="text-secondary">' . _('Show zones beginning with') . '</span><br>';
        $html .= '<nav><ul class="pagination pagination-sm d-flex flex-wrap">';

        foreach ($this->items($availableChars, $digitsAvailable, $letterStart, $baseUrlPrefix, $rowsPerPage) as $item) {
            $label = htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8');
            if ($item['active']) {
                // The "Show all" item has always carried href="#" instead of tabindex; kept for theme forks.
                $attribute = $item['letter'] === 'all' ? 'href="#"' : 'tabindex="-1"';
                $html .= '<li class="page-item active"><span class="page-link" ' . $attribute . '>' . $label . '</span></li>';
            } elseif ($item['disabled']) {
                $html .= '<li class="page-item disabled"><span class="page-link" tabindex="-1">' . $label . '</span></li>';
            } else {
                $html .= '<li class="page-item"><a class="page-link" href="' . $item['url'] . '">' . $label . '</a></li>';
            }
        }

        return $html . '</ul></nav>';
    }

    /**
     * @return array{letter: string, label: string, url: ?string, active: bool, disabled: bool}
     */
    private function item(string $letter, string $label, ?string $url, bool $active, bool $disabled): array
    {
        return ['letter' => $letter, 'label' => $label, 'url' => $url, 'active' => $active, 'disabled' => $disabled];
    }
}
