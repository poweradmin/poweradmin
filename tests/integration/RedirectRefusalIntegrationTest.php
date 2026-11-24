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
use Poweradmin\Application\Service\PowerdnsStatusService;
use Poweradmin\Domain\Error\ApiErrorException;
use Poweradmin\Infrastructure\Api\HttpClient;
use Poweradmin\Application\Service\OidcConfigurationService;
use Poweradmin\Application\Service\OidcService;
use Poweradmin\Application\Service\UserProvisioningService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\Logger;
use Poweradmin\Infrastructure\Database\PDOCommon;
use ReflectionMethod;
use ReflectionProperty;
use TestHelpers\StubHttpServer;

/**
 * Credentials sent to PowerDNS must not follow a redirect to another host.
 */
class RedirectRefusalIntegrationTest extends TestCase
{
    use StubHttpServer;

    private const API_KEY = 'fxiBmBFx7MITw5ECRMOr10ghlxGMvWZA';

    #[\PHPUnit\Framework\Attributes\DataProvider('redirectStatusProvider')]
    public function testRedirectIsRefusedForEveryMethodAndNotFollowed(int $status, string $method): void
    {
        [$target, $targetPort] = $this->listenOnFreePort();
        [$process, $port] = $this->startStubServer("HTTP/1.1 {$status} Redirect\r\nLocation: http://127.0.0.1:" . $targetPort . "/x\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");

        try {
            (new HttpClient('http://127.0.0.1:' . $port, self::API_KEY))->makeRequest($method, '/x', ['a' => 1]);
            $this->fail('Expected ApiErrorException');
        } catch (ApiErrorException $e) {
            $this->assertSame(0, $e->getCode());
            $this->assertStringContainsString('Redirects are refused', $e->getMessage());
            $this->assertStringContainsString((string) $status, $e->getMessage());
            $this->assertStringNotContainsString((string) $targetPort, $e->getMessage());
            $this->assertFalse(@stream_socket_accept($target, 0), 'the redirect target must never be contacted');
        } finally {
            fclose($target);
            foreach ([$process] as $p) {
                proc_terminate($p);
                proc_close($p);
            }
        }
    }

    public function testRedirectWithoutAReasonPhraseIsRefusedWithTheExplanation(): void
    {
        [$process, $port] = $this->startStubServer("HTTP/1.1 302\r\nLocation: http://127.0.0.1:9/x\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");

        try {
            (new HttpClient('http://127.0.0.1:' . $port, self::API_KEY))->makeRequest('GET', '/x');
            $this->fail('Expected ApiErrorException');
        } catch (ApiErrorException $e) {
            $this->assertStringContainsString('Redirects are refused', $e->getMessage());
        } finally {
            proc_terminate($process);
            proc_close($process);
        }
    }

    public static function redirectStatusProvider(): array
    {
        return [
            '301 GET' => [301, 'GET'],
            '302 PUT' => [302, 'PUT'],
            '307 PATCH' => [307, 'PATCH'],
            '308 POST' => [308, 'POST'],
        ];
    }

    public function testMetricsFetchRefusesARedirectAndNeverContactsTheTarget(): void
    {
        [$target, $targetPort] = $this->listenOnFreePort();
        [$process, $port] = $this->startStubServer("HTTP/1.1 302 Found\r\nLocation: http://127.0.0.1:" . $targetPort . "/metrics\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");

        try {
            $service = new PowerdnsStatusService();
            foreach (['apiUrl' => 'http://127.0.0.1:' . $port, 'webserverUsername' => 'user', 'webserverPassword' => 'pass'] as $name => $value) {
                $property = new ReflectionProperty($service, $name);
                $property->setValue($service, $value);
            }
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

    public function testOidcTokenRequestIsNotFollowedToARedirectTarget(): void
    {
        [$target, $targetPort] = $this->listenOnFreePort();
        [$process, $port] = $this->startStubServer("HTTP/1.1 307 Temporary Redirect\r\nLocation: http://127.0.0.1:" . $targetPort . "/token\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");

        try {
            $config = $this->createMock(ConfigurationManager::class);
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
                $this->createMock(Logger::class),
                $this->createMock(PDOCommon::class)
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
