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


namespace unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\CsrfTokenService;
use PoweradminInstall\InstallSecurityService;
use Symfony\Component\HttpFoundation\Request;

/**
 * The installer IP allow-list must match ranges by address family and prefix.
 */
class InstallSecurityServiceIpRangeTest extends TestCase
{
    private mixed $savedRemoteAddr;

    protected function setUp(): void
    {
        $this->savedRemoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->savedRemoteAddr === null) {
            unset($_SERVER['REMOTE_ADDR']);
        } else {
            $_SERVER['REMOTE_ADDR'] = $this->savedRemoteAddr;
        }
    }

    public static function rangeProvider(): array
    {
        return [
            'IPv6 client outside IPv6 range' => ['2001:db8:ffff::1', '2001:db8:1::/48', false],
            'IPv6 client inside IPv6 range' => ['2001:db8:1::5', '2001:db8:1::/48', true],
            'IPv4 client against bare address, other host' => ['192.0.2.2', '192.0.2.1', false],
            'IPv4 client against bare address, same host' => ['192.0.2.1', '192.0.2.1', true],
            'IPv4 client outside IPv4 range' => ['198.51.100.1', '192.0.2.0/24', false],
            'IPv4 client inside IPv4 range' => ['192.0.2.77', '192.0.2.0/24', true],
            'IPv6 client against IPv4 range' => ['2001:db8::1', '192.0.2.0/24', false],
        ];
    }

    #[DataProvider('rangeProvider')]
    public function testAllowedRanges(string $clientIp, string $range, bool $allowed): void
    {
        $_SERVER['REMOTE_ADDR'] = $clientIp;

        $service = new InstallSecurityService([
            'csrf' => ['enabled' => false],
            'ip_access' => [
                'enabled' => true,
                'allowed_ips' => [],
                'allowed_ranges' => [$range],
                'trusted_proxies' => [],
            ],
            'password_policy' => [],
        ], new CsrfTokenService());

        $errors = $service->validateRequest(Request::create('/'));

        $this->assertSame($allowed, !array_key_exists('ip', $errors));
    }
}
