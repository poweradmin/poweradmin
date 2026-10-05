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

use Poweradmin\Domain\Config\ConfigurationInterface;

/**
 * The apex SOA a zone gets when no template supplies one, built from the dns.* settings.
 */
final class DefaultSoaBuilder
{
    public function __construct(private readonly ConfigurationInterface $config)
    {
    }

    public function content(): string
    {
        $ns1 = $this->config->get('dns', 'ns1');
        $hm = $this->config->get('dns', 'hostmaster');
        $timers = $this->timers();
        $serial = date("Ymd") . "00";

        return "$ns1 $hm $serial {$timers['refresh']} {$timers['retry']} {$timers['expire']} {$timers['minimum']}";
    }

    /**
     * @return array{refresh: mixed, retry: mixed, expire: mixed, minimum: mixed}
     */
    public function timers(): array
    {
        return [
            'refresh' => $this->config->get('dns', 'soa_refresh', 28800),
            'retry' => $this->config->get('dns', 'soa_retry', 7200),
            'expire' => $this->config->get('dns', 'soa_expire', 604800),
            'minimum' => $this->config->get('dns', 'soa_minimum', 86400),
        ];
    }

    public function ttl(): int
    {
        return (int)$this->config->get('dns', 'ttl');
    }
}
