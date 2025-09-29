<?php

namespace unit\Application\Service;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\UserProvisioningService;
use Poweradmin\Domain\ValueObject\OidcUserInfo;
use Poweradmin\Domain\ValueObject\SamlUserInfo;
use ReflectionClass;

class UserProvisioningServiceTest extends TestCase
{
    private UserProvisioningService $service;

    protected function setUp(): void
    {
        $reflection = new ReflectionClass(UserProvisioningService::class);
        $this->service = $reflection->newInstanceWithoutConstructor();
    }

    /**
     * Test shouldUpdateAuthMethod method using reflection since it's private
     */
    public function testShouldUpdateAuthMethodWithNullCurrentMethod(): void
    {
        // No configuration setup needed for this private method test
        $result = $this->invokeShouldUpdateAuthMethod(null, UserProvisioningService::AUTH_METHOD_OIDC);
        $this->assertTrue($result, 'Should update auth method when current method is null');
    }

    public function testShouldUpdateAuthMethodWithEmptyCurrentMethod(): void
    {
        $result = $this->invokeShouldUpdateAuthMethod('', UserProvisioningService::AUTH_METHOD_SAML);
        $this->assertTrue($result, 'Should update auth method when current method is empty');
    }

    public function testShouldNotUpdateAuthMethodFromSqlToOidc(): void
    {
        $result = $this->invokeShouldUpdateAuthMethod(
            UserProvisioningService::AUTH_METHOD_SQL,
            UserProvisioningService::AUTH_METHOD_OIDC
        );
        $this->assertFalse($result, 'Should preserve SQL auth method when logging in via OIDC');
    }

    public function testShouldNotUpdateAuthMethodFromSqlToSaml(): void
    {
        $result = $this->invokeShouldUpdateAuthMethod(
            UserProvisioningService::AUTH_METHOD_SQL,
            UserProvisioningService::AUTH_METHOD_SAML
        );
        $this->assertFalse($result, 'Should preserve SQL auth method when logging in via SAML');
    }

    public function testShouldUpdateAuthMethodSameMethod(): void
    {
        $result = $this->invokeShouldUpdateAuthMethod(
            UserProvisioningService::AUTH_METHOD_OIDC,
            UserProvisioningService::AUTH_METHOD_OIDC
        );
        $this->assertTrue($result, 'Should update auth method when refreshing same auth type');
    }

    public function testShouldUpdateAuthMethodSameSamlMethod(): void
    {
        $result = $this->invokeShouldUpdateAuthMethod(
            UserProvisioningService::AUTH_METHOD_SAML,
            UserProvisioningService::AUTH_METHOD_SAML
        );
        $this->assertTrue($result, 'Should update auth method when refreshing same SAML auth type');
    }

    public function testShouldNotUpdateAuthMethodFromLdapToOidc(): void
    {
        $result = $this->invokeShouldUpdateAuthMethod(
            UserProvisioningService::AUTH_METHOD_LDAP,
            UserProvisioningService::AUTH_METHOD_OIDC
        );
        $this->assertFalse($result, 'Should not overwrite LDAP auth method with OIDC');
    }

    public function testShouldNotUpdateAuthMethodFromLdapToSaml(): void
    {
        $result = $this->invokeShouldUpdateAuthMethod(
            UserProvisioningService::AUTH_METHOD_LDAP,
            UserProvisioningService::AUTH_METHOD_SAML
        );
        $this->assertFalse($result, 'Should not overwrite LDAP auth method with SAML');
    }

    public function testShouldUpdateAuthMethodFromOidcToSaml(): void
    {
        $result = $this->invokeShouldUpdateAuthMethod(
            UserProvisioningService::AUTH_METHOD_OIDC,
            UserProvisioningService::AUTH_METHOD_SAML
        );
        $this->assertTrue($result, 'Should allow transition from OIDC to SAML (both external SSO)');
    }

    public function testShouldUpdateAuthMethodFromSamlToOidc(): void
    {
        $result = $this->invokeShouldUpdateAuthMethod(
            UserProvisioningService::AUTH_METHOD_SAML,
            UserProvisioningService::AUTH_METHOD_OIDC
        );
        $this->assertTrue($result, 'Should allow transition from SAML to OIDC (both external SSO)');
    }

    public function testShouldNotUpdateAuthMethodFromOidcToLdap(): void
    {
        $result = $this->invokeShouldUpdateAuthMethod(
            UserProvisioningService::AUTH_METHOD_OIDC,
            UserProvisioningService::AUTH_METHOD_LDAP
        );
        $this->assertFalse($result, 'Should not overwrite OIDC auth method with LDAP');
    }

