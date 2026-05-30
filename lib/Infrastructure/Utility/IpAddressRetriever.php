<?php

namespace Poweradmin\Infrastructure\Utility;

use Poweradmin\Domain\Service\DnsValidation\IPAddressValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

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
    private array $trustedProxies;

    public function __construct(array $server, ?IPAddressValidator $ipValidator = null, ?array $trustedProxies = null)
    {
        $this->server = $server;
        $this->ipValidator = $ipValidator ?? new IPAddressValidator();
        $this->trustedProxies = $trustedProxies ?? $this->loadTrustedProxiesFromConfig();
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
                if (!empty($this->server[$header])) {
                    $ips = array_values(array_filter(
                        array_map('trim', explode(',', $this->server[$header])),
                        function (string $ip) use ($remoteAddr): bool {
                            return $ip !== $remoteAddr
                                && ($this->ipValidator->isValidIPv4($ip) || $this->ipValidator->isValidIPv6($ip));
                        }
                    ));

                    if (!empty($ips)) {
                        return $ips[0];
                    }
                }
            }
        }

        if ($remoteAddr !== '' && ($this->ipValidator->isValidIPv4($remoteAddr) || $this->ipValidator->isValidIPv6($remoteAddr))) {
            return $remoteAddr;
        }

        return '';
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
        return $this->isPrivateOrReserved($ip) || $this->matchesTrustedProxy($ip);
    }

    /**
     * Match a peer address against the configured allowlist, which may contain
     * exact IPs, CIDR ranges (IPv4 or IPv6), or IPv4 wildcards (e.g. 203.0.113.*).
     */
    private function matchesTrustedProxy(string $ip): bool
    {
        foreach ($this->trustedProxies as $entry) {
            if (!is_string($entry) || $entry === '') {
                continue;
            }

            if ($this->ipsEqual($entry, $ip)) {
                return true;
            }

            if (str_contains($entry, '/')) {
                if ($this->ipMatchesCidr($ip, $entry)) {
                    return true;
                }
                continue;
            }

            if (str_contains($entry, '*')) {
                $pattern = '/^' . str_replace(['.', '*'], ['\\.', '[0-9]+'], $entry) . '$/';
                if (preg_match($pattern, $ip) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Compare two IP addresses by binary value so equivalent IPv6 forms
     * (compressed vs expanded) match regardless of textual representation.
     */
    private function ipsEqual(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }

        $aBin = @inet_pton($a);
        $bBin = @inet_pton($b);
        return $aBin !== false && $bBin !== false && $aBin === $bBin;
    }

    private function ipMatchesCidr(string $ip, string $cidr): bool
    {
        [$network, $prefix] = explode('/', $cidr, 2);
        if ($prefix === '' || !ctype_digit($prefix)) {
            return false;
        }

        $ipBin = @inet_pton($ip);
        $netBin = @inet_pton($network);
        if ($ipBin === false || $netBin === false || strlen($ipBin) !== strlen($netBin)) {
            return false;
        }

        $prefix = (int) $prefix;
        $maxBits = strlen($ipBin) * 8;
        if ($prefix < 0 || $prefix > $maxBits) {
            return false;
        }
        if ($prefix === 0) {
            return true;
        }

        $fullBytes = intdiv($prefix, 8);
        $remBits = $prefix % 8;

        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($netBin, 0, $fullBytes)) {
            return false;
        }
        if ($remBits === 0) {
            return true;
        }

        $mask = chr((0xFF << (8 - $remBits)) & 0xFF);
        return (ord($ipBin[$fullBytes]) & ord($mask)) === (ord($netBin[$fullBytes]) & ord($mask));
    }

    private function loadTrustedProxiesFromConfig(): array
    {
        $configured = ConfigurationManager::getInstance()->get('security', 'trusted_proxies', []);
        return is_array($configured) ? $configured : [];
    }
}
