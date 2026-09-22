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

namespace Poweradmin\Application\Console;

use InvalidArgumentException;

/**
 * Writes the tabular output of a command as tab-separated lines (a header
 * row, then one line per row) or as a JSON list of objects keyed by the
 * lower-cased column names.
 */
final class TableWriter
{
    public const OPTION = 'format';
    public const FORMATS = ['tsv', 'json'];

    /** @var resource */
    private $stdout;
    private string $format;

    /**
     * @param resource $stdout
     */
    private function __construct($stdout, string $format)
    {
        $this->stdout = $stdout;
        $this->format = $format;
    }

    /**
     * @param resource $stdout
     * @throws InvalidArgumentException when --format names anything but tsv or json
     */
    public static function fromArguments(Arguments $arguments, $stdout): self
    {
        $format = $arguments->has(self::OPTION) ? $arguments->value(self::OPTION) : 'tsv';
        if ($format === null || !in_array($format, self::FORMATS, true)) {
            throw new InvalidArgumentException(sprintf('Option --%s expects one of %s', self::OPTION, implode(', ', self::FORMATS)));
        }

        return new self($stdout, $format);
    }

    /**
     * @param list<string> $columns
     * @param iterable<list<int|string|bool>> $rows One value per column, in column order
     */
    public function write(array $columns, iterable $rows): void
    {
        if ($this->format === 'json') {
            $this->writeJson($columns, $rows);
            return;
        }

        fwrite($this->stdout, implode("\t", $columns) . "\n");
        foreach ($rows as $row) {
            fwrite($this->stdout, implode("\t", array_map(self::tsvCell(...), $row)) . "\n");
        }
    }

    /**
     * @param list<string> $columns
     * @param iterable<list<int|string|bool>> $rows
     */
    private function writeJson(array $columns, iterable $rows): void
    {
        $keys = array_map('strtolower', $columns);
        $objects = [];
        foreach ($rows as $row) {
            $objects[] = array_combine($keys, $row);
        }

        fwrite($this->stdout, json_encode($objects, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }

    private static function tsvCell(int|string|bool $value): string
    {
        return is_bool($value) ? ($value ? '1' : '0') : (string) $value;
    }
}
