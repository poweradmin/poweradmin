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
use Poweradmin\Application\Service\OidcConfigurationService;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Provider\ResourceOwnerInterface;
use League\OAuth2\Client\Token\AccessToken;
use Poweradmin\Application\Service\OidcService;
use Poweradmin\Domain\ValueObject\OidcUserInfo;
use Poweradmin\Application\Service\UserProvisioningService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use PDO;
use Poweradmin\Infrastructure\Logger\Logger;
use ReflectionMethod;

class OidcServiceIdTokenTest extends TestCase
{
    private OidcService $service;
    private ReflectionMethod $decodeMethod;

    protected function setUp(): void
    {
        $configManager = $this->createMock(ConfigurationManager::class);
        $configManager->method('get')->willReturn(null);

        $oidcConfigService = $this->createMock(OidcConfigurationService::class);
        $userProvisioningService = $this->createMock(UserProvisioningService::class);
        $logger = $this->createMock(Logger::class);
        $db = $this->createMock(PDO::class);

        $this->service = new OidcService(
            $configManager,
            $oidcConfigService,
            $userProvisioningService,
            $logger,
            $db
        );

        $this->decodeMethod = new ReflectionMethod(OidcService::class, 'decodeIdTokenPayload');
        $this->decodeMethod->setAccessible(true);
    }

    private function buildJwt(array $payload): string
    {
        $header = rtrim(strtr(base64_encode('{"alg":"RS256","typ":"JWT"}'), '+/', '-_'), '=');
        $body = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
        $signature = rtrim(strtr(base64_encode('fake-signature'), '+/', '-_'), '=');

        return "$header.$body.$signature";
    }

    /**
     * The userinfo endpoint and the ID token are both parts of the same
     * authenticated exchange, so claims the endpoint omits are taken from the
     * token. email_verified is the one that decides account linking.
     */
    public function testUserInfoIsFilledFromTheIdTokenWhereUserinfoIsSilent(): void
    {
        $userInfo = $this->resolveUserInfo(
            ['sub' => 'user-123', 'email' => 'test@example.com'],
            ['sub' => 'user-123', 'email_verified' => false, 'groups' => ['admins']]
        );

        $raw = $userInfo->getRawData();
        $this->assertArrayHasKey('email_verified', $raw);
        $this->assertFalse($raw['email_verified'], 'The claim the userinfo endpoint never sent must still be seen');
        $this->assertSame(['admins'], $userInfo->getGroups());
    }

    public function testUserinfoWinsWhenBothSourcesCarryTheSameClaim(): void
    {
        $userInfo = $this->resolveUserInfo(
            ['sub' => 'user-123', 'email' => 'test@example.com', 'email_verified' => false],
            ['sub' => 'user-123', 'email_verified' => true]
        );

        $this->assertFalse($userInfo->getRawData()['email_verified']);
    }

    public function testUserInfoWithoutAnIdTokenKeepsTheUserinfoClaims(): void
    {
        $userInfo = $this->resolveUserInfo(['sub' => 'user-123', 'email' => 'test@example.com'], null);

        $this->assertSame(['sub' => 'user-123', 'email' => 'test@example.com'], $userInfo->getRawData());
        $this->assertSame([], $userInfo->getGroups());
    }

    /**
     * @param array<string, mixed> $userData claims the userinfo endpoint returns
     * @param array<string, mixed>|null $idTokenClaims claims carried by the ID token, or null for no token
     */
    private function resolveUserInfo(array $userData, ?array $idTokenClaims): OidcUserInfo
    {
        $resourceOwner = $this->createMock(ResourceOwnerInterface::class);
        $resourceOwner->method('toArray')->willReturn($userData);

        $provider = $this->createMock(GenericProvider::class);
        $provider->method('getResourceOwner')->willReturn($resourceOwner);

        // GenericProvider::getResourceOwner() takes the concrete token, not the interface
        $options = ['access_token' => 'access-token'];
        if ($idTokenClaims !== null) {
            $options['id_token'] = $this->buildJwt($idTokenClaims);
        }
        $token = new AccessToken($options);

        $method = new ReflectionMethod(OidcService::class, 'getUserInfo');
        $method->setAccessible(true);

        return $method->invoke($this->service, $provider, $token, 'test-provider');
    }

