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
 */

namespace Poweradmin\Tests\Unit\Application\Service\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Http\Request;
use Poweradmin\Application\Service\Auth\AuthenticationService;
use Poweradmin\Application\Service\Auth\SamlConfigurationService;
use Poweradmin\Application\Service\Auth\SamlService;
use Poweradmin\Application\Service\Auth\UserProvisioningService;
use Poweradmin\Application\Service\Web\AuditService;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Service\Auth\MfaService;
use Poweradmin\Infrastructure\Session\ArraySession;
use Psr\Log\NullLogger;

/**
 * Login and logout must send the provider id as RelayState. Logout used to pass none,
 * so php-saml built one from the request Host header and sent it to the identity provider.
 */
#[CoversClass(SamlService::class)]
class SamlRelayStateTest extends TestCase
{
    private const FORGED_HOST = 'evil.example';

    private array $savedServer;
    private ArraySession $session;

    protected function setUp(): void
    {
        $this->savedServer = $_SERVER;
        $_SERVER['HTTP_HOST'] = self::FORGED_HOST;
        $_SERVER['SERVER_NAME'] = self::FORGED_HOST;
        $_SERVER['REQUEST_URI'] = '/logout';
        $this->session = new ArraySession();
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->savedServer;
    }

    private function service(): SamlService
    {
        $samlConfig = $this->createMock(SamlConfigurationService::class);
        $samlConfig->method('validatePermissionTemplateMapping')->willReturn([]);
        $samlConfig->method('generateOneLoginSettings')->willReturn([
            'strict' => true,
            'sp' => [
                'entityId' => 'https://dns.example.com/saml/metadata',
                'assertionConsumerService' => ['url' => 'https://dns.example.com/saml/acs'],
                'singleLogoutService' => ['url' => 'https://dns.example.com/saml/sls'],
            ],
            'idp' => [
                'entityId' => 'https://idp.example.com',
                'singleSignOnService' => ['url' => 'https://idp.example.com/sso'],
                'singleLogoutService' => ['url' => 'https://idp.example.com/slo'],
                'certFingerprint' => str_repeat('ab', 20),
            ],
        ]);

        return new SamlService(
            $this->createMock(ConfigurationInterface::class),
            $samlConfig,
            $this->createMock(UserProvisioningService::class),
            new NullLogger(),
            $this->createMock(AuthenticationService::class),
            $this->createMock(AuditService::class),
            $this->createMock(MfaService::class),
            $this->session,
            $this->createMock(Request::class)
        );
    }

    private function relayStateOf(?string $url): string
    {
        $this->assertNotNull($url);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        $this->assertArrayHasKey('RelayState', $query);
        return (string)$query['RelayState'];
    }

    public function testLogoutSendsTheProviderNotTheRequestHost(): void
    {
        $this->session->set('saml_name_id', 'alice@example.com');

        $relayState = $this->relayStateOf($this->service()->initiateSingleLogout('okta'));

        $this->assertStringNotContainsString(self::FORGED_HOST, $relayState);
        $this->assertSame(['provider' => 'okta'], json_decode(base64_decode($relayState), true));
    }

    public function testLoginSendsTheSameRelayStateAsLogout(): void
    {
        $relayState = $this->relayStateOf($this->service()->initiateAuthFlow('okta'));

        $this->assertSame(SamlService::relayStateFor('okta'), $relayState);
    }
}
