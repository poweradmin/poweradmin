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

namespace Poweradmin\Module\ZoneImportExport\Service;

/**
 * BIND zone file parser.
 *
 * Parses standard BIND zone file format (RFC 1035) with Cloudflare extensions.
 * Informed by PowerDNS ZoneParserTNG patterns.
 */
class BindZoneFileParser
{
    private const KNOWN_TYPES = [
        'A', 'AAAA', 'AFSDB', 'ALIAS', 'APL', 'CAA', 'CDNSKEY', 'CDS', 'CERT',
        'CNAME', 'CSYNC', 'DHCID', 'DLV', 'DNAME', 'DNSKEY', 'DS', 'EUI48',
        'EUI64', 'HINFO', 'HTTPS', 'IPSECKEY', 'KEY', 'KX', 'LOC', 'MX',
        'NAPTR', 'NID', 'NS', 'NSEC', 'NSEC3', 'NSEC3PARAM', 'OPENPGPKEY',
        'PTR', 'RP', 'RRSIG', 'SMIMEA', 'SOA', 'SPF', 'SRV', 'SSHFP',
        'SVCB', 'TKEY', 'TLSA', 'TSIG', 'TXT', 'URI', 'ZONEMD',
    ];

    private const TYPES_WITH_NAME_RDATA = [
        'MX', 'NS', 'CNAME', 'PTR', 'DNAME', 'SRV', 'AFSDB', 'KX',
    ];

    private int $autoTtlValue;

    public function __construct(int $autoTtlValue = 300)
    {
        $this->autoTtlValue = $autoTtlValue;
    }

