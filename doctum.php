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

// Doctum configuration for the local class reference (composer docs:reference).
// Output and cache live under ignored paths; nothing here is published.

use Doctum\Doctum;
use Doctum\RemoteRepository\GitHubRemoteRepository;
use Symfony\Component\Finder\Finder;

$files = Finder::create()
    ->files()
    ->name('*.php')
    ->in(__DIR__ . '/lib');

return new Doctum($files, [
    'title' => 'Poweradmin class reference',
    'build_dir' => __DIR__ . '/docs/reference',
    'cache_dir' => __DIR__ . '/.doctum/cache',
    'source_dir' => __DIR__,
    'remote_repository' => new GitHubRemoteRepository('poweradmin/poweradmin', __DIR__),
    'default_opened_level' => 2,
]);
