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
 */

namespace Poweradmin\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Every fputcsv() call in lib/ must pass a one-character delimiter and enclosure and
 * an empty escape (an optional sixth line-ending argument is allowed). Without the escape PHP 8.4 prints a deprecation into the download,
 * and a two-character enclosure throws ValueError; the exports themselves are not
 * reachable from a unit test, so the call sites are checked in the source.
 */
class CsvCallSiteTest extends TestCase
{
    private const EXPECTED_TAIL = ["','", "'\"'", "''"];

    public function testEveryFputcsvCallPassesDelimiterEnclosureAndEmptyEscape(): void
    {
        $calls = 0;
        $problems = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/lib'));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            foreach ($this->fputcsvArguments((string)file_get_contents($file->getPathname())) as [$line, $args]) {
                $calls++;
                if (!in_array(count($args), [5, 6], true) || array_slice($args, 2, 3) !== self::EXPECTED_TAIL) {
                    $problems[] = $file->getPathname() . ':' . $line . ' passes (' . implode(', ', $args) . ')';
                }
            }
        }

        $this->assertGreaterThan(0, $calls, 'No fputcsv() call found; the scan is broken.');
        $this->assertSame([], $problems);
    }

    /**
     * The built-in, called as fputcsv or \fputcsv; not a method, a static call or a
     * declaration that happens to share the name.
     */
    private function isGlobalFputcsvName(array $tokens, int $i): bool
    {
        $names = [T_STRING => 'fputcsv', T_NAME_FULLY_QUALIFIED => '\\fputcsv'];
        $token = $tokens[$i];
        if (!is_array($token) || ($names[$token[0]] ?? null) !== strtolower($token[1])) {
            return false;
        }

        $prev = $i - 1;
        while ($prev >= 0 && $this->isTrivia($tokens[$prev])) {
            $prev--;
        }
        $before = $tokens[$prev] ?? null;
        $beforeId = is_array($before) ? $before[0] : $before;
        return !in_array($beforeId, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true);
    }

    private function isTrivia(array|string $token): bool
    {
        return is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }

    /**
     * @return list<array{int, list<string>}> line number and the source text of each argument
     */
    private function fputcsvArguments(string $source): array
    {
        $tokens = token_get_all($source);
        $found = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if (!$this->isGlobalFputcsvName($tokens, $i)) {
                continue;
            }
            $j = $i + 1;
            while ($j < $count && $this->isTrivia($tokens[$j])) {
                $j++;
            }
            if (($tokens[$j] ?? null) !== '(') {
                continue;
            }
            $depth = 0;
            $args = [''];
            for ($j++; $j < $count; $j++) {
                if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $text = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                if (in_array($text, ['(', '[', '{'], true)) {
                    $depth++;
                } elseif (in_array($text, [')', ']', '}'], true)) {
                    if ($depth === 0) {
                        break;
                    }
                    $depth--;
                } elseif ($text === ',' && $depth === 0) {
                    $args[] = '';
                    continue;
                }
                $args[count($args) - 1] .= $text;
            }
            $args = array_map('trim', $args);
            if (count($args) > 1 && end($args) === '') {
                array_pop($args); // trailing comma
            }
            $found[] = [$tokens[$i][2], $args];
        }
        return $found;
    }
}
