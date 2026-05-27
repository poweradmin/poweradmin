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

namespace PoweradminInstall;

use Poweradmin\Application\Service\CsrfTokenService;
use Poweradmin\Domain\Service\SessionKeys;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;

class InstallSecurityService
{
    private array $config;
    private CsrfTokenService $csrfTokenService;
    private array $server;
    private const DEFAULT_IP = '0.0.0.0';

    public function __construct(array $config, CsrfTokenService $csrfTokenService, ?array $server = null)
    {
        $this->config = $config;
        $this->csrfTokenService = $csrfTokenService;
        $this->server = $server ?? $_SERVER;
    }

    public function validateRequest(Request $request): array
    {
        $errors = [];

        if (!$this->checkIpAccess()) {
            return ['ip' => 'Access denied from your IP address'];
        }

        if ($this->config['csrf']['enabled'] && $request->isMethod('POST')) {
            $token = $request->request->get('install_token');
            if (empty($token)) {
                $errors['csrf'] = 'Security Token Missing: A required security token was not provided. Please start the installation from the beginning.';
            } elseif (!$this->csrfTokenService->validateToken($token, SessionKeys::INSTALL_TOKEN)) {
                $errors['csrf'] = 'Invalid Security Token: The provided token is invalid or has expired. Please start the installation from the beginning.';
            }
        }

        return $errors;
    }

    private function checkIpAccess(): bool
    {
        if (!$this->config['ip_access']['enabled']) {
            return true;
        }

        $clientIp = $this->getClientIp();
        $allowed = array_merge(
            $this->config['ip_access']['allowed_ips'] ?? [],
            $this->config['ip_access']['allowed_ranges'] ?? []
        );

        return $allowed !== [] && IpUtils::checkIp($clientIp, $allowed);
    }

    private function getClientIp(): string
    {
        $remoteAddr = $this->server['REMOTE_ADDR'] ?? '';
        if ($remoteAddr === '' || filter_var($remoteAddr, FILTER_VALIDATE_IP) === false) {
            return self::DEFAULT_IP;
        }

        // Only entries explicitly listed in trusted_proxies are believed when reading
        // X-Forwarded-For. Private/loopback addresses are NOT auto-trusted: a peer on
        // the same LAN can be an attacker too, and a legitimate client could itself
        // hold a private address behind NAT/VPN. Without an operator-configured list,
        // REMOTE_ADDR is the only address that gates allowlist access.
        $trusted = $this->config['ip_access']['trusted_proxies'] ?? [];

        if ($trusted === [] || !IpUtils::checkIp($remoteAddr, $trusted) || empty($this->server['HTTP_X_FORWARDED_FOR'])) {
            return $remoteAddr;
        }

        // Walk the chain right-to-left peeling trusted hops; the first untrusted
        // address is the real client, matching Symfony's getClientIp() model.
        $forwarded = array_reverse(array_map('trim', explode(',', $this->server['HTTP_X_FORWARDED_FOR'])));
        foreach ($forwarded as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                continue;
            }
            if (!IpUtils::checkIp($ip, $trusted)) {
                return $ip;
            }
        }

        return $remoteAddr;
    }
}
