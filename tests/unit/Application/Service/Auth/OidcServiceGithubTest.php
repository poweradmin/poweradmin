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

namespace Poweradmin\Tests\Unit\Application\Service\Auth;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use League\OAuth2\Client\Provider\GenericProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\Auth\AuthenticationService;
use Poweradmin\Application\Service\Auth\OidcConfigurationService;
use Poweradmin\Application\Service\Auth\OidcService;
use Poweradmin\Application\Service\Auth\UserProvisioningService;
use Poweradmin\Application\Service\Web\AuditService;
use Poweradmin\Domain\Service\Auth\MfaService;
use Poweradmin\Domain\ValueObject\OidcUserInfo;
use Poweradmin\Infrastructure\Logger\Logger;
use Poweradmin\Infrastructure\Session\ArraySession;
use ReflectionMethod;
use TestHelpers\FakeConfiguration;

/**
 * GitHub sign-in (#1620): OAuth 2.0 without OIDC. The token reply is form-encoded, the
 * user id is numeric and a private profile email comes back null, so the verified
 * address is read from the emails_url list. Replies are GitHub-shaped mocks.
 */
class OidcServiceGithubTest extends TestCase
{
    private const GITHUB = [
        'client_id' => 'client',
        'client_secret' => 'secret',
        'authorize_url' => 'https://github.com/login/oauth/authorize',
        'token_url' => 'https://github.com/login/oauth/access_token',
        'userinfo_url' => 'https://api.github.com/user',
        'emails_url' => 'https://api.github.com/user/emails',
        'scopes' => 'read:user user:email',
        'user_mapping' => ['username' => 'login', 'email' => 'email', 'display_name' => 'name', 'subject' => 'id', 'avatar' => 'avatar_url'],
    ];

    /** @var list<array{request: \Psr\Http\Message\RequestInterface}> */
    private array $sent = [];

    /** @var Logger&\PHPUnit\Framework\MockObject\MockObject */
    private Logger $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(Logger::class);
    }

    public function testAPrivateProfileEmailIsReplacedByTheVerifiedPrimary(): void
    {
        $info = $this->signIn(self::GITHUB, ['email' => null], [
            ['email' => 'old@example.com', 'primary' => false, 'verified' => true],
            ['email' => 'me@example.com', 'primary' => true, 'verified' => true],
        ]);

        $this->assertSame('octocat', $info->getUsername());
        $this->assertSame('583231', $info->getSubject());
        $this->assertSame('me@example.com', $info->getEmail());
        $this->assertTrue($info->getRawData()['email_verified']);
        $this->assertSame('https://avatars.example/octocat', $info->getAvatarUrl());
        $this->assertSame('Bearer gho_token', $this->sent[2]['request']->getHeaderLine('Authorization'));
    }

    public function testAVerifiedPublicProfileEmailIsKeptOverThePrimary(): void
    {
        $info = $this->signIn(self::GITHUB, ['email' => 'Public@example.com'], [
            ['email' => 'me@example.com', 'primary' => true, 'verified' => true],
            ['email' => 'public@example.com', 'primary' => false, 'verified' => true],
        ]);

        $this->assertSame('public@example.com', $info->getEmail());
        $this->assertTrue($info->getRawData()['email_verified']);
    }

    public function testWithoutAVerifiedAddressTheEmailIsMarkedUnverified(): void
    {
        $info = $this->signIn(self::GITHUB, ['email' => 'claimed@example.com'], [
            ['email' => 'claimed@example.com', 'primary' => true, 'verified' => false],
        ]);

        $this->assertSame('claimed@example.com', $info->getEmail());
        $this->assertFalse($info->getRawData()['email_verified']);
    }

    public function testARefusedEmailListIsLoggedAndMarksTheEmailUnverified(): void
    {
        // OidcService logs through ClassContextLogger, which calls log() with the level
        $this->logger->expects($this->once())->method('log')
            ->with('warning', $this->stringContains('HTTP {status}'), $this->callback(static fn(array $context): bool => $context['status'] === 404));

        $info = $this->signIn(self::GITHUB, ['email' => 'claimed@example.com'], new Response(404, ['Content-Type' => 'application/json'], '{"message":"Not Found"}'));

        $this->assertSame('claimed@example.com', $info->getEmail());
        $this->assertFalse($info->getRawData()['email_verified']);
    }

    public function testAnUnreachableEmailListIsLoggedAndMarksTheEmailUnverified(): void
    {
        $this->logger->expects($this->once())->method('log')
            ->with('warning', $this->stringContains('{error}'), $this->callback(static fn(array $context): bool => str_contains($context['error'], 'timed out')));

        $info = $this->signIn(self::GITHUB, ['email' => 'claimed@example.com'], new ConnectException('Connection timed out', new Request('GET', 'https://api.github.com/user/emails')));

        $this->assertSame('claimed@example.com', $info->getEmail());
        $this->assertFalse($info->getRawData()['email_verified']);
    }

    public function testWithoutEmailsUrlNoListIsReadAndNoClaimIsAdded(): void
    {
        $config = self::GITHUB;
        unset($config['emails_url']);

        $info = $this->signIn($config, ['email' => 'public@example.com'], null);

        $this->assertSame('public@example.com', $info->getEmail());
        $this->assertArrayNotHasKey('email_verified', $info->getRawData());
        $this->assertCount(2, $this->sent);
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $profile fields that override the default /user reply
     * @param list<array<string, mixed>>|Response|ConnectException|null $emails the /user/emails reply, null when it must not be asked for
     */
    private function signIn(array $config, array $profile, array|Response|ConnectException|null $emails): OidcUserInfo
    {
        $replies = [
            new Response(200, ['Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8'], 'access_token=gho_token&scope=read%3Auser%2Cuser%3Aemail&token_type=bearer'),
            new Response(200, ['Content-Type' => 'application/json'], (string)json_encode($profile + [
                'login' => 'octocat', 'id' => 583231, 'name' => 'The Octocat', 'avatar_url' => 'https://avatars.example/octocat',
            ])),
        ];
        if ($emails !== null) {
            $replies[] = is_array($emails) ? new Response(200, ['Content-Type' => 'application/json'], (string)json_encode($emails)) : $emails;
        }
        $stack = HandlerStack::create(new MockHandler($replies));
        $this->sent = [];
        $stack->push(Middleware::history($this->sent));

        $provider = new GenericProvider([
            'clientId' => $config['client_id'],
            'clientSecret' => $config['client_secret'],
            'redirectUri' => 'https://pa.example/oidc/callback',
            'urlAuthorize' => $config['authorize_url'],
            'urlAccessToken' => $config['token_url'],
            'urlResourceOwnerDetails' => $config['userinfo_url'],
        ], ['httpClient' => new Client(['handler' => $stack])]);

        $configService = $this->createMock(OidcConfigurationService::class);
        $configService->method('getProviderConfig')->willReturn($config);
        $service = new OidcService(
            new FakeConfiguration(),
            $configService,
            $this->createMock(UserProvisioningService::class),
            $this->logger,
            $this->createMock(AuthenticationService::class),
            $this->createMock(AuditService::class),
            $this->createMock(MfaService::class),
            new ArraySession()
        );

        $token = $provider->getAccessToken('authorization_code', ['code' => 'code', 'code_verifier' => 'verifier']);
        $this->assertSame('gho_token', $token->getToken());

        return (new ReflectionMethod(OidcService::class, 'getUserInfo'))->invoke($service, $provider, $token, 'github');
    }
}
