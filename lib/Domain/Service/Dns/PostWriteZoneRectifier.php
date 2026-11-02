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

use Closure;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Port\ZoneRectifierInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Rectifies a zone after records were written, so the NSEC/NSEC3 ordering data
 * of a signed zone follows nodes being added or removed.
 */
final class PostWriteZoneRectifier
{
    /** A failure this slow means PowerDNS timed out or stalled, not that it refused one zone. */
    private const SLOW_FAILURE_SECONDS = 3.0;

    private ?ZoneRectifierInterface $rectifier = null;
    private bool $stalled = false;
    private Closure $clock;

    /**
     * @param Closure(): ZoneRectifierInterface $rectifierFactory Built on first use, so DNSSEC-disabled installs never construct one
     * @param (Closure(): float)|null $clock Seconds, monotonic; defaults to hrtime
     */
    public function __construct(
        private readonly ConfigurationInterface $config,
        private readonly Closure $rectifierFactory,
        private readonly LoggerInterface $logger,
        ?Closure $clock = null
    ) {
        $this->clock = $clock ?? static fn(): float => hrtime(true) / 1e9;
    }

    /**
     * The write is already committed, so a failure here is logged, not raised. After one
     * slow failure the rest of the request skips rectify, so a bulk write cannot stall per zone;
     * a slow success never trips it.
     */
    public function rectify(string $zoneName): void
    {
        if (!$this->config->get('dnssec', 'enabled')) {
            return;
        }
        if ($this->stalled) {
            $this->logger->warning('Zone rectify skipped for {zone}: an earlier rectify in this request failed after timing out or stalling', ['zone' => $zoneName]);
            return;
        }
        $started = ($this->clock)();
        try {
            $this->rectifier ??= ($this->rectifierFactory)();
            if ($this->rectifier->rectifyZone($zoneName)) {
                return;
            }
            $this->logger->warning('Zone rectify refused for {zone}', ['zone' => $zoneName]);
        } catch (Throwable $e) {
            $this->logger->warning('Zone rectify failed for {zone}: {error}', ['zone' => $zoneName, 'error' => $e->getMessage()]);
        }
        $this->stalled = ($this->clock)() - $started >= self::SLOW_FAILURE_SECONDS;
    }
}
