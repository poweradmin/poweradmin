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

namespace Poweradmin\Tests\Unit\Install;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\CsrfTokenService;
use PoweradminInstall\InstallSecurityService;
use Symfony\Component\HttpFoundation\Request;

class InstallSecurityServiceIpRangeTest extends TestCase
{
    private ?string $remoteAddr = null;
    private ?string $forwardedFor = null;

    protected function setUp(): void
    {
        $this->remoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;
        $this->forwardedFor = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->remoteAddr === null) {
            unset($_SERVER['REMOTE_ADDR']);
        } else {
            $_SERVER['REMOTE_ADDR'] = $this->remoteAddr;
        }
        if ($this->forwardedFor === null) {
            unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        } else {
            $_SERVER['HTTP_X_FORWARDED_FOR'] = $this->forwardedFor;
        }
    }

    public static function cases(): array
    {
        return [
            'IPv4 inside range' => ['192.0.2.10', '192.0.2.0/24', true],
            'IPv4 outside range' => ['198.51.100.10', '192.0.2.0/24', false],
            'IPv6 inside range' => ['2001:db8::1', '2001:db8::/32', true],
            'IPv6 outside range' => ['2001:db9::1', '2001:db8::/32', false],
            'IPv6 client against IPv4 range' => ['2001:db8::1', '192.0.2.0/24', false],
            'range without prefix length is a single address' => ['198.51.100.10', '192.0.2.1', false],
        ];
    }

    #[DataProvider('cases')]
    public function testAllowedRanges(string $clientIp, string $range, bool $allowed): void
    {
        $_SERVER['REMOTE_ADDR'] = $clientIp;
        $service = new InstallSecurityService(
            [
                'ip_access' => ['enabled' => true, 'allowed_ips' => [], 'allowed_ranges' => [$range]],
                'csrf' => ['enabled' => false],
            ],
            $this->createMock(CsrfTokenService::class)
        );

        $errors = $service->validateRequest(Request::create('/install/'));

        $this->assertSame($allowed, !isset($errors['ip']));
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function portedChains(): array
    {
        return [
            'forged entry before a ported one' => ['10.0.0.5, 203.0.113.7:51234', false],
            'forged entry before an unparsable one' => ['10.0.0.5, unknown', false],
            'ported real client' => ['198.51.100.99, 10.0.0.5:4444', true],
            'bracketed IPv6 real client' => ['198.51.100.99, [2001:db8::5]:4444', true],
            'IPv4-mapped entry from a dual-stack proxy' => ['10.0.0.5, ::ffff:203.0.113.7', false],
        ];
    }

    #[DataProvider('portedChains')]
    public function testAPortedEntryIsReadAsItsAddressAndAnUnparsableOneStopsTheWalk(string $chain, bool $allowed): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = $chain;
        $service = new InstallSecurityService(
            [
                'ip_access' => ['enabled' => true, 'allowed_ips' => ['10.0.0.5', '2001:db8::5'], 'trusted_proxies' => ['127.0.0.1']],
                'csrf' => ['enabled' => false],
            ],
            $this->createMock(CsrfTokenService::class)
        );

        $errors = $service->validateRequest(Request::create('/install/'));

        $this->assertSame($allowed, !isset($errors['ip']));
    }

    public function testAnUnparsableChainIsRefusedEvenWhenTheProxyItselfIsAllowed(): void
    {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.7, unknown';
        $service = new InstallSecurityService(
            [
                'ip_access' => ['enabled' => true, 'allowed_ips' => [], 'allowed_ranges' => ['10.0.0.0/8'], 'trusted_proxies' => ['10.0.0.1']],
                'csrf' => ['enabled' => false],
            ],
            $this->createMock(CsrfTokenService::class)
        );

        $this->assertTrue(isset($service->validateRequest(Request::create('/install/'))['ip']));
    }
}
