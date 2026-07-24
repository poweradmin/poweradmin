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

namespace Poweradmin\Infrastructure\Service;

use Psr\Log\LoggerInterface;

class TemplateCacheResolver
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * Resolves the compiled-template cache directory, creating it if needed.
     * Returns null when the directory cannot be used - callers then render
     * uncached, so a misconfigured path degrades instead of failing.
     *
     * @param string $configuredPath Configured directory; empty selects var/cache/twig under the app root
     * @return string|null The usable cache directory, or null to run uncached
     */
    public function resolve(string $configuredPath): ?string
    {
        $cachePath = $configuredPath !== '' ? $configuredPath : dirname(__DIR__, 3) . '/var/cache/twig';

        // The !is_dir recheck covers concurrent first requests racing mkdir
        if (!is_dir($cachePath) && !@mkdir($cachePath, 0770, true) && !is_dir($cachePath)) {
            $this->logger->warning('Template cache directory {path} could not be created; running without template cache.', ['path' => $cachePath]);
            return null;
        }
        if (!is_writable($cachePath)) {
            $this->logger->warning('Template cache directory {path} is not writable; running without template cache.', ['path' => $cachePath]);
            return null;
        }

        return $cachePath;
    }
}
