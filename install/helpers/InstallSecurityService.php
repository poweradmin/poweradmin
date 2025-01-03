<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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
use Symfony\Component\HttpFoundation\Request;

class InstallSecurityService
{
    private array $config;
    private CsrfTokenService $csrfTokenService;
    private const DEFAULT_IP = '0.0.0.0';

    public function __construct(array $config, CsrfTokenService $csrfTokenService)
    {
        $this->config = $config;
        $this->csrfTokenService = $csrfTokenService;
    }

    public function validateRequest(Request $request): array
    {
        $errors = [];

        if (!$this->checkIpAccess()) {
            return ['ip' => 'Access denied from your IP address'];
        }

        if ($this->config['csrf']['enabled'] && $request->isMethod('POST')) {
            $token = $request->get('install_token');
            if (empty($token)) {
                $errors['csrf'] = 'Security Token Missing: A required security token was not provided. Please refresh the page and try again.';
            } elseif (!$this->csrfTokenService->validateToken($token, 'install_token')) {
                $errors['csrf'] = 'Invalid Security Token: The provided token is invalid. Please refresh the page and try again.';
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

        if (in_array($clientIp, $this->config['ip_access']['allowed_ips'])) {
            return true;
        }

        foreach ($this->config['ip_access']['allowed_ranges'] as $range) {
            if ($this->ipInRange($clientIp, $range)) {
                return true;
            }
        }

        return false;
    }

    private function ipInRange(string $ip, string $range): bool
    {
        list($range, $netmask) = explode('/', $range, 2);
        $rangeDecimal = ip2long($range);
        $ipDecimal = ip2long($ip);
        $wildcardDecimal = pow(2, (32 - $netmask)) - 1;
        $netmaskDecimal = ~$wildcardDecimal;

        return ($ipDecimal & $netmaskDecimal) == ($rangeDecimal & $netmaskDecimal);
    }

    private function getClientIp(): string
    {

        $hasForwardedForHeader = isset($_SERVER['HTTP_X_FORWARDED_FOR']);
        $hasRemoteAddress = isset($_SERVER['REMOTE_ADDR']);

        if ($hasRemoteAddress && $hasForwardedForHeader) {
            $forwardedIps = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $clientIp = trim(end($forwardedIps));

            if (filter_var($clientIp, FILTER_VALIDATE_IP)) {
                return $clientIp;
            }
        }

        if ($hasRemoteAddress) {
            return filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP)
                ? $_SERVER['REMOTE_ADDR']
                : self::DEFAULT_IP;
        }

        return self::DEFAULT_IP;
    }
}
