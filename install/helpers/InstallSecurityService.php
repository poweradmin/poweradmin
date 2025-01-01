<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2024 Poweradmin Development Team
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
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;

class InstallSecurityService
{
    private array $config;
    private CsrfTokenService $csrfTokenService;

    public function __construct(string $configPath, CsrfTokenService $csrfTokenService)
    {
        $this->config = $this->loadConfig($configPath);
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
                $errors['csrf'] = 'Security token is required';
            } elseif (!$this->csrfTokenService->validateToken($token, 'install_token')) {
                $errors['csrf'] = 'Invalid security token';
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
        $headers = [
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];

        foreach ($headers as $header) {
            if (isset($_SERVER[$header])) {
                foreach (explode(',', $_SERVER[$header]) as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP)) {
                        return $ip;
                    }
                }
            }
        }

        return '0.0.0.0';
    }

    private function loadConfig(string $configPath): array
    {
        if (!file_exists($configPath)) {
            throw new RuntimeException("Configuration file not found: $configPath");
        }

        $config = require $configPath;

        if (!is_array($config)) {
            throw new RuntimeException("Invalid configuration format");
        }

        return $config;
    }
}
