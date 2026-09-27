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

namespace Poweradmin\Application\Http;

/**
 * Opt-in paging and substring filtering for API list endpoints. paginate() and
 * filterContains() serve lists that are already loaded, e.g. records, which come
 * from the PowerDNS API in API backend mode; isPastEnd() and extra() also serve
 * lists paged in SQL, such as change requests.
 *
 * Paging follows GET /change-requests: nothing is paged without per_page (or
 * with 0); with it, page starts at 1 and per_page is capped.
 */
final class ListPaging
{
    /**
     * @return array{0: int, 1: int} [page, perPage]; perPage 0 means "return everything"
     */
    public static function parameters(mixed $page, mixed $perPage, int $maxPageSize): array
    {
        $perPage = is_numeric($perPage) ? (int)$perPage : 0;
        if ($perPage <= 0) {
            return [1, 0];
        }

        $page = is_numeric($page) ? (int)$page : 1;

        return [max(1, $page), min($maxPageSize, $perPage)];
    }

    /**
     * Whether the page lies beyond the last one. Checked before any offset is
     * computed, so a huge page number can never overflow ($page - 1) * $perPage.
     */
    public static function isPastEnd(int $page, int $perPage, int $total): bool
    {
        return $page > self::lastPage($perPage, $total);
    }

    /**
     * The pagination object for a paged response, or nothing when per_page was
     * not given.
     *
     * @return array<string, mixed> Extra top-level response fields
     */
    public static function extra(int $page, int $perPage, int $total): array
    {
        if ($perPage <= 0) {
            return [];
        }

        return ['pagination' => [
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => self::lastPage($perPage, $total),
        ]];
    }

    /**
     * Page a list that is already filtered and sorted. The total counts every
     * item passed in, so pass only what the caller may see.
     *
     * @param array<int, mixed> $items
     * @return array{0: list<mixed>, 1: array<string, mixed>} [items of the page, extra response fields]
     */
    public static function paginate(array $items, int $page, int $perPage): array
    {
        $items = array_values($items);
        if ($perPage <= 0) {
            return [$items, []];
        }

        $total = count($items);
        $extra = self::extra($page, $perPage, $total);
        if (self::isPastEnd($page, $perPage, $total)) {
            return [[], $extra];
        }

        return [array_slice($items, ($page - 1) * $perPage, $perPage), $extra];
    }

    private static function lastPage(int $perPage, int $total): int
    {
        return max(1, (int)ceil($total / max(1, $perPage)));
    }

    /**
     * Keep the items with a value that contains the needle, ignoring case.
     * An empty needle keeps everything.
     *
     * @param array<int, array<string, mixed>> $items
     * @param callable $values Returns the values of an item to search
     * @return list<array<string, mixed>>
     */
    public static function filterContains(array $items, string $needle, callable $values): array
    {
        $needle = trim($needle);
        if ($needle === '') {
            return array_values($items);
        }

        return array_values(array_filter($items, static function (array $item) use ($needle, $values): bool {
            foreach ($values($item) as $value) {
                if (is_scalar($value) && mb_stripos((string)$value, $needle) !== false) {
                    return true;
                }
            }
            return false;
        }));
    }
}
