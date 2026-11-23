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

namespace Poweradmin\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\Backend\PowerdnsStatusService;
use Poweradmin\Infrastructure\Session\ArraySession;
use Poweradmin\Application\Service\Auth\AuthenticationService;
use Poweradmin\Application\Service\Auth\OidcConfigurationService;
use Poweradmin\Application\Service\Auth\OidcService;
use Poweradmin\Application\Service\Auth\UserProvisioningService;
use Poweradmin\Application\Service\Web\AuditService;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Service\Auth\MfaService;
use Psr\Log\NullLogger;
use ReflectionMethod;
use TestHelpers\FakeConfiguration;
use TestHelpers\StubHttpServer;

/**
 * The webserver Basic credentials must not follow a redirect to another host.
 */
class RedirectRefusalIntegrationTest extends TestCase
{
    use StubHttpServer;

    public function testMetricsFetchRefusesARedirectAndNeverContactsTheTarget(): void
    {
        [$target, $targetPort] = $this->listenOnFreePort();
        [$process, $port] = $this->startStubServer("HTTP/1.1 302 Found\r\nLocation: http://127.0.0.1:" . $targetPort . "/metrics\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");

        try {
            $service = new PowerdnsStatusService(new FakeConfiguration(['pdns_api' => [
                'url' => 'http://127.0.0.1:' . $port,
                'key' => 'secret',
                'webserver_username' => 'user',
                'webserver_password' => 'pass',
                'timeout' => 3,
            ]]), new ArraySession());
            $fetch = new ReflectionMethod($service, 'fetchMetricsWithAuth');

            $this->assertFalse($fetch->invoke($service, 'http://127.0.0.1:' . $port . '/metrics'));
            $this->assertFalse(@stream_socket_accept($target, 0), 'the redirect target must never be contacted');
        } finally {
            fclose($target);
            foreach ([$process] as $p) {
                proc_terminate($p);
                proc_close($p);
            }
        }
    }

    public function testMetricsFetchIgnoresAnOversizedBody(): void
    {
        $body = str_repeat('# HELP x y' . "\n", 20);
        [$process, $port] = $this->startStubServer("HTTP/1.1 200 OK\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);

        try {
            $service = new class (new FakeConfiguration(['pdns_api' => [
                'url' => 'http://127.0.0.1:' . $port,
                'key' => 'secret',
                'timeout' => 3,
            ]]), new ArraySession()) extends PowerdnsStatusService {
                protected function maxMetricsBytes(): int
                {
                    return 50;
                }
            };

            $this->assertFalse((new ReflectionMethod($service, 'fetchMetricsWithAuth'))->invoke($service, 'http://127.0.0.1:' . $port . '/metrics'));
        } finally {
            proc_terminate($process);
            proc_close($process);
        }
    }

    public function testOidcTokenRequestIsNotFollowedToARedirectTarget(): void
    {
        [$target, $targetPort] = $this->listenOnFreePort();
        [$process, $port] = $this->startStubServer("HTTP/1.1 307 Temporary Redirect\r\nLocation: http://127.0.0.1:" . $targetPort . "/token\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");

        try {
            $config = $this->createMock(ConfigurationInterface::class);
            $config->method('get')->willReturnCallback(
                fn($group, $key, $default = null) => ['interface.application_url' => 'https://dns.example.com'][$group . '.' . $key] ?? $default
            );
            $oidcConfig = $this->createMock(OidcConfigurationService::class);
            $oidcConfig->method('getProviderConfig')->willReturn([
                'client_id' => 'id',
                'client_secret' => 'client-secret-value',
                'authorize_url' => 'http://127.0.0.1:' . $port . '/authorize',
                'token_url' => 'http://127.0.0.1:' . $port . '/token',
                'userinfo_url' => 'http://127.0.0.1:' . $port . '/userinfo',
            ]);
            $service = new OidcService(
                $config,
                $oidcConfig,
                $this->createMock(UserProvisioningService::class),
                new NullLogger(),
                $this->createMock(AuthenticationService::class),
                $this->createMock(AuditService::class),
                $this->createMock(MfaService::class),
                new ArraySession()
            );
            $provider = (new ReflectionMethod($service, 'createProvider'))->invoke($service, 'test');
            // Keep the client's redirect setting, add a timeout so a followed redirect fails fast
            $provider->setHttpClient(new \GuzzleHttp\Client([
                'allow_redirects' => $provider->getHttpClient()->getConfig('allow_redirects'),
                'timeout' => 3,
            ]));

            try {
                $provider->getAccessToken('authorization_code', ['code' => 'abc']);
                $this->fail('Expected the unfollowed redirect to fail the token exchange');
            } catch (\Throwable $e) {
                $this->assertStringNotContainsString('client-secret-value', $e->getMessage());
            }
            $this->assertFalse(@stream_socket_accept($target, 0), 'the redirect target must never be contacted');
        } finally {
            fclose($target);
            proc_terminate($process);
            proc_close($process);
        }
    }
}
