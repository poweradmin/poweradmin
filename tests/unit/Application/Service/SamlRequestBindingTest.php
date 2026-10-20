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

namespace Poweradmin\Tests\Unit\Application\Service;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Http\Request;
use Poweradmin\Application\Service\SamlConfigurationService;
use Poweradmin\Application\Service\SamlService;
use Poweradmin\Application\Service\UserProvisioningService;
use Poweradmin\Domain\Model\SessionEntity;
use Poweradmin\Domain\Service\AuthenticationService;
use Poweradmin\Domain\Service\PasswordEncryptionService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\Logger;
use ReflectionProperty;

/**
 * With allow_idp_initiated off, a response must answer the sign-in this browser started. On,
 * which is the 4.x stable default, responses are processed as before, bound or not.
 */
class SamlRequestBindingTest extends TestCase
{
    private const SESSION_KEY = 'test-session-key';
    private const REFUSED = 'the sign-in was not started from this browser';
    private const MISMATCH = 'does not match the ID of the AuthNRequest';
    private const NO_IN_RESPONSE_TO = 'No InResponseTo at the Response';

    /** @var array<string, mixed> */
    private array $server;

    /** @var array<string, mixed> */
    private array $cookie;

    /** @var array<string, mixed> */
    private array $post;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $this->cookie = $_COOKIE;
        $this->post = $_POST;
        $_SERVER = ['REQUEST_METHOD' => 'POST', 'HTTP_HOST' => 'pa.example.com', 'HTTPS' => 'on', 'SERVER_PORT' => '443', 'REQUEST_URI' => '/saml/acs'];
        $_COOKIE = [];
        $_POST = [];
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_COOKIE = $this->cookie;
        $_POST = $this->post;
        $_SESSION = [];
    }

    public function testAnUnboundResponseIsProcessedByDefault(): void
    {
        $result = $this->handle(['corp' => []], null, 'corp');

        $this->assertProcessedWithoutBindingError($result);
    }

    public function testAFlowCookieThatDoesNotDecryptCountsAsUnboundByDefault(): void
    {
        $_COOKIE['__Host-saml_flow'] = 'not-an-encrypted-payload';

        $this->assertProcessedWithoutBindingError($this->handle(['corp' => []], null, 'corp'));
    }

    public function testByDefaultAResponseToAnotherRequestIsProcessedAsBefore(): void
    {
        $this->setFlowCookie('corp', 'request-123');

        $this->assertProcessedWithoutBindingError($this->handle(['corp' => []], 'request-999'));
    }

    public function testByDefaultAnUnsolicitedResponseIsProcessedWhileASignInIsPending(): void
    {
        $this->setFlowCookie('corp', 'request-123');

        $this->assertProcessedWithoutBindingError($this->handle(['corp' => []], null));
    }

    public function testWithIdpInitiatedOffAResponseToAnotherRequestIsRefused(): void
    {
        $this->setFlowCookie('corp', 'request-123');

        $result = $this->handle(['corp' => ['allow_idp_initiated' => false]], 'request-999');

        $this->assertStringContainsString(self::MISMATCH, implode(' ', $result['reasons']));
    }

    public function testWithIdpInitiatedOffAnUnsolicitedResponseIsRefusedWhileASignInIsPending(): void
    {
        $this->setFlowCookie('corp', 'request-123');

        $result = $this->handle(['corp' => ['allow_idp_initiated' => false]], null);

        $this->assertStringContainsString(self::NO_IN_RESPONSE_TO, implode(' ', $result['reasons']));
    }

    public function testAResponseToTheBoundRequestPassesTheBindingCheck(): void
    {
        $this->setFlowCookie('corp', 'request-123');

        $this->assertProcessedWithoutBindingError($this->handle(['corp' => []], 'request-123'));
    }

    public function testWithIdpInitiatedOffAResponseWithoutAFlowCookieIsRefused(): void
    {
        $result = $this->handle(['corp' => ['allow_idp_initiated' => false]], null, 'corp');

        $this->assertStringContainsString(self::REFUSED, $result['message']);
        $this->assertSame([], $result['reasons']);
    }

    public function testWithIdpInitiatedOffAFlowCookieThatDoesNotDecryptIsRefused(): void
    {
        $_COOKIE['__Host-saml_flow'] = 'not-an-encrypted-payload';

        $this->assertStringContainsString(self::REFUSED, $this->handle(['corp' => ['allow_idp_initiated' => false]], null, 'corp')['message']);
    }

    public function testWithIdpInitiatedOffAFlowCookieFromAnotherKeyIsRefused(): void
    {
        $_COOKIE['__Host-saml_flow'] = (new PasswordEncryptionService('other-key'))->encrypt(json_encode([
            'provider' => 'corp',
            'request_id' => 'request-123',
        ]));

        $this->assertStringContainsString(self::REFUSED, $this->handle(['corp' => ['allow_idp_initiated' => false]], null, 'corp')['message']);
    }

    public function testWithIdpInitiatedOffAFlowCookieWithoutARequestIdIsRefused(): void
    {
        $_COOKIE['__Host-saml_flow'] = (new PasswordEncryptionService(self::SESSION_KEY))->encrypt(json_encode(['provider' => 'corp']));

        $this->assertStringContainsString(self::REFUSED, $this->handle(['corp' => ['allow_idp_initiated' => false]], null, 'corp')['message']);
    }

    public function testWithIdpInitiatedOffAResponseToTheBoundRequestPassesTheBindingCheck(): void
    {
        $this->setFlowCookie('corp', 'request-123');

        $this->assertProcessedWithoutBindingError($this->handle(['corp' => ['allow_idp_initiated' => false]], 'request-123'));
    }

    public function testByDefaultTheRelayStateProviderWinsOverTheFlowCookie(): void
    {
        $this->setFlowCookie('corp', 'request-123');

        $result = $this->handle(['corp' => [], 'other' => []], 'request-123', 'other');

        $this->assertSame(['other'], $result['providers']);
        $this->assertProcessedWithoutBindingError($result);
    }

    public function testTheFlowCookieProviderIsUsedWhenNothingElseNamesOne(): void
    {
        $this->setFlowCookie('corp', 'request-123');

        $this->assertSame(['corp'], $this->handle(['corp' => []], 'request-123')['providers']);
    }

    public function testWithIdpInitiatedOffARelayStateProviderTheCookieDidNotStartIsRefused(): void
    {
        $this->setFlowCookie('corp', 'request-123');

        $result = $this->handle(['corp' => [], 'other' => ['allow_idp_initiated' => false]], 'request-123', 'other');

        $this->assertStringContainsString(self::REFUSED, $result['message']);
    }

    public function testWithIdpInitiatedOffARelayStateNamingTheCookieProviderIsBound(): void
    {
        $this->setFlowCookie('corp', 'request-123');

        $result = $this->handle(['corp' => ['allow_idp_initiated' => false]], 'request-999', 'corp');

        $this->assertStringContainsString(self::MISMATCH, implode(' ', $result['reasons']));
    }

    /**
     * @param array{message: string, reasons: array<int, string>, providers: array<int, string>} $result
     */
    private function assertProcessedWithoutBindingError(array $result): void
    {
        $this->assertStringNotContainsString(self::REFUSED, $result['message']);
        // php-saml ran and failed on the unsigned test response, past its InResponseTo check
        $this->assertNotSame([], $result['reasons']);
        $this->assertStringNotContainsString('InResponseTo', implode(' ', $result['reasons']));
    }

    private function setFlowCookie(string $provider, string $requestId): void
    {
        $_COOKIE['__Host-saml_flow'] = (new PasswordEncryptionService(self::SESSION_KEY))->encrypt(json_encode([
            'provider' => $provider,
            'request_id' => $requestId,
        ]));
    }

    private static function samlResponse(?string $inResponseTo): string
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $inResponseToAttribute = $inResponseTo === null ? '' : ' InResponseTo="' . $inResponseTo . '"';

        return base64_encode(
            '<samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion"'
            . ' ID="_response" Version="2.0" IssueInstant="' . $now . '" Destination="https://pa.example.com/saml/acs"' . $inResponseToAttribute . '>'
            . '<saml:Issuer>https://idp.example.com/corp</saml:Issuer>'
            . '<samlp:Status><samlp:StatusCode Value="urn:oasis:names:tc:SAML:2.0:status:Success"/></samlp:Status>'
            . '<saml:Assertion ID="_assertion" Version="2.0" IssueInstant="' . $now . '">'
            . '<saml:Issuer>https://idp.example.com/corp</saml:Issuer>'
            . '<saml:Subject><saml:NameID>bob</saml:NameID></saml:Subject>'
            . '</saml:Assertion></samlp:Response>'
        );
    }

    /**
     * Runs handleAssertion() on an unsigned response, which php-saml always rejects in the end.
     * Returns the flash message, the php-saml error reasons logged, and the providers an Auth was built for.
     *
     * @param array<string, array<string, mixed>> $providers provider configs by id
     * @return array{message: string, reasons: array<int, string>, providers: array<int, string>}
     */
    private function handle(array $providers, ?string $inResponseTo, ?string $relayStateProvider = null): array
    {
        $authProviders = [];
        $reasons = [];

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            fn(string $group, string $key, $default = null) => $group === 'security' && $key === 'session_key' ? self::SESSION_KEY : $default
        );

        $samlConfig = $this->createMock(SamlConfigurationService::class);
        $samlConfig->method('getProviderConfig')->willReturnCallback(fn(string $id) => $providers[$id] ?? null);
        $samlConfig->method('getServiceProviderConfig')->willReturn(['assertion_consumer_service_url' => 'https://pa.example.com/saml/acs']);
        $samlConfig->method('generateOneLoginSettings')->willReturnCallback(function (string $id) use (&$authProviders): array {
            $authProviders[] = $id;
            return [
                'strict' => true,
                'sp' => [
                    'entityId' => 'https://pa.example.com/saml/metadata',
                    'assertionConsumerService' => ['url' => 'https://pa.example.com/saml/acs'],
                ],
                'idp' => [
                    'entityId' => 'https://idp.example.com/' . $id,
                    'singleSignOnService' => ['url' => 'https://idp.example.com/sso'],
                    'certFingerprint' => str_repeat('a', 40),
                ],
                'security' => ['wantXMLValidation' => false],
            ];
        });

        $logger = $this->createMock(Logger::class);
        $logger->method('error')->willReturnCallback(function (string $message, array $context = []) use (&$reasons): void {
            if (isset($context['reason'])) {
                $reasons[] = (string) $context['reason'];
            }
        });

        $_POST['SAMLResponse'] = self::samlResponse($inResponseTo);
        if ($relayStateProvider !== null) {
            $_POST['RelayState'] = base64_encode(json_encode(['provider' => $relayStateProvider]));
        }

        $service = new SamlService(
            $config,
            $samlConfig,
            $this->createMock(UserProvisioningService::class),
            $logger,
            $this->createMock(PDO::class),
            new Request()
        );

        $message = '';
        $authentication = $this->createMock(AuthenticationService::class);
        $authentication->expects($this->once())->method('auth')->willReturnCallback(
            function (SessionEntity $entity) use (&$message): void {
                $message = $entity->getMessage();
            }
        );
        (new ReflectionProperty(SamlService::class, 'authenticationService'))->setValue($service, $authentication);

        $service->handleAssertion();

        return ['message' => $message, 'reasons' => $reasons, 'providers' => $authProviders];
    }
}