    public function testDecodeValidIdTokenWithGroups(): void
    {
        $groups = ['group-id-1', 'group-id-2', 'group-id-3'];
        $jwt = $this->buildJwt([
            'sub' => 'user-123',
            'name' => 'Test User',
            'email' => 'test@example.com',
            'groups' => $groups,
        ]);

        $claims = $this->decodeMethod->invoke($this->service, $jwt);

        $this->assertIsArray($claims);
        $this->assertEquals('user-123', $claims['sub']);
        $this->assertEquals($groups, $claims['groups']);
    }

    public function testDecodeIdTokenWithoutGroups(): void
    {
        $jwt = $this->buildJwt([
            'sub' => 'user-123',
            'name' => 'Test User',
        ]);

        $claims = $this->decodeMethod->invoke($this->service, $jwt);

        $this->assertIsArray($claims);
        $this->assertArrayNotHasKey('groups', $claims);
    }

    public function testDecodeInvalidTokenFormat(): void
    {
        $claims = $this->decodeMethod->invoke($this->service, 'not-a-jwt');
        $this->assertEmpty($claims);
    }

    public function testDecodeTokenWithTwoParts(): void
    {
        $claims = $this->decodeMethod->invoke($this->service, 'header.payload');
        $this->assertEmpty($claims);
    }

    public function testDecodeTokenWithFourParts(): void
    {
        $claims = $this->decodeMethod->invoke($this->service, 'a.b.c.d');
        $this->assertEmpty($claims);
    }

    public function testDecodeTokenWithInvalidBase64Payload(): void
    {
        $claims = $this->decodeMethod->invoke($this->service, 'header.!!!invalid!!!.signature');
        $this->assertEmpty($claims);
    }

    public function testDecodeTokenWithNonJsonPayload(): void
    {
        $header = rtrim(strtr(base64_encode('{"alg":"RS256"}'), '+/', '-_'), '=');
        $body = rtrim(strtr(base64_encode('not json'), '+/', '-_'), '=');
        $signature = rtrim(strtr(base64_encode('sig'), '+/', '-_'), '=');

        $claims = $this->decodeMethod->invoke($this->service, "$header.$body.$signature");
        $this->assertEmpty($claims);
    }

    public function testDecodeEmptyToken(): void
    {
        $claims = $this->decodeMethod->invoke($this->service, '');
        $this->assertEmpty($claims);
    }

    public function testDecodeEntraIdStyleToken(): void
    {
        $jwt = $this->buildJwt([
            'aud' => 'client-id-123',
            'iss' => 'https://login.microsoftonline.com/tenant-id/v2.0',
            'sub' => 'entra-user-sub',
            'name' => 'John Doe',
            'preferred_username' => 'john@example.com',
            'groups' => [
                'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
                'ffffffff-1111-2222-3333-444444444444',
            ],
        ]);

        $claims = $this->decodeMethod->invoke($this->service, $jwt);

        $this->assertCount(2, $claims['groups']);
        $this->assertEquals('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', $claims['groups'][0]);
    }

    public function testDecodeTokenWithBase64UrlPadding(): void
    {
        // Ensure base64url decoding handles the + and / substitutions correctly
        $jwt = $this->buildJwt([
            'sub' => 'user+special/chars=test',
            'groups' => ['group1'],
        ]);

        $claims = $this->decodeMethod->invoke($this->service, $jwt);

        $this->assertEquals('user+special/chars=test', $claims['sub']);
        $this->assertEquals(['group1'], $claims['groups']);
    }
}
