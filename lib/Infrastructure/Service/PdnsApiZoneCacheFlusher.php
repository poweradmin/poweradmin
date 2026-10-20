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

use Poweradmin\Domain\Port\ZoneCacheFlusherInterface;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * SQL backend with a PowerDNS API configured: flushes through the API, which
 * also adds a new zone to the PowerDNS zone cache (PowerDNS 4.6 and later).
 */
final class PdnsApiZoneCacheFlusher implements ZoneCacheFlusherInterface
{
    private bool $unavailable = false;

    public function __construct(
        private readonly PowerdnsApiClient $client,
        private readonly LoggerInterface $logger
    ) {
    }

    public function flushZone(string $zoneName): void
    {
        // One failure is enough per request: a loop of writes must not wait out the timeout each time
        if ($this->unavailable) {
            return;
        }
        try {
            $flushed = $this->client->flushZoneCache($zoneName);
            $error = 'the API did not answer 200';
        } catch (Throwable $e) {
            $flushed = false;
            $error = $e->getMessage();
        }
        if (!$flushed) {
            $this->unavailable = true;
            $this->logger->warning('PowerDNS cache flush failed for {zone}, skipping it for the rest of this request: {error}', ['zone' => $zoneName, 'error' => $error]);
        }
    }
}