    public function parse(string $content): ParsedZoneFile
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $content));
        $lines = $this->joinMultilineRecords($lines);

        $origin = null;
        $defaultTtl = 86400;
        $records = [];
        $warnings = [];
        $previousName = null;

        foreach ($lines as $lineNum => $line) {
            $line = $this->stripComment($line);
            $trimmed = trim($line);

            if ($trimmed === '' || $trimmed[0] === ';') {
                continue;
            }

            // Handle directives
            if ($trimmed[0] === '$') {
                $parts = preg_split('/\s+/', $trimmed);
                $directive = strtoupper($parts[0]);

                if ($directive === '$ORIGIN') {
                    $origin = isset($parts[1]) ? rtrim($parts[1], '.') : null;
                    continue;
                }

                if ($directive === '$TTL') {
                    $defaultTtl = isset($parts[1]) ? $this->parseTtl($parts[1]) : 86400;
                    continue;
                }

                // Skip unsupported directives ($GENERATE, $INCLUDE)
                $warnings[] = sprintf('Line %d: Unsupported directive "%s", skipped', $lineNum + 1, $directive);
                continue;
            }

            // Parse record line
            $parsed = $this->parseRecordLine($line, $origin, $defaultTtl, $previousName);
            if ($parsed === null) {
                $warnings[] = sprintf('Line %d: Could not parse record, skipped', $lineNum + 1);
                continue;
            }

            // Map Cloudflare TTL=1 (auto) to configured default
            if ($parsed->ttl === 1) {
                $parsed->ttl = $this->autoTtlValue;
            }

            $previousName = $parsed->name;
            $records[] = $parsed;
        }

        return new ParsedZoneFile($origin, $defaultTtl, $records, $warnings);
    }

    /**
     * Join multi-line records (parenthesized).
     *
     * @param string[] $lines
     * @return string[]
     */
    private function joinMultilineRecords(array $lines): array
    {
        $result = [];
        $buffer = '';
        $inParens = false;

        foreach ($lines as $line) {
            $stripped = $this->stripComment($line);

            if ($inParens) {
                $buffer .= ' ' . trim($stripped);
                if (strpos($stripped, ')') !== false) {
                    $inParens = false;
                    $buffer = str_replace(['(', ')'], '', $buffer);
                    $result[] = $buffer;
                    $buffer = '';
                }
            } else {
                if (strpos($stripped, '(') !== false && strpos($stripped, ')') === false) {
                    $inParens = true;
                    $buffer = $stripped;
                } else {
                    $result[] = str_replace(['(', ')'], '', $line);
                }
            }
        }

        // Handle unclosed parens
        if ($buffer !== '') {
            $result[] = str_replace(['(', ')'], '', $buffer);
        }

        return $result;
    }

    /**
     * Strip comments from a line, respecting quoted strings.
     */
    private function stripComment(string $line): string
    {
        $inQuote = false;
        $len = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            $char = $line[$i];
            if ($char === '"' && ($i === 0 || $line[$i - 1] !== '\\')) {
                $inQuote = !$inQuote;
            } elseif ($char === ';' && !$inQuote) {
                return substr($line, 0, $i);
            }
        }
        return $line;
    }

    private function parseRecordLine(string $line, ?string $origin, int $defaultTtl, ?string $previousName): ?ParsedRecord
    {
        // Leading whitespace means reuse previous name
        $hasLeadingWhitespace = $line !== '' && ($line[0] === ' ' || $line[0] === "\t");
        $tokens = preg_split('/\s+/', trim($line));
        $tokens = array_values(array_filter($tokens, fn($t) => $t !== ''));

        if (count($tokens) < 2) {
            return null;
        }

        $pos = 0;

        // Determine record name
        if ($hasLeadingWhitespace) {
            $name = $previousName ?? ($origin ? $origin . '.' : '');
        } else {
            $name = $tokens[$pos];
            $pos++;
        }

        // Resolve special names
        if ($name === '@') {
            $name = $origin ? $origin . '.' : '';
        }

        // Parse optional TTL and class (can be in either order)
        $ttl = null;
        $foundClass = false;
        $type = null;

        // Look for TTL and/or class before the record type
        for ($i = 0; $i < 2 && ($pos + $i) < count($tokens); $i++) {
            $token = $tokens[$pos];

            if (!$foundClass && strtoupper($token) === 'IN') {
                $foundClass = true;
                $pos++;
                continue;
            }

            if ($ttl === null && $this->isTtlValue($token)) {
                $ttl = $this->parseTtl($token);
                $pos++;
                continue;
            }

            break;
        }

        if ($ttl === null) {
            $ttl = $defaultTtl;
        }

        // Next token should be the record type
        if ($pos >= count($tokens)) {
            return null;
        }

        $type = strtoupper($tokens[$pos]);
        $pos++;

        if (!in_array($type, self::KNOWN_TYPES, true)) {
            return null;
        }

        // Remaining tokens are rdata
        $rdataTokens = array_slice($tokens, $pos);
        if (empty($rdataTokens)) {
            return null;
        }

        // Make name fully qualified (skip if reusing previous name, already absolute)
        if (!$hasLeadingWhitespace) {
            $name = $this->makeAbsolute($name, $origin);
        }

        // Parse type-specific content
        return $this->buildRecord($name, $ttl, $type, $rdataTokens, $origin);
    }

    private function buildRecord(string $name, int $ttl, string $type, array $rdataTokens, ?string $origin): ?ParsedRecord
    {
        $priority = 0;

        switch ($type) {
            case 'MX':
            case 'KX':
                if (count($rdataTokens) < 2) {
                    return null;
                }
                $priority = (int)$rdataTokens[0];
                $target = $this->makeAbsolute($rdataTokens[1], $origin);
                $content = $target;
                break;

            case 'SRV':
                if (count($rdataTokens) < 4) {
                    return null;
                }
                $priority = (int)$rdataTokens[0];
                $weight = $rdataTokens[1];
                $port = $rdataTokens[2];
                $target = $this->makeAbsolute($rdataTokens[3], $origin);
                $content = "$weight $port $target";
                break;

            case 'SOA':
                if (count($rdataTokens) < 7) {
                    return null;
                }
                $mname = $this->makeAbsolute($rdataTokens[0], $origin);
                $rname = $this->makeAbsolute($rdataTokens[1], $origin);
                $content = sprintf(
                    '%s %s %s %s %s %s %s',
                    $mname,
                    $rname,
                    $rdataTokens[2],
                    $rdataTokens[3],
                    $rdataTokens[4],
                    $rdataTokens[5],
                    $rdataTokens[6]
                );
                break;

            case 'NS':
            case 'CNAME':
            case 'PTR':
            case 'DNAME':
            case 'AFSDB':
                $target = $this->makeAbsolute($rdataTokens[0], $origin);
                $content = $target;
                if ($type === 'AFSDB' && count($rdataTokens) >= 2) {
                    $priority = (int)$rdataTokens[0];
                    $content = $this->makeAbsolute($rdataTokens[1], $origin);
                }
                break;

            case 'TXT':
            case 'SPF':
                $content = $this->parseTxtRdata($rdataTokens);
                break;

            case 'CAA':
                if (count($rdataTokens) < 3) {
                    return null;
                }
                $flags = $rdataTokens[0];
                $tag = $rdataTokens[1];
                $value = implode(' ', array_slice($rdataTokens, 2));
                $content = "$flags $tag $value";
                break;

            case 'NAPTR':
                if (count($rdataTokens) < 6) {
                    return null;
                }
                $replacement = $this->makeAbsolute($rdataTokens[5], $origin);
                $parts = array_slice($rdataTokens, 0, 5);
                $parts[] = $replacement;
                $content = implode(' ', $parts);
                break;

            default:
                $content = implode(' ', $rdataTokens);
                break;
        }

        return new ParsedRecord($name, $ttl, $type, $content, $priority);
    }

    /**
     * Parse concatenated TXT rdata tokens, handling quoted strings.
     */
    private function parseTxtRdata(array $tokens): string
    {
        $raw = implode(' ', $tokens);
        $parts = [];
        $len = strlen($raw);
        $i = 0;

        while ($i < $len) {
            // Skip whitespace
            while ($i < $len && ($raw[$i] === ' ' || $raw[$i] === "\t")) {
                $i++;
            }

            if ($i >= $len) {
                break;
            }

            if ($raw[$i] === '"') {
                // Quoted string
                $i++;
                $part = '';
                while ($i < $len && $raw[$i] !== '"') {
                    if ($raw[$i] === '\\' && ($i + 1) < $len) {
                        $part .= $raw[$i] . $raw[$i + 1];
                        $i += 2;
                    } else {
                        $part .= $raw[$i];
                        $i++;
                    }
                }
                $parts[] = '"' . $part . '"';
                if ($i < $len) {
                    $i++; // skip closing quote
                }
            } else {
                // Unquoted token
                $part = '';
                while ($i < $len && $raw[$i] !== ' ' && $raw[$i] !== "\t") {
                    $part .= $raw[$i];
                    $i++;
                }
                $parts[] = '"' . $part . '"';
            }
        }

        return implode(' ', $parts);
    }

    /**
     * Make a name fully qualified (ending with a dot stripped for PowerDNS storage).
     */
    private function makeAbsolute(string $name, ?string $origin): string
    {
        if ($name === '') {
            return $origin ?? '';
        }

        // Already fully qualified
        if (str_ends_with($name, '.')) {
            return rtrim($name, '.');
        }

        // Relative name — append origin
        if ($origin !== null) {
            return $name . '.' . $origin;
        }

        return $name;
    }

    private function isTtlValue(string $token): bool
    {
        return (bool)preg_match('/^\d+[smhdwy]?$/i', $token);
    }

    /**
     * Parse TTL value with optional time suffix.
     * Supports: s (seconds), m (minutes), h (hours), d (days), w (weeks), y (years)
     */
    public function parseTtl(string $value): int
    {
        $value = strtolower(trim($value));

        if (is_numeric($value)) {
            return (int)$value;
        }

        $total = 0;
        $current = '';

        for ($i = 0; $i < strlen($value); $i++) {
            $char = $value[$i];

            if (ctype_digit($char)) {
                $current .= $char;
            } else {
                $num = $current !== '' ? (int)$current : 0;
                $current = '';

                switch ($char) {
                    case 's':
                        $total += $num;
                        break;
                    case 'm':
                        $total += $num * 60;
                        break;
                    case 'h':
                        $total += $num * 3600;
                        break;
                    case 'd':
                        $total += $num * 86400;
                        break;
                    case 'w':
                        $total += $num * 604800;
                        break;
                    case 'y':
                        $total += $num * 31536000;
                        break;
                }
            }
        }

        // Handle trailing number without suffix
        if ($current !== '') {
            $total += (int)$current;
        }

        return $total;
    }
}
