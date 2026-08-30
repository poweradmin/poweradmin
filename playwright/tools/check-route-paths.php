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
 * Fails when an E2E spec navigates to a path that is not a route.
 *
 * Such a spec still "passes" whenever its assertions accept 404 body text, so
 * the mistake is invisible at runtime. Matching happens through the same
 * Symfony matcher the application uses, plus the module routes the registry
 * adds at boot.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Symfony\Component\Config\FileLocator;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Loader\YamlFileLoader;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;

$root = dirname(__DIR__, 2);

// Paths a spec visits on purpose to assert the 404 behaviour itself.
$deliberate404 = [
    '/fake-page-test',
    '/invalid-page',
    '/nonexistent-page',
    '/nonexistent-page-12345',
    '/nonexistent-page-xyz',
    '/nonexistent-test',
    '/nonexistent-xyz',
    '/test-nonexistent',
    // Reflected-XSS probe: asserts the payload is escaped on the 404 page.
    '/%3Cscript%3Ealert(1)%3C/script%3E',
];

$routes = (new YamlFileLoader(new FileLocator([$root . '/lib/Application/Config'])))->load('routes.yaml');

// Modules register their routes after routes.yaml, so a spec may legitimately
// visit one. They are plain literals in each module class.
$moduleFiles = glob($root . '/lib/Module/*/*Module.php') ?: [];
$moduleRouteCount = 0;
foreach ($moduleFiles as $file) {
    if (!preg_match_all("/'path'\s*=>\s*'([^']+)'/", (string) file_get_contents($file), $matches)) {
        continue;
    }
    foreach ($matches[1] as $i => $path) {
        $routes->add('module_lint_' . basename($file, '.php') . '_' . $i, new Route($path));
        $moduleRouteCount++;
    }
}

$specFiles = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/playwright'));
foreach ($iterator as $file) {
    if ($file->isFile() && str_ends_with($file->getFilename(), '.js')) {
        $specFiles[] = $file->getPathname();
    }
}
sort($specFiles);

$context = new RequestContext();
$context->setMethod('GET');
$matcher = new UrlMatcher($routes, $context);

$failures = [];
$checked = 0;
$skippedDynamic = 0;

foreach ($specFiles as $file) {
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    foreach ($lines as $number => $line) {
        if (!preg_match_all('/\.goto\(\s*([\'"`])(.*?)\1/', $line, $matches, PREG_SET_ORDER)) {
            continue;
        }

        foreach ($matches as $match) {
            $raw = $match[2];

            // Interpolated paths depend on runtime ids and cannot be checked here.
            if (str_contains($raw, '${')) {
                $skippedDynamic++;
                continue;
            }

            if (!str_starts_with($raw, '/')) {
                continue;
            }

            $path = strtok($raw, '?');
            $path = strtok($path, '#');

            if (in_array($path, $deliberate404, true)) {
                continue;
            }

            $checked++;

            try {
                $matcher->match($path);
            } catch (MethodNotAllowedException) {
                // The route exists; only the verb is wrong, which is not this check's job.
            } catch (ResourceNotFoundException) {
                $relative = substr($file, strlen($root) + 1);
                $failures[] = sprintf('%s:%d  %s', $relative, $number + 1, $raw);
            }
        }
    }
}

printf(
    "Checked %d navigation paths against %d routes (%d from modules); skipped %d interpolated.\n",
    $checked,
    $routes->count(),
    $moduleRouteCount,
    $skippedDynamic
);

if ($failures !== []) {
    echo "\nERROR: these specs navigate to paths that are not routes. A spec whose\n";
    echo "assertions accept 404 text will pass anyway, so fix the path or add it to\n";
    echo "the deliberate-404 list in " . substr(__FILE__, strlen($root) + 1) . ".\n\n";
    foreach ($failures as $failure) {
        echo '  ' . $failure . "\n";
    }
    exit(1);
}

echo "All navigation paths resolve to a route.\n";
