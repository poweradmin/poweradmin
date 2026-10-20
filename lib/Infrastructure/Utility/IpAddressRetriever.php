<?php

namespace Poweradmin\Infrastructure\Utility;

use Poweradmin\Domain\Service\DnsValidation\IPAddressValidator;
use Poweradmin\Domain\Config\ConfigurationInterface;

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
 * Resolves the client IP from REMOTE_ADDR, honoring forwarded headers only from private or trusted proxy peers.
 */
class IpAddressRetriever
{
    private array $server;
    private IPAddressValidator $ipValidator;
    private TrustedProxyList $trustedProxies;

    /**
     * @param array<int, string> $trustedProxies Public peers whose forwarded headers are honoured (security.trusted_proxies)
     */
    public function __construct(array $server, ?IPAddressValidator $ipValidator = null, array $trustedProxies = [])
    {
        $this->server = $server;
        $this->ipValidator = $ipValidator ?? new IPAddressValidator();
        $this->trustedProxies = new TrustedProxyList($trustedProxies);
    }

    /**
     * Build with the trusted proxy list from security.trusted_proxies.
     */
    public static function fromConfig(array $server, ConfigurationInterface $config, ?IPAddressValidator $ipValidator = null): self
    {
        $configured = $config->get('security', 'trusted_proxies', []);

        return new self($server, $ipValidator, is_array($configured) ? $configured : []);
    }

    /**
     * Get the client IP address.
     *
     * Forwarded-IP headers (Client-IP, X-Forwarded-For, X-Real-IP) are only honored
     * when the immediate peer (REMOTE_ADDR) is a private/loopback address - i.e. a
     * reverse proxy on the same host or internal network - or an explicitly
     * configured trusted proxy (security.trusted_proxies). Direct-internet peers
     * cannot be trusted to send accurate headers, so their values are ignored to
     * prevent audit-log spoofing and per-IP rate-limit bypass.
     *
     * @return string
     */
    public function getClientIp(): string
    {
        $remoteAddr = $this->server['REMOTE_ADDR'] ?? '';

        if ($remoteAddr !== '' && $this->isTrustedPeer($remoteAddr)) {
            $proxyHeaders = [
                'HTTP_CLIENT_IP',
                'HTTP_X_FORWARDED_FOR',
                'HTTP_X_REAL_IP',
            ];

            foreach ($proxyHeaders as $header) {
                if (empty($this->server[$header])) {
                    continue;
                }

                $clientIp = $this->resolveForwardedClientIp((string) $this->server[$header], $remoteAddr);
                if ($clientIp !== null) {
                    return $clientIp;
                }
            }
        }

        if ($remoteAddr !== '' && ($this->ipValidator->isValidIPv4($remoteAddr) || $this->ipValidator->isValidIPv6($remoteAddr))) {
            return $remoteAddr;
        }

        return '';
    }

    /**
     * Resolve the real client IP from a forwarded-for header value.
     *
     * The header is an ordered chain (leftmost = original client, rightmost = the
     * hop closest to us). A client can spoof its address by sending a leftmost
     * value through a proxy that appends rather than replaces the header, so the
     * chain is walked from the right and only hops matching the configured
     * trusted-proxy list are skipped; the first remaining address is the real
     * client. Private/loopback ranges are NOT auto-skipped here - that allowance
     * applies only to the immediate peer (REMOTE_ADDR), since a chain entry can
     * be forged by a client on the internal network. When every hop is a
     * configured proxy, the leftmost (the originator) is returned.
     *
     * @return string|null Resolved client IP, or null when the header carries no usable address
     */
    private function resolveForwardedClientIp(string $headerValue, string $remoteAddr): ?string
    {
        $ips = array_values(array_filter(
            array_map('trim', explode(',', $headerValue)),
            function (string $ip) use ($remoteAddr): bool {
                return $ip !== $remoteAddr
                    && ($this->ipValidator->isValidIPv4($ip) || $this->ipValidator->isValidIPv6($ip));
            }
        ));

        if ($ips === []) {
            return null;
        }

        for ($i = count($ips) - 1; $i >= 0; $i--) {
            if (!$this->trustedProxies->matches($ips[$i])) {
                return $ips[$i];
            }
        }

        return $ips[0];
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

    /**
     * A peer is trusted to set forwarded headers when it is a private/loopback
     * address or matches an entry in the configured trusted-proxy allowlist.
     */
    private function isTrustedPeer(string $ip): bool
    {
        return $this->isPrivateOrReserved($ip) || $this->trustedProxies->matches($ip);
    }
}
