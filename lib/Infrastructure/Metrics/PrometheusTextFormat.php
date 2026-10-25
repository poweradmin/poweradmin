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

namespace Poweradmin\Infrastructure\Metrics;

use InvalidArgumentException;

/**
 * Renders metrics in the Prometheus text exposition format 0.0.4.
 *
 * Each family is ['name' => ..., 'type' => 'gauge'|'counter', 'help' => ...,
 * 'samples' => [['labels' => [...], 'value' => number], ...]].
 */
final class PrometheusTextFormat
{
    public const CONTENT_TYPE = 'text/plain; version=0.0.4; charset=utf-8';

    private const NAME_PATTERN = '/^[a-zA-Z_:][a-zA-Z0-9_:]*$/';
    private const LABEL_PATTERN = '/^[a-zA-Z_][a-zA-Z0-9_]*$/';

    /**
     * @param list<array{name: string, type: string, help: string, samples: list<array{labels?: array<string, string>, value: int|float}>}> $families
     */
    public static function render(array $families): string
    {
        $lines = [];
        foreach ($families as $family) {
            $name = $family['name'];
            if (!preg_match(self::NAME_PATTERN, $name)) {
                throw new InvalidArgumentException(sprintf('Invalid metric name "%s"', $name));
            }
            if (!in_array($family['type'], ['gauge', 'counter'], true)) {
                throw new InvalidArgumentException(sprintf('Unsupported metric type "%s"', $family['type']));
            }

            $lines[] = sprintf('# HELP %s %s', $name, self::escapeHelp($family['help']));
            $lines[] = sprintf('# TYPE %s %s', $name, $family['type']);
            foreach ($family['samples'] as $sample) {
                $lines[] = $name . self::labels($sample['labels'] ?? []) . ' ' . self::value($sample['value']);
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<string, string> $labels
     */
    private static function labels(array $labels): string
    {
        if ($labels === []) {
            return '';
        }

        $pairs = [];
        foreach ($labels as $key => $value) {
            if (!preg_match(self::LABEL_PATTERN, $key)) {
                throw new InvalidArgumentException(sprintf('Invalid label name "%s"', $key));
            }
            $pairs[] = sprintf('%s="%s"', $key, str_replace(['\\', "\n", '"'], ['\\\\', '\n', '\"'], $value));
        }

        return '{' . implode(',', $pairs) . '}';
    }

    private static function escapeHelp(string $help): string
    {
        return str_replace(['\\', "\n"], ['\\\\', '\n'], $help);
    }

    private static function value(int|float $value): string
    {
        if (is_int($value)) {
            return (string)$value;
        }
        if (is_nan($value)) {
            return 'NaN';
        }
        if (is_infinite($value)) {
            return $value > 0 ? '+Inf' : '-Inf';
        }

        return rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
    }
}
