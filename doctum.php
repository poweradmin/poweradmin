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

// Doctum configuration for the class reference (composer docs:reference). Source links
// point at develop; the docs site build overrides the output path through the env vars.

use Doctum\Doctum;
use Doctum\RemoteRepository\GitHubRemoteRepository;
use Symfony\Component\Finder\Finder;

$files = Finder::create()
    ->files()
    ->name('*.php')
    ->in(__DIR__ . '/lib');

require_once __DIR__ . '/lib/Version.php';

// The footer records what the pages were generated from, since the published
// site is rebuilt from develop on a schedule of its own.
$commit = !function_exists('shell_exec') ? '' : trim((string)shell_exec('git -C ' . escapeshellarg(__DIR__) . ' rev-parse --short HEAD 2>/dev/null'));
$footer = [
    'href' => $commit !== '' ? 'https://github.com/poweradmin/poweradmin/commit/' . $commit : '',
    'rel' => 'noreferrer',
    'target' => '_blank',
    'before_text' => sprintf('Poweradmin %s, develop branch,', Poweradmin\Version::VERSION),
    'link_text' => $commit !== '' ? 'commit ' . $commit . ',' : '',
    'after_text' => 'built ' . gmdate('Y-m-d H:i') . ' UTC.',
];

return new Doctum($files, [
    'title' => 'Poweradmin class reference',
    'versions' => 'develop',
    'theme' => 'poweradmin',
    'template_dirs' => [__DIR__ . '/.doctum/theme'],
    'footer_link' => $footer,
    'build_dir' => getenv('DOCTUM_BUILD_DIR') ?: __DIR__ . '/docs/reference',
    'cache_dir' => getenv('DOCTUM_CACHE_DIR') ?: __DIR__ . '/.doctum/cache',
    'source_dir' => __DIR__,
    'remote_repository' => new GitHubRemoteRepository('poweradmin/poweradmin', __DIR__),
    'default_opened_level' => 2,
]);
