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
 * Sort order requested through the `sort` query parameter of an API list endpoint.
 *
 * Format: comma-separated fields, each optionally suffixed with `:asc` or `:desc`
 * (default ascending), e.g. `type,ttl:desc`. Only fields on the endpoint's
 * whitelist are accepted. An invalid value does not throw: it yields a sort
 * with an error message, which the controller turns into a 400.
 */
final class ListSort
{
    /** Upper bound on sort keys. */
    private const MAX_FIELDS = 5;

    /**
     * @param list<array{field: string, desc: bool}> $fields
     */
    private function __construct(
        private readonly array $fields,
        public readonly ?string $error = null
    ) {
    }

    private static function none(): self
    {
        return new self([]);
    }

    /**
     * Parse a `sort` query value against the endpoint's allowed fields.
     *
     * @param string[] $allowedFields
     */
    public static function fromQuery(?string $raw, array $allowedFields): self
    {
        $raw = trim((string)$raw);
        if ($raw === '') {
            return self::none();
        }

        $parts = explode(',', $raw);
        if (count($parts) > self::MAX_FIELDS) {
            return self::invalid(sprintf('At most %d sort fields are allowed', self::MAX_FIELDS));
        }

        $fields = [];
        $seen = [];
        foreach ($parts as $part) {
            [$field, $direction] = array_pad(explode(':', trim($part), 2), 2, 'asc');
            $field = strtolower(trim($field));
            $direction = strtolower(trim($direction));

            if (!in_array($field, $allowedFields, true)) {
                return self::invalid(sprintf("Invalid sort field '%s'. Allowed fields: %s", $field, implode(', ', $allowedFields)));
            }
            if ($direction !== 'asc' && $direction !== 'desc') {
                return self::invalid(sprintf("Invalid sort direction '%s' for field '%s'. Use asc or desc", $direction, $field));
            }
            if (isset($seen[$field])) {
                return self::invalid(sprintf("Sort field '%s' is given more than once", $field));
            }

            $seen[$field] = true;
            $fields[] = ['field' => $field, 'desc' => $direction === 'desc'];
        }

        return new self($fields);
    }

    private static function invalid(string $error): self
    {
        return new self([], $error);
    }

    /**
     * The requested sort keys in order, for endpoints that sort in SQL. Every
     * field is one of the allowed fields; empty when no sort was requested.
     *
     * @return list<array{field: string, desc: bool}>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * Sort already-loaded rows (for endpoints that filter in PHP rather than SQL).
     *
     * Integers compare numerically, everything else case-insensitively in
     * natural order, and nulls sort first. usort() is stable, so rows that
     * compare equal keep their original order.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function sortRows(array $rows): array
    {
        $rows = array_values($rows);
        if ($this->fields === []) {
            return $rows;
        }

        usort($rows, function (array $a, array $b): int {
            foreach ($this->fields as $sortField) {
                $result = self::compareValues($a[$sortField['field']] ?? null, $b[$sortField['field']] ?? null);
                if ($result !== 0) {
                    return $sortField['desc'] ? -$result : $result;
                }
            }
            return 0;
        });

        return $rows;
    }

    private static function compareValues(mixed $a, mixed $b): int
    {
        if ($a === null || $b === null) {
            return ($a === null ? 0 : 1) <=> ($b === null ? 0 : 1);
        }
        if (is_int($a) && is_int($b)) {
            return $a <=> $b;
        }
        return strnatcasecmp((string)$a, (string)$b);
    }
}
