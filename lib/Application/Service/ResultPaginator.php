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

namespace Poweradmin\Application\Service;

/**
 * In-memory sorting, filtering, and pagination for datasets.
 *
 * Used when the DNS backend (API) returns all results at once and
 * pagination/sorting must be handled in PHP rather than SQL.
 */
class ResultPaginator
{
    /**
     * Sort an array of associative arrays by a given key.
     *
     * @param array $data Dataset to sort
     * @param string $sortBy Key to sort by
     * @param string $direction 'ASC' or 'DESC'
     * @return array Sorted dataset
     */
    public static function sort(array $data, string $sortBy, string $direction = 'ASC'): array
    {
        if (empty($data) || $sortBy === '') {
            return $data;
        }

        $dir = strtoupper($direction) === 'DESC' ? -1 : 1;

        usort($data, function ($a, $b) use ($sortBy, $dir) {
            $va = $a[$sortBy] ?? '';
            $vb = $b[$sortBy] ?? '';

            if (is_numeric($va) && is_numeric($vb)) {
                return ($va - $vb) * $dir;
            }

            return strnatcasecmp((string)$va, (string)$vb) * $dir;
        });

        return $data;
    }

    /**
     * Filter dataset by starting letter of a field.
     *
     * @param array $data Dataset to filter
     * @param string $letter Starting letter ('all' for no filter, '1' for digits)
     * @param string $field Field name to check
     * @return array Filtered dataset
     */
    public static function filterByLetter(array $data, string $letter, string $field = 'name'): array
    {
        if ($letter === 'all' || $letter === '') {
            return $data;
        }

        return array_values(array_filter($data, function ($item) use ($letter, $field) {
            $value = $item[$field] ?? '';
            if ($value === '') {
                return false;
            }

            $firstChar = strtolower($value[0]);

            if ($letter === '1') {
                return is_numeric($firstChar);
            }

            return $firstChar === strtolower($letter);
        }));
    }

    /**
     * Filter dataset by pattern match across multiple fields.
     *
     * @param array $data Dataset to filter
     * @param string $pattern Search pattern (case-insensitive substring)
     * @param array $fields Fields to search in
     * @return array Filtered dataset
     */
    public static function filterByPattern(array $data, string $pattern, array $fields): array
    {
        if ($pattern === '' || empty($fields)) {
            return $data;
        }

        $pattern = strtolower($pattern);

        return array_values(array_filter($data, function ($item) use ($pattern, $fields) {
            foreach ($fields as $field) {
                $value = strtolower((string)($item[$field] ?? ''));
                if (str_contains($value, $pattern)) {
                    return true;
                }
            }
            return false;
        }));
    }

    /**
     * Filter dataset by exact value of a field.
     *
     * @param array $data Dataset to filter
     * @param string $field Field name
     * @param string $value Value to match (case-insensitive)
     * @return array Filtered dataset
     */
    public static function filterByValue(array $data, string $field, string $value): array
    {
        if ($value === '') {
            return $data;
        }

        $value = strtolower($value);

        return array_values(array_filter($data, function ($item) use ($field, $value) {
            return strtolower((string)($item[$field] ?? '')) === $value;
        }));
    }

    /**
     * Apply offset and limit to a dataset.
     *
     * @param array $data Dataset to paginate
     * @param int $offset Starting index
     * @param int $limit Maximum items to return
     * @return array Paginated subset
     */
    public static function paginate(array $data, int $offset, int $limit): array
    {
        return array_slice($data, $offset, $limit);
    }

    /**
     * Get distinct starting letters from a dataset field.
     *
     * @param array $data Dataset
     * @param string $field Field to extract letters from
     * @return array Unique starting letters (lowercase)
     */
    public static function getDistinctLetters(array $data, string $field = 'name'): array
    {
        $letters = [];
        foreach ($data as $item) {
            $value = $item[$field] ?? '';
            if ($value !== '') {
                $letters[strtolower($value[0])] = true;
            }
        }

        $result = array_keys($letters);
        sort($result);
        return $result;
    }
}
