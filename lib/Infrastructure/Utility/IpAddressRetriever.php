<?php

namespace Poweradmin\Infrastructure\Utility;

use Poweradmin\Domain\Service\DnsValidation\IPAddressValidator;

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

class IpAddressRetriever
{
    private array $server;
    private IPAddressValidator $ipValidator;

    public function __construct(array $server, ?IPAddressValidator $ipValidator = null)
    {
        $this->server = $server;
        $this->ipValidator = $ipValidator ?? new IPAddressValidator();
    }

    /**
     * Get the client IP address.
     *
     * Forwarded-IP headers (X-Forwarded-For, X-Real-IP) are only honored
     * when the immediate peer (REMOTE_ADDR) is a private/loopback address - i.e. a
     * reverse proxy on the same host or internal network. Direct-internet peers
     * cannot be trusted to send accurate headers, so their values are ignored to
     * prevent audit-log spoofing and per-IP rate-limit bypass.
     *
     * @return string
     */
    public function getClientIp(): string
    {
        $remoteAddr = $this->server['REMOTE_ADDR'] ?? '';

        if ($remoteAddr !== '' && $this->isPrivateOrReserved($remoteAddr)) {
            // No Client-IP: proxies rarely set it but pass a client's own through
            $proxyHeaders = [
                'HTTP_X_FORWARDED_FOR',
                'HTTP_X_REAL_IP',
            ];

            foreach ($proxyHeaders as $header) {
                if (!empty($this->server[$header])) {
                    $entries = array_values(array_filter(
                        array_map(fn(string $ip): string => self::withoutPort(trim($ip)), explode(',', $this->server[$header])),
                        fn(string $ip): bool => $ip !== '' && $ip !== $remoteAddr
                    ));
                    $last = $entries === [] ? null : $entries[count($entries) - 1];

                    if ($last === null) {
                        continue;
                    }
                    // The rightmost entry is the one the trusted peer recorded; a client can forge the rest. One that
                    // is not an address (filter_var takes the ::ffff: form) must not fall through to X-Real-IP
                    if (filter_var($last, FILTER_VALIDATE_IP) === false) {
                        break;
                    }
                    return $last;
                }
            }
        }

        if ($remoteAddr !== '' && ($this->ipValidator->isValidIPv4($remoteAddr) || $this->ipValidator->isValidIPv6($remoteAddr))) {
            return $remoteAddr;
        }

        return '';
    }

    /**
     * The address of an "a.b.c.d:port" or "[v6]:port" entry; anything else unchanged.
     */
    private static function withoutPort(string $entry): string
    {
        if (preg_match('/^\[([^\]]+)\](?::\d+)?$/', $entry, $m) === 1) {
            return $m[1];
        }
        if (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $entry, $m) === 1) {
            return $m[1];
        }

        return $entry;
    }

    private function isPrivateOrReserved(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }
}
