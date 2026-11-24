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

namespace Poweradmin\Domain\Service\Dns;

/**
 * Parses one CSV line of the bulk record form (name,type,content,prio,ttl[,disabled][,comment]) into a record.
 */
final class BulkRecordParser
{
    /** Whitespace str_getcsv skips before an opening quote (the newline is handled on its own) */
    private const FIELD_SPACE = [' ', "\t", "\r", "\x0B", "\f"];

    /**
     * Split pasted bulk input into records, keeping a newline inside a double-quoted
     * cell in that cell. A quote that never closes, or text right after a closing quote,
     * falls back to one record per line for the rest of the input.
     *
     * @return list<string>
     */
    public function splitRecords(string $text): array
    {
        $rows = [];
        $length = strlen($text);
        $start = 0;
        $inQuotes = false;
        $fieldStart = true;
        $afterClose = false;
        $malformed = false;

        for ($pos = 0; $pos < $length; $pos++) {
            $char = $text[$pos];

            if ($inQuotes) {
                // Same rules as the str_getcsv call in parseLine: a doubled quote stays inside the cell
                if ($char === '"') {
                    if (($text[$pos + 1] ?? '') === '"') {
                        $pos++;
                    } else {
                        $inQuotes = false;
                        $afterClose = true;
                    }
                }
            } elseif ($afterClose && $char !== ',' && $char !== "\n" && !in_array($char, self::FIELD_SPACE, true)) {
                // A typo such as a missing closing quote would otherwise pull the next line into this record
                $malformed = true;
                break;
            } elseif ($char === "\n") {
                $rows[] = substr($text, $start, $pos - $start);
                $start = $pos + 1;
                $fieldStart = true;
                $afterClose = false;
            } elseif ($char === ',') {
                $fieldStart = true;
                $afterClose = false;
            } elseif ($char === '"' && $fieldStart) {
                $inQuotes = true;
                $fieldStart = false;
            } elseif (!in_array($char, self::FIELD_SPACE, true)) {
                $fieldStart = false;
            }
        }

        if ($inQuotes || $malformed) {
            // Re-split from the broken record by physical line so one stray quote cannot swallow the rest
            return array_merge($rows, explode("\n", substr($text, $start)));
        }

        $rows[] = substr($text, $start);
        return $rows;
    }

    /**
     * Parse a CSV line into a record array.
     *
     * @return array{name: string, type: string, content: string, prio: int, ttl: int|null, disabled: int, comment: string}|string Error message on failure
     */
    public function parseLine(string $line, ?int $defaultTtl = null): array|string
    {
        $line = trim($line);
        if (empty($line)) {
            return _('Empty line.');
        }

        // Empty escape, as the CSV export writes with fputcsv: a backslash is plain data,
        // so a TXT value holding \" survives the round trip.
        $parts = str_getcsv($line, ',', '"', '');

        if (count($parts) < 3) {
            return _('Invalid format. Expected at least: name,type,content');
        }

        $name = trim($parts[0]);
        $type = strtoupper(trim($parts[1]));
        $content = trim($parts[2]);

        if ($type === 'SRV') {
            return $this->parseSrvRecord($parts, $name, $content, $defaultTtl);
        }

        $prio = isset($parts[3]) && $parts[3] !== '' ? (int)$parts[3] : 0;
        $ttl = isset($parts[4]) && $parts[4] !== '' ? (int)$parts[4] : $defaultTtl;
        [$disabled, $comment] = $this->parseDisabledAndComment($parts, 5);

        return [
            'name' => $name,
            'type' => $type,
            'content' => $content,
            'prio' => $prio,
            'ttl' => $ttl,
            'disabled' => $disabled,
            'comment' => $comment,
        ];
    }


    /**
     * @param array<int, string> $parts
     * @return array{name: string, type: string, content: string, prio: int, ttl: int|null, disabled: int, comment: string}|string
     */
    private function parseSrvRecord(array $parts, string $name, string $content, ?int $defaultTtl): array|string
    {
        if (str_contains($content, ' ')) {
            // CSV export format: name,SRV,"weight port target",priority,ttl[,disabled][,comment]
            $prio = isset($parts[3]) && $parts[3] !== '' ? (int)$parts[3] : 0;
            $ttl = isset($parts[4]) && $parts[4] !== '' ? (int)$parts[4] : $defaultTtl;
            [$disabled, $comment] = $this->parseDisabledAndComment($parts, 5);
        } elseif (count($parts) >= 5) {
            // Legacy format: name,SRV,target,weight,port,ttl[,disabled][,comment]
            $prio = 0;
            $weight = (int)$parts[3];
            $port = isset($parts[4]) && is_numeric($parts[4]) ? (int)$parts[4] : 0;
            $content = "$weight $port $content";
            $ttl = isset($parts[5]) && is_numeric($parts[5]) ? (int)$parts[5] : $defaultTtl;
            [$disabled, $comment] = $this->parseDisabledAndComment($parts, 6);
        } else {
            return _('Invalid SRV format. Use: name,SRV,"weight port target",priority,ttl or name,SRV,target,weight,port,ttl');
        }

        return [
            'name' => $name,
            'type' => 'SRV',
            'content' => $content,
            'prio' => $prio,
            'ttl' => $ttl,
            'disabled' => $disabled,
            'comment' => $comment,
        ];
    }

    /**
     * Parse optional disabled and comment fields from CSV parts.
     *
     * Detects "Yes"/"No" as the disabled flag (matching CSV export format).
     * If not present, treats the field as a comment.
     *
     * @param array<int, string> $parts
     * @return array{0: int, 1: string} [disabled, comment]
     */
    private function parseDisabledAndComment(array $parts, int $offset): array
    {
        $field = isset($parts[$offset]) ? trim($parts[$offset]) : '';

        if (strcasecmp($field, 'Yes') === 0) {
            $comment = isset($parts[$offset + 1]) ? trim($parts[$offset + 1]) : '';
            return [1, $comment];
        }

        if (strcasecmp($field, 'No') === 0) {
            $comment = isset($parts[$offset + 1]) ? trim($parts[$offset + 1]) : '';
            return [0, $comment];
        }

        return [0, $field];
    }
}