    public function testShouldNotUpdateAuthMethodFromSamlToLdap(): void
    {
        $result = $this->invokeShouldUpdateAuthMethod(
            UserProvisioningService::AUTH_METHOD_SAML,
            UserProvisioningService::AUTH_METHOD_LDAP
        );
        $this->assertFalse($result, 'Should not overwrite SAML auth method with LDAP');
    }

    /**
     * Test edge cases with custom/unknown auth methods
     */
    public function testShouldNotUpdateAuthMethodWithUnknownCurrentMethod(): void
    {
        $result = $this->invokeShouldUpdateAuthMethod('unknown_method', UserProvisioningService::AUTH_METHOD_OIDC);
        $this->assertFalse($result, 'Should not overwrite unknown auth method');
    }

    public function testShouldNotUpdateAuthMethodWithCustomCurrentMethod(): void
    {
        $result = $this->invokeShouldUpdateAuthMethod('custom_auth', UserProvisioningService::AUTH_METHOD_SAML);
        $this->assertFalse($result, 'Should not overwrite custom auth method');
    }

    /**
     * Test all combinations systematically
     */
    public static function provideShouldUpdateAuthMethodTestCases(): array
    {
        return [
            // Cases that should return true
            ['current' => null, 'new' => UserProvisioningService::AUTH_METHOD_OIDC, 'expected' => true],
            ['current' => '', 'new' => UserProvisioningService::AUTH_METHOD_SAML, 'expected' => true],
            ['current' => UserProvisioningService::AUTH_METHOD_OIDC, 'new' => UserProvisioningService::AUTH_METHOD_OIDC, 'expected' => true],
            ['current' => UserProvisioningService::AUTH_METHOD_SAML, 'new' => UserProvisioningService::AUTH_METHOD_SAML, 'expected' => true],

            // Cases that should return true (SAML <-> OIDC transitions allowed)
            ['current' => UserProvisioningService::AUTH_METHOD_OIDC, 'new' => UserProvisioningService::AUTH_METHOD_SAML, 'expected' => true],
            ['current' => UserProvisioningService::AUTH_METHOD_SAML, 'new' => UserProvisioningService::AUTH_METHOD_OIDC, 'expected' => true],

            // Cases that should return false (preserve existing auth methods)
            ['current' => UserProvisioningService::AUTH_METHOD_SQL, 'new' => UserProvisioningService::AUTH_METHOD_OIDC, 'expected' => false],
            ['current' => UserProvisioningService::AUTH_METHOD_SQL, 'new' => UserProvisioningService::AUTH_METHOD_SAML, 'expected' => false],
            ['current' => UserProvisioningService::AUTH_METHOD_LDAP, 'new' => UserProvisioningService::AUTH_METHOD_OIDC, 'expected' => false],
            ['current' => UserProvisioningService::AUTH_METHOD_LDAP, 'new' => UserProvisioningService::AUTH_METHOD_SAML, 'expected' => false],
            ['current' => UserProvisioningService::AUTH_METHOD_OIDC, 'new' => UserProvisioningService::AUTH_METHOD_LDAP, 'expected' => false],
            ['current' => UserProvisioningService::AUTH_METHOD_SAML, 'new' => UserProvisioningService::AUTH_METHOD_LDAP, 'expected' => false],
            ['current' => 'unknown_method', 'new' => UserProvisioningService::AUTH_METHOD_OIDC, 'expected' => false],
            ['current' => 'custom_auth', 'new' => UserProvisioningService::AUTH_METHOD_SAML, 'expected' => false],
        ];
    }

    /**
     * @dataProvider provideShouldUpdateAuthMethodTestCases
     */
    public function testShouldUpdateAuthMethodAllCombinations(?string $current, string $new, bool $expected): void
    {
        $result = $this->invokeShouldUpdateAuthMethod($current, $new);
        $this->assertEquals(
            $expected,
            $result,
            sprintf('shouldUpdateAuthMethod("%s", "%s") should return %s', $current ?? 'null', $new, $expected ? 'true' : 'false')
        );
    }

    /**
     * Helper to invoke the private shouldUpdateAuthMethod logic
     */
    private function invokeShouldUpdateAuthMethod(?string $currentAuthMethod, string $newAuthMethod): bool
    {
        $invoker = $this->getPrivateMethodInvoker('shouldUpdateAuthMethod');

        return $invoker($currentAuthMethod, $newAuthMethod);
    }

    private function getPrivateMethodInvoker(string $method): callable
    {
        return \Closure::bind(
            function (...$args) use ($method) {
                return $this->{$method}(...$args);
            },
            $this->service,
            UserProvisioningService::class
        );
    }

