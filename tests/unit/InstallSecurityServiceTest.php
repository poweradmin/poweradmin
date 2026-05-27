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

namespace Poweradmin\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\CsrfTokenService;
use Poweradmin\Domain\Service\SessionKeys;
use PoweradminInstall\InstallSecurityService;
use Symfony\Component\HttpFoundation\Request;

class InstallSecurityServiceTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists('PoweradminInstall\InstallSecurityService')) {
            $this->markTestSkipped('Install folder not present - InstallSecurityService class not available');
        }

        if (!isset($_SESSION)) {
            $_SESSION = [];
        }
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function buildService(array $config, array $server): InstallSecurityService
    {
        return new InstallSecurityService(
            $config,
            new CsrfTokenService(),
            $server
        );
    }

    private function baseConfig(array $overrides = []): array
    {
        $defaults = [
            'csrf' => ['enabled' => false],
            'ip_access' => [
                'enabled' => false,
                'allowed_ips' => [],
                'allowed_ranges' => [],
                'trusted_proxies' => [],
            ],
        ];

        return array_replace_recursive($defaults, $overrides);
    }

    public function testIpAllowlistDisabledAllowsAnyRequest(): void
    {
        $service = $this->buildService(
            $this->baseConfig(['ip_access' => ['enabled' => false]]),
            ['REMOTE_ADDR' => '198.51.100.99']
        );

        $errors = $service->validateRequest(Request::create('/install/'));
        $this->assertSame([], $errors);
    }

    public function testIpAllowlistAllowsExactMatchFromDirectClient(): void
    {
        $service = $this->buildService(
            $this->baseConfig([
                'ip_access' => [
                    'enabled' => true,
                    'allowed_ips' => ['127.0.0.1'],
                ],
            ]),
            ['REMOTE_ADDR' => '127.0.0.1']
        );

        $errors = $service->validateRequest(Request::create('/install/'));
        $this->assertSame([], $errors);
    }

    public function testIpAllowlistDeniesRequestFromOutsideAllowedIp(): void
    {
        $service = $this->buildService(
            $this->baseConfig([
                'ip_access' => [
                    'enabled' => true,
                    'allowed_ips' => ['127.0.0.1'],
                ],
            ]),
            ['REMOTE_ADDR' => '203.0.113.10']
        );

        $errors = $service->validateRequest(Request::create('/install/'));
        $this->assertArrayHasKey('ip', $errors);
    }

    public function testIpAllowlistAllowsCidrRangeMatch(): void
    {
        $service = $this->buildService(
            $this->baseConfig([
                'ip_access' => [
                    'enabled' => true,
                    'allowed_ranges' => ['10.0.0.0/8'],
                ],
            ]),
            ['REMOTE_ADDR' => '10.5.6.7']
        );

        $errors = $service->validateRequest(Request::create('/install/'));
        $this->assertSame([], $errors);
    }

    /**
     * Regression test for X-Forwarded-For trust bypass.
     *
     * Before the fix, getClientIp() returned the X-Forwarded-For value regardless of
     * which peer sent it, letting any internet attacker spoof their way past the
     * IP allowlist by claiming to be in the allowed range.
     */
    public function testIpAllowlistRejectsSpoofedXForwardedForFromPublicClient(): void
    {
        $service = $this->buildService(
            $this->baseConfig([
                'ip_access' => [
                    'enabled' => true,
                    'allowed_ips' => ['10.0.0.5'],
                ],
            ]),
            [
                'REMOTE_ADDR' => '203.0.113.10',
                'HTTP_X_FORWARDED_FOR' => '10.0.0.5',
            ]
        );

        $errors = $service->validateRequest(Request::create('/install/'));
        $this->assertArrayHasKey('ip', $errors, 'Forwarded-For header from public peer must not be trusted');
    }

    public function testIpAllowlistHonorsXForwardedForFromLoopbackProxy(): void
    {
        $service = $this->buildService(
            $this->baseConfig([
                'ip_access' => [
                    'enabled' => true,
                    'allowed_ips' => ['198.51.100.42'],
                    'trusted_proxies' => ['127.0.0.1'],
                ],
            ]),
            [
                'REMOTE_ADDR' => '127.0.0.1',
                'HTTP_X_FORWARDED_FOR' => '198.51.100.42',
            ]
        );

        $errors = $service->validateRequest(Request::create('/install/'));
        $this->assertSame([], $errors);
    }

    public function testIpAllowlistIgnoresXForwardedForFromUnconfiguredLoopbackProxy(): void
    {
        // Regression test: private/loopback peers are NOT auto-trusted. Without an
        // explicit trusted_proxies entry, XFF is ignored even from REMOTE_ADDR=127.0.0.1,
        // so a LAN attacker who happens to reach the installer cannot spoof.
        $service = $this->buildService(
            $this->baseConfig([
                'ip_access' => [
                    'enabled' => true,
                    'allowed_ips' => ['198.51.100.42'],
                ],
            ]),
            [
                'REMOTE_ADDR' => '127.0.0.1',
                'HTTP_X_FORWARDED_FOR' => '198.51.100.42',
            ]
        );

        $errors = $service->validateRequest(Request::create('/install/'));
        $this->assertArrayHasKey('ip', $errors, 'Private REMOTE_ADDR must not auto-trust XFF without explicit trusted_proxies');
    }

    /**
     * Regression test for X-Forwarded-For prefix spoofing behind a trusted proxy.
     *
     * Nginx with $proxy_add_x_forwarded_for APPENDS the real client IP to whatever
     * the client sent. So an attacker from 5.6.7.8 sending `X-Forwarded-For: 10.0.0.5`
     * arrives at PHP as `X-Forwarded-For: 10.0.0.5, 5.6.7.8`. The rightmost entry
     * is what the trusted proxy contributed; leftmost entries are attacker-controlled
     * and must not be used for allowlist decisions.
     */
    public function testIpAllowlistRejectsSpoofedXForwardedForPrefixBehindTrustedProxy(): void
    {
        $service = $this->buildService(
            $this->baseConfig([
                'ip_access' => [
                    'enabled' => true,
                    'allowed_ips' => ['10.0.0.5'],
                ],
            ]),
            [
                'REMOTE_ADDR' => '127.0.0.1',
                'HTTP_X_FORWARDED_FOR' => '10.0.0.5, 5.6.7.8',
            ]
        );

        $errors = $service->validateRequest(Request::create('/install/'));
        $this->assertArrayHasKey('ip', $errors, 'Leftmost X-Forwarded-For entry is client-controllable and must not gate access');
    }

    public function testIpAllowlistHonorsXForwardedForFromConfiguredTrustedProxy(): void
    {
        $service = $this->buildService(
            $this->baseConfig([
                'ip_access' => [
                    'enabled' => true,
                    'allowed_ips' => ['198.51.100.42'],
                    'trusted_proxies' => ['203.0.113.50'],
                ],
            ]),
            [
                'REMOTE_ADDR' => '203.0.113.50',
                'HTTP_X_FORWARDED_FOR' => '198.51.100.42',
            ]
        );

        $errors = $service->validateRequest(Request::create('/install/'));
        $this->assertSame([], $errors);
    }

    public function testIpAllowlistHonorsXForwardedForFromCidrTrustedProxy(): void
    {
        $service = $this->buildService(
            $this->baseConfig([
                'ip_access' => [
                    'enabled' => true,
                    'allowed_ips' => ['198.51.100.42'],
                    'trusted_proxies' => ['203.0.113.0/24'],
                ],
            ]),
            [
                'REMOTE_ADDR' => '203.0.113.99',
                'HTTP_X_FORWARDED_FOR' => '198.51.100.42',
            ]
        );

        $errors = $service->validateRequest(Request::create('/install/'));
        $this->assertSame([], $errors);
    }

    public function testIpAllowlistPeelsMultiHopTrustedProxyChain(): void
    {
        // Chain: real_client (198.51.100.42) → CDN (203.0.113.50) → loopback nginx → PHP
        // XFF arrives as "198.51.100.42, 203.0.113.50". With BOTH the CDN and the
        // local nginx listed as trusted, the right-to-left walk peels them and
        // lands on the real client.
        $service = $this->buildService(
            $this->baseConfig([
                'ip_access' => [
                    'enabled' => true,
                    'allowed_ips' => ['198.51.100.42'],
                    'trusted_proxies' => ['127.0.0.1', '203.0.113.50'],
                ],
            ]),
            [
                'REMOTE_ADDR' => '127.0.0.1',
                'HTTP_X_FORWARDED_FOR' => '198.51.100.42, 203.0.113.50',
            ]
        );

        $errors = $service->validateRequest(Request::create('/install/'));
        $this->assertSame([], $errors);
    }

    public function testIpAllowlistRejectsSpoofedClientInMultiHopChain(): void
    {
        // Attacker (5.6.7.8) sends "X-Forwarded-For: 198.51.100.42" claiming to be
        // the allowed client. The CDN appends 5.6.7.8 and nginx appends the CDN,
        // so PHP sees "198.51.100.42, 5.6.7.8, 203.0.113.50". Peeling trusted hops
        // from the right stops at 5.6.7.8 (untrusted) - the real attacker - which
        // is correctly rejected.
        $service = $this->buildService(
            $this->baseConfig([
                'ip_access' => [
                    'enabled' => true,
                    'allowed_ips' => ['198.51.100.42'],
                    'trusted_proxies' => ['127.0.0.1', '203.0.113.50'],
                ],
            ]),
            [
                'REMOTE_ADDR' => '127.0.0.1',
                'HTTP_X_FORWARDED_FOR' => '198.51.100.42, 5.6.7.8, 203.0.113.50',
            ]
        );

        $errors = $service->validateRequest(Request::create('/install/'));
        $this->assertArrayHasKey('ip', $errors, 'Right-to-left peel must stop at the first untrusted hop, not believe leftmost spoofed entries');
    }

    public function testIpAllowlistDeniesLanAttackerSpoofingXForwardedFor(): void
    {
        // Regression test for codex finding: an attacker on the internal LAN
        // (REMOTE_ADDR=10.0.0.20) must not be auto-trusted just because their IP
        // is in RFC1918. Without a trusted_proxies entry covering 10.0.0.20, the
        // spoofed XFF is ignored and the LAN-attacker REMOTE_ADDR is what gates access.
        $service = $this->buildService(
            $this->baseConfig([
                'ip_access' => [
                    'enabled' => true,
                    'allowed_ips' => ['198.51.100.42'],
                ],
            ]),
            [
                'REMOTE_ADDR' => '10.0.0.20',
                'HTTP_X_FORWARDED_FOR' => '198.51.100.42',
            ]
        );

        $errors = $service->validateRequest(Request::create('/install/'));
        $this->assertArrayHasKey('ip', $errors, 'LAN peer must not be implicitly trusted to set XFF');
    }

    public function testIpAllowlistAllowsRealPrivateClientBehindTrustedLoopbackProxy(): void
    {
        // Regression test for codex finding: when a legitimate client legitimately
        // sits in private space (e.g. corporate VPN), the loop must not strip them
        // as if they were a proxy. Only configured trusted_proxies entries get peeled.
        $service = $this->buildService(
            $this->baseConfig([
                'ip_access' => [
                    'enabled' => true,
                    'allowed_ranges' => ['10.0.0.0/8'],
                    'trusted_proxies' => ['127.0.0.1'],
                ],
            ]),
            [
                'REMOTE_ADDR' => '127.0.0.1',
                'HTTP_X_FORWARDED_FOR' => '10.5.6.7',
            ]
        );

        $errors = $service->validateRequest(Request::create('/install/'));
        $this->assertSame([], $errors);
    }

    public function testIpAllowlistHandlesIpv6TrustedProxyCidr(): void
    {
        $service = $this->buildService(
            $this->baseConfig([
                'ip_access' => [
                    'enabled' => true,
                    'allowed_ips' => ['2001:db8:cafe::1'],
                    'trusted_proxies' => ['2001:db8:abcd::/48'],
                ],
            ]),
            [
                'REMOTE_ADDR' => '2001:db8:abcd::99',
                'HTTP_X_FORWARDED_FOR' => '2001:db8:cafe::1',
            ]
        );

        $errors = $service->validateRequest(Request::create('/install/'));
        $this->assertSame([], $errors);
    }

    public function testIpAllowlistHandlesIpv6AllowedRange(): void
    {
        $service = $this->buildService(
            $this->baseConfig([
                'ip_access' => [
                    'enabled' => true,
                    'allowed_ranges' => ['2001:db8::/32'],
                ],
            ]),
            ['REMOTE_ADDR' => '2001:db8:1234::5']
        );

        $errors = $service->validateRequest(Request::create('/install/'));
        $this->assertSame([], $errors);
    }

    public function testIpAllowlistIgnoresXForwardedForFromUnlistedPublicProxy(): void
    {
        $service = $this->buildService(
            $this->baseConfig([
                'ip_access' => [
                    'enabled' => true,
                    'allowed_ips' => ['198.51.100.42'],
                    'trusted_proxies' => ['203.0.113.50'],
                ],
            ]),
            [
                'REMOTE_ADDR' => '198.18.0.1',
                'HTTP_X_FORWARDED_FOR' => '198.51.100.42',
            ]
        );

        $errors = $service->validateRequest(Request::create('/install/'));
        $this->assertArrayHasKey('ip', $errors, 'XFF from a peer not listed in trusted_proxies must be ignored');
    }

    public function testCsrfDisabledSkipsTokenCheckOnPost(): void
    {
        $service = $this->buildService(
            $this->baseConfig(['csrf' => ['enabled' => false]]),
            ['REMOTE_ADDR' => '127.0.0.1']
        );

        $errors = $service->validateRequest(Request::create('/install/', 'POST'));
        $this->assertSame([], $errors);
    }

    public function testCsrfReportsMissingTokenOnPost(): void
    {
        $service = $this->buildService(
            $this->baseConfig(['csrf' => ['enabled' => true]]),
            ['REMOTE_ADDR' => '127.0.0.1']
        );

        $errors = $service->validateRequest(Request::create('/install/', 'POST'));
        $this->assertArrayHasKey('csrf', $errors);
    }

    public function testCsrfReportsInvalidTokenOnPost(): void
    {
        $_SESSION[SessionKeys::INSTALL_TOKEN] = 'correct-token';

        $service = $this->buildService(
            $this->baseConfig(['csrf' => ['enabled' => true]]),
            ['REMOTE_ADDR' => '127.0.0.1']
        );

        $request = Request::create('/install/', 'POST', ['install_token' => 'wrong-token']);
        $errors = $service->validateRequest($request);
        $this->assertArrayHasKey('csrf', $errors);
    }

    public function testCsrfAcceptsValidTokenOnPost(): void
    {
        $_SESSION[SessionKeys::INSTALL_TOKEN] = 'correct-token';

        $service = $this->buildService(
            $this->baseConfig(['csrf' => ['enabled' => true]]),
            ['REMOTE_ADDR' => '127.0.0.1']
        );

        $request = Request::create('/install/', 'POST', ['install_token' => 'correct-token']);
        $errors = $service->validateRequest($request);
        $this->assertSame([], $errors);
    }

    public function testCsrfNotEnforcedOnGet(): void
    {
        $_SESSION[SessionKeys::INSTALL_TOKEN] = 'correct-token';

        $service = $this->buildService(
            $this->baseConfig(['csrf' => ['enabled' => true]]),
            ['REMOTE_ADDR' => '127.0.0.1']
        );

        $errors = $service->validateRequest(Request::create('/install/'));
        $this->assertSame([], $errors);
    }
}
