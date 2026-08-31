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

/**
 * Fails when a spec asserts on a URL fragment or href that no route can produce.
 *
 * Sibling of check-route-paths.php: that one covers where a spec navigates, this
 * one covers what it then claims about the address. A wrong fragment is silent -
 * `expect(x || url.includes('api/keys'))` degrades to `expect(x)` because the
 * route is /settings/api-keys, and the negated form is permanently true, which
 * makes the test unfailable rather than merely weaker.
 *
 * Also rejects fragments that are true for every URL, such as includes('/').
 */

require __DIR__ . '/../../vendor/autoload.php';

use Symfony\Component\Config\FileLocator;
use Symfony\Component\Routing\Loader\YamlFileLoader;

$root = dirname(__DIR__, 2);

// A "contains" test on this is true of every url, so it can never fail.
$tautologies = [
    '/',
];

// Real fragments that no route path can contain by construction: '/?' is the
// index with a query string, which the router never sees as part of the path.
$nonRoute = [
    '/?',
];

$routes = (new YamlFileLoader(new FileLocator([$root . '/lib/Application/Config'])))->load('routes.yaml');

// Modules register their routes after routes.yaml, so a fragment may come from
// one of those instead. They are plain literals in each module class.
$moduleRouteCount = 0;
foreach (glob($root . '/lib/Module/*/*Module.php') ?: [] as $file) {
    if (!preg_match_all("/'path'\s*=>\s*'([^']+)'/", (string) file_get_contents($file), $matches)) {
        continue;
    }
    foreach ($matches[1] as $path) {
        $routes->add('module_url_lint_' . $moduleRouteCount, new Symfony\Component\Routing\Route($path));
        $moduleRouteCount++;
    }
}

// Routes are compared segment by segment rather than as raw substrings, so a
// fragment may line up with a placeholder. An *id* placeholder only stands in
// for a number: without that, `{id}` would happily absorb the word "edit" and
// vouch for `users/edit`, which is the very typo this check exists to catch.
$routeSegments = [];
foreach ($routes->all() as $route) {
    $routeSegments[] = explode('/', trim($route->getPath(), '/'));
}

/** True when the fragment's segments appear consecutively in the route's. */
$segmentsMatch = static function (array $needle, array $haystack): bool {
    $limit = count($haystack) - count($needle);
    for ($offset = 0; $offset <= $limit; $offset++) {
        foreach ($needle as $i => $segment) {
            $candidate = $haystack[$offset + $i];
            $isPlaceholder = str_starts_with($candidate, '{') && str_ends_with($candidate, '}');
            if ($isPlaceholder) {
                // The first segment must land on a literal, or a fragment like
                // "/activate" would be vouched for by any free-form placeholder
                // such as {type} and nothing would ever be reported.
                $idLike = str_contains($candidate, 'id');
                if ($i === 0 || ($idLike && !ctype_digit($segment))) {
                    continue 2;
                }
                continue;
            }
            if ($candidate !== $segment) {
                continue 2;
            }
        }
        return true;
    }
    return false;
};

// Only specs and the helpers they call. playwright/tools holds maintenance
// scripts whose url handling is not a test assertion.
$specFiles = [];
foreach (['/playwright/tests', '/playwright/helpers'] as $dir) {
    if (!is_dir($root . $dir)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . $dir));
    foreach ($iterator as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.js')) {
            $specFiles[] = $file->getPathname();
        }
    }
}
sort($specFiles);

$failures = [];
$checked = 0;

foreach ($specFiles as $file) {
    $relative = substr($file, strlen($root) + 1);
    foreach (file($file, FILE_IGNORE_NEW_LINES) as $number => $line) {
        // A comment may quote a broken fragment on purpose to explain the bug.
        $trimmed = ltrim($line);
        if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')) {
            continue;
        }

        // Each entry is [fragment, isContainsMatch]. Only a "contains" test can be
        // tautological: a[href="/"] is the real home link, a[href*="/"] is every link.
        $found = [];

        // URL substring assertions: url.includes('x'), page.url().includes('x').
        if (preg_match_all('/(?:url|url\(\))\s*\.includes\(\s*([\'"`])(.*?)\1/i', $line, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $found[] = [$hit[2], true];
            }
        }

        // Attribute selectors matching a link or form target.
        if (preg_match_all('/(?:href|action)\s*([\^$*]?)=\s*"([^"]*)"/', $line, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $found[] = [$hit[2], $hit[1] === '*'];
            }
        }

        foreach ($found as [$raw, $isContains]) {
            if ($raw === '' || str_contains($raw, '${')) {
                continue;
            }

            // True of every URL, so any assertion resting on it can never fail.
            if ($isContains && in_array($raw, $tautologies, true)) {
                $failures[] = sprintf('%s:%d  %s  (matches every url)', $relative, $number + 1, $raw);
                continue;
            }

            // Only path-shaped fragments are checkable. A query string is built by
            // the app rather than the router, an absolute url is not a route at
            // all, and a bare word is as likely to be a css class as a path.
            if (!str_contains($raw, '/') || str_contains($raw, '=') || str_starts_with($raw, 'http')) {
                continue;
            }

            if (in_array($raw, $nonRoute, true)) {
                continue;
            }

            $checked++;

            $needle = array_values(array_filter(explode('/', trim($raw, '/')), static fn($s) => $s !== ''));
            if ($needle === []) {
                continue;
            }

            foreach ($routeSegments as $candidate) {
                if ($segmentsMatch($needle, $candidate)) {
                    continue 2;
                }
            }

            $failures[] = sprintf('%s:%d  %s  (no route contains this)', $relative, $number + 1, $raw);
        }
    }
}

printf(
    "Checked %d url fragments against %d routes (%d from modules).\n",
    $checked,
    $routes->count(),
    $moduleRouteCount
);

if ($failures !== []) {
    echo "\nUrl fragments that no route can produce:\n";
    foreach ($failures as $failure) {
        echo '  ' . $failure . "\n";
    }
    echo "\nFix the fragment, or add it to \$nonRoute if it comes from a query string.\n";
    exit(1);
}

echo "All url fragments resolve to a route.\n";
