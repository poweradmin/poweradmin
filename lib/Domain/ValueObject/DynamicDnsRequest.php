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

namespace Poweradmin\Domain\ValueObject;

use Symfony\Component\HttpFoundation\Request;

class DynamicDnsRequest
{
    public function __construct(
        private readonly string $username,
        private readonly string $password,
        private readonly string $hostname,
        private readonly string $ipv4,
        private readonly string $ipv6,
        private readonly bool $dualstackUpdate,
        private readonly string $userAgent
    ) {
    }

    public static function fromHttpRequest(Request $request): self
    {
        $username = $_SERVER['PHP_AUTH_USER'] ?? $request->get('username', '');
        $password = $_SERVER['PHP_AUTH_PW'] ?? $request->get('password', '');
        $hostname = $request->get('hostname', '');
        $ipv4 = $request->get('myip') ?? $request->get('ip', '');
        $ipv6 = $request->get('myip6') ?? $request->get('ip6', '');
        $dualstackUpdate = $request->get('dualstack_update') === '1';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        if ($ipv4 === 'whatismyip') {
            $ipv4 = $_SERVER['REMOTE_ADDR'] ?? '';
        }

        if ($ipv6 === 'whatismyip') {
            $ipv6 = $_SERVER['REMOTE_ADDR'] ?? '';
        }

        return new self(
            $username,
            $password,
            $hostname,
            $ipv4,
            $ipv6,
            $dualstackUpdate,
            $userAgent
        );
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function getHostname(): string
    {
        return $this->hostname;
    }

    public function getIpv4(): string
    {
        return $this->ipv4;
    }

    public function getIpv6(): string
    {
        return $this->ipv6;
    }

    public function isDualstackUpdate(): bool
    {
        return $this->dualstackUpdate;
    }

    public function getUserAgent(): string
    {
        return $this->userAgent;
    }

    public function hasUsername(): bool
    {
        return !empty($this->username);
    }

    public function hasUserAgent(): bool
    {
        return !empty($this->userAgent);
    }

    public function hasHostname(): bool
    {
        return !empty($this->hostname);
    }

    public function hasIpAddresses(): bool
    {
        return !empty($this->ipv4) || !empty($this->ipv6);
    }
}