    /**
     * Test auth method constants are properly defined
     */
    public function testAuthMethodConstants(): void
    {
        $this->assertEquals('sql', UserProvisioningService::AUTH_METHOD_SQL);
        $this->assertEquals('ldap', UserProvisioningService::AUTH_METHOD_LDAP);
        $this->assertEquals('oidc', UserProvisioningService::AUTH_METHOD_OIDC);
        $this->assertEquals('saml', UserProvisioningService::AUTH_METHOD_SAML);
    }

    /**
     * Test that auth method transition logic is secure and doesn't allow unauthorized transitions
     */
    public function testAuthMethodTransitionSecurity(): void
    {
        // Test that we never overwrite LDAP (could break existing LDAP users)
        $this->assertFalse($this->invokeShouldUpdateAuthMethod('ldap', 'oidc'));
        $this->assertFalse($this->invokeShouldUpdateAuthMethod('ldap', 'saml'));
        $this->assertFalse($this->invokeShouldUpdateAuthMethod('ldap', 'sql'));

        // Test that we allow transitions between external SSO auth methods (SAML <-> OIDC)
        $this->assertTrue($this->invokeShouldUpdateAuthMethod('oidc', 'saml'));
        $this->assertTrue($this->invokeShouldUpdateAuthMethod('saml', 'oidc'));

        // Test that unknown methods are preserved (could be custom implementations)
        $this->assertFalse($this->invokeShouldUpdateAuthMethod('custom_sso', 'oidc'));
        $this->assertFalse($this->invokeShouldUpdateAuthMethod('enterprise_auth', 'saml'));
    }

    /**
     * Test the new determineAuthMethodFromUserInfo method
     */
    public function testDetermineAuthMethodFromUserInfo(): void
    {
        $oidcUserInfo = new OidcUserInfo(
            username: 'test.user',
            email: 'test@example.com',
            firstName: 'Test',
            lastName: 'User',
            displayName: 'Test User',
            groups: ['users'],
            providerId: 'test_provider',
            subject: 'oidc-subject',
            rawData: []
        );

        $samlUserInfo = new SamlUserInfo(
            username: 'test.user',
            email: 'test@example.com',
            firstName: 'Test',
            lastName: 'User',
            displayName: 'Test User',
            groups: ['users'],
            providerId: 'test_provider',
            nameId: 'name-id',
            sessionIndex: 'session-index',
            rawAttributes: []
        );

        $determineAuthMethod = $this->getPrivateMethodInvoker('determineAuthMethodFromUserInfo');

        // Test OIDC user info
        $result = $determineAuthMethod($oidcUserInfo);
        $this->assertEquals(UserProvisioningService::AUTH_METHOD_OIDC, $result, 'Should detect OIDC from OidcUserInfo');

        // Test SAML user info
        $result = $determineAuthMethod($samlUserInfo);
        $this->assertEquals(UserProvisioningService::AUTH_METHOD_SAML, $result, 'Should detect SAML from SamlUserInfo');
    }

    /**
     * Test that provider ID conflicts are resolved by UserInfo type
     * This tests the fix for the issue where OIDC and SAML providers with same ID (e.g., "okta")
     * would incorrectly determine auth method based on configuration lookup order
     */
    public function testProviderIdConflictResolution(): void
    {
        // Create OIDC and SAML user info with the same provider ID
        $oidcUserWithOktaProvider = new OidcUserInfo(
            username: 'oidc.user',
            email: 'oidc@example.com',
            firstName: 'OIDC',
            lastName: 'User',
            displayName: 'OIDC User',
            groups: ['users'],
            providerId: 'okta', // Same provider ID
            subject: 'oidc-subject',
            rawData: []
        );

        $samlUserWithOktaProvider = new SamlUserInfo(
            username: 'saml.user',
            email: 'saml@example.com',
            firstName: 'SAML',
            lastName: 'User',
            displayName: 'SAML User',
            groups: ['users'],
            providerId: 'okta', // Same provider ID
            nameId: 'saml-name-id',
            sessionIndex: 'session-index',
            rawAttributes: []
        );

        $determineAuthMethod = $this->getPrivateMethodInvoker('determineAuthMethodFromUserInfo');

        // Despite having the same provider ID, the auth method should be determined by UserInfo type
        $oidcResult = $determineAuthMethod($oidcUserWithOktaProvider);
        $samlResult = $determineAuthMethod($samlUserWithOktaProvider);

        $this->assertEquals(
            UserProvisioningService::AUTH_METHOD_OIDC,
            $oidcResult,
            'OIDC user should be detected as OIDC regardless of provider ID conflicts'
        );
        $this->assertEquals(
            UserProvisioningService::AUTH_METHOD_SAML,
            $samlResult,
            'SAML user should be detected as SAML regardless of provider ID conflicts'
        );
    }
}
