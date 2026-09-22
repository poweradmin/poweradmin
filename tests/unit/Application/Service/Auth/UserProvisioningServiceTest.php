<?php

namespace Poweradmin\Tests\Unit\Application\Service\Auth;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\Auth\UserProvisioningService;
use Poweradmin\Domain\Repository\ExternalIdentityRepositoryInterface;
use Poweradmin\Domain\Repository\UserGroupLookupInterface;
use Poweradmin\Domain\Repository\UserGroupMemberRepositoryInterface;
use Poweradmin\Domain\Repository\UserRepositoryInterface;
use Poweradmin\Domain\ValueObject\LdapUserInfo;
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

    #[DataProvider('provideShouldUpdateAuthMethodTestCases')]
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
     * Create a UserProvisioningService instance with mocked dependencies for testing
     * determinePermissionTemplate and related methods.
     */
    public function testSuperuserTemplateIsRefusedWhenProvisioningItIsNotAllowed(): void
    {
        // An IdP claim mapped onto the Administrator template would otherwise mint a
        // global superuser at login.
        $result = $this->resolveMappedTemplate(templateGrantsUberuser: true, allowSuperuser: false);

        $this->assertNull($result, 'A superuser template must not be provisioned from an IdP claim');
    }

    public function testSuperuserTemplateIsAllowedWhenTheOperatorOptsIn(): void
    {
        $result = $this->resolveMappedTemplate(templateGrantsUberuser: true, allowSuperuser: true);

        $this->assertEquals(1, $result);
    }

    public function testOrdinaryTemplateIsUnaffected(): void
    {
        $result = $this->resolveMappedTemplate(templateGrantsUberuser: false, allowSuperuser: false);

        $this->assertEquals(1, $result);
    }

    private function resolveMappedTemplate(bool $templateGrantsUberuser, bool $allowSuperuser): ?int
    {
        $configManager = $this->createMock(\Poweradmin\Infrastructure\Configuration\ConfigurationManager::class);
        $configManager->method('get')
            ->willReturnMap([
                ['oidc', 'permission_template_mapping', [], ['admins' => 'Administrator']],
                ['oidc', 'allow_superuser_provisioning', false, $allowSuperuser],
            ]);

        $userRepository = $this->createMock(UserRepositoryInterface::class);
        $userRepository->method('templateGrantsUberuser')->willReturn($templateGrantsUberuser);
        $userRepository->method('findPermissionTemplateIdByName')->willReturn(1);

        $service = $this->createServiceWithMocks($configManager, $userRepository);

        $method = (new ReflectionClass(UserProvisioningService::class))->getMethod('determinePermissionTemplate');
        $method->setAccessible(true);

        return $method->invoke($service, ['admins'], 'oidc', false);
    }

    private function createServiceWithMocks(
        ?\Poweradmin\Infrastructure\Configuration\ConfigurationManager $configManager = null,
        ?UserRepositoryInterface $userRepository = null
    ): UserProvisioningService {
        if ($userRepository === null) {
            $userRepository = $this->createMock(UserRepositoryInterface::class);
            $userRepository->method('templateGrantsUberuser')->willReturn(false);
        }

        return new UserProvisioningService(
            $configManager ?? $this->createMock(\Poweradmin\Infrastructure\Configuration\ConfigurationManager::class),
            $this->createMock(\Poweradmin\Infrastructure\Logger\Logger::class),
            $userRepository,
            $this->createMock(ExternalIdentityRepositoryInterface::class),
            $this->createMock(UserGroupLookupInterface::class),
            $this->createMock(UserGroupMemberRepositoryInterface::class)
        );
    }

    /**
     * Test determinePermissionTemplate with useDefaultFallback=false returns null when no groups match
     */
    public function testDeterminePermissionTemplateWithoutFallbackReturnsNull(): void
    {
        $configManager = $this->createMock(\Poweradmin\Infrastructure\Configuration\ConfigurationManager::class);
        $configManager->method('get')
            ->willReturnMap([
                ['oidc', 'permission_template_mapping', [], []],
                ['oidc', 'default_permission_template', '', 'Guest'],
            ]);

        $service = $this->createServiceWithMocks($configManager);

        $method = (new ReflectionClass(UserProvisioningService::class))->getMethod('determinePermissionTemplate');
        $method->setAccessible(true);

        $result = $method->invoke($service, [], 'oidc', false);
        $this->assertNull($result, 'Should return null when useDefaultFallback is false and no groups match');
    }

    /**
     * Test determinePermissionTemplate with useDefaultFallback=true falls back to default
     */
    public function testDeterminePermissionTemplateWithFallbackUsesDefault(): void
    {
        $configManager = $this->createMock(\Poweradmin\Infrastructure\Configuration\ConfigurationManager::class);
        $configManager->method('get')
            ->willReturnMap([
                ['oidc', 'permission_template_mapping', [], []],
                ['oidc', 'default_permission_template', '', 'Guest'],
            ]);

        $service = $this->createServiceWithMocks($configManager, $this->templateRepository(['Guest' => 5]));

        $method = (new ReflectionClass(UserProvisioningService::class))->getMethod('determinePermissionTemplate');
        $method->setAccessible(true);

        $result = $method->invoke($service, [], 'oidc', true);
        $this->assertEquals(5, $result, 'Should return default template ID when useDefaultFallback is true');
    }

    /**
     * Test determinePermissionTemplate returns mapped template when group matches
     */
    public function testDeterminePermissionTemplateWithMatchingGroup(): void
    {
        $configManager = $this->createMock(\Poweradmin\Infrastructure\Configuration\ConfigurationManager::class);
        $configManager->method('get')
            ->willReturnMap([
                ['oidc', 'permission_template_mapping', [], ['admins' => 'Administrator']],
            ]);

        $service = $this->createServiceWithMocks($configManager, $this->templateRepository(['Administrator' => 1]));

        $method = (new ReflectionClass(UserProvisioningService::class))->getMethod('determinePermissionTemplate');
        $method->setAccessible(true);

        $result = $method->invoke($service, ['admins', 'users'], 'oidc', false);
        $this->assertEquals(1, $result, 'Should return mapped template ID when user group matches mapping');
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

        // Test LDAP user info
        $result = $determineAuthMethod(new LdapUserInfo('test.user'));
        $this->assertEquals(UserProvisioningService::AUTH_METHOD_LDAP, $result, 'Should detect LDAP from LdapUserInfo');
    }

    public function testGroupMatchesRequiresTheWholeValue(): void
    {
        $groupMatches = $this->getPrivateMethodInvoker('groupMatches');
        $groups = ['cn=dns-admins,ou=groups,dc=example,dc=com', 'plain-group'];

        $this->assertTrue($groupMatches('plain-group', $groups), 'Exact value matches');
        $this->assertTrue($groupMatches('cn=dns-admins,ou=groups,dc=example,dc=com', $groups), 'Full DN matches');
        $this->assertFalse($groupMatches('dns-admins', $groups), 'A bare RDN value must not match a DN');
        $this->assertFalse($groupMatches('ou=groups', $groups), 'Later RDNs do not match');
        $this->assertFalse($groupMatches('dns-operators', $groups));
        $this->assertFalse($groupMatches('dns-admins', []), 'No groups, no match');
    }

    public function testGroupMatchesRejectsLookalikeDnInAnotherContainer(): void
    {
        // A directory user who can create groups in their own OU must not be able to
        // satisfy a mapping written for the same name in a different container.
        $groupMatches = $this->getPrivateMethodInvoker('groupMatches');
        $configuredDn = 'cn=dns-admins,ou=groups,dc=example,dc=com';

        $this->assertFalse(
            $groupMatches($configuredDn, ['cn=dns-admins,ou=lab,dc=example,dc=com']),
            'Same CN in a different OU must not match'
        );
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

    /**
     * Existing single-string mapping format must keep working:
     *   'team1' => 'Administrators'
     */
    public function testNormalizeMappedGroupNamesAcceptsLegacyStringValue(): void
    {
        $invoker = $this->getPrivateMethodInvoker('normalizeMappedGroupNames');

        $this->assertSame(['Administrators'], $invoker('Administrators'));
    }

    /**
     * New 1:n format from issue #1148:
     *   'team1' => ['lab1', 'lab2', 'customer1']
     */
    public function testNormalizeMappedGroupNamesExpandsArrayValue(): void
    {
        $invoker = $this->getPrivateMethodInvoker('normalizeMappedGroupNames');

        $this->assertSame(
            ['lab1', 'lab2', 'customer1'],
            $invoker(['lab1', 'lab2', 'customer1'])
        );
    }

    /**
     * An empty mapping value (legacy '' or modern []) should resolve to no groups
     * so existing configs without an entry don't trigger spurious group lookups.
     */
    public function testNormalizeMappedGroupNamesIgnoresEmptyValues(): void
    {
        $invoker = $this->getPrivateMethodInvoker('normalizeMappedGroupNames');

        $this->assertSame([], $invoker(''));
        $this->assertSame([], $invoker([]));
        $this->assertSame([], $invoker(null));
    }

    /**
     * Array form drops empty strings but keeps the order of remaining names,
     * so a misconfigured entry like ['lab1', '', 'lab2'] still maps the valid ones.
     */
    public function testNormalizeMappedGroupNamesFiltersEmptyStringsInArrays(): void
    {
        $invoker = $this->getPrivateMethodInvoker('normalizeMappedGroupNames');

        $this->assertSame(['lab1', 'lab2'], $invoker(['lab1', '', 'lab2']));
    }

    /**
     * Non-string array entries (numbers, nested arrays, objects) are skipped
     * rather than blowing up the group lookup with a TypeError.
     */
    public function testNormalizeMappedGroupNamesSkipsNonStringEntries(): void
    {
        $invoker = $this->getPrivateMethodInvoker('normalizeMappedGroupNames');

        $this->assertSame(['lab1'], $invoker(['lab1', 42, ['nested'], new \stdClass()]));
    }

    /**
     * A missing default template must not fall back to "the first template by id",
     * which is the bundled Administrator template on every shipped schema.
     */
    public function testDeterminePermissionTemplateFailsClosedWhenDefaultIsMissing(): void
    {
        $configManager = $this->createMock(\Poweradmin\Infrastructure\Configuration\ConfigurationManager::class);
        $configManager->method('get')
            ->willReturnMap([
                ['oidc', 'permission_template_mapping', [], []],
                ['oidc', 'default_permission_template', '', 'Guest'],
            ]);

        $service = $this->createServiceWithMocks($configManager, $this->templateRepository([]));

        $method = (new ReflectionClass(UserProvisioningService::class))->getMethod('determinePermissionTemplate');
        $method->setAccessible(true);

        $this->assertNull(
            $method->invoke($service, [], 'oidc', true),
            'A renamed or deleted default template must refuse provisioning, not pick template id 1'
        );
    }

    /**
     * @return array<string, array{mixed, bool}>
     */
    public static function emailVerifiedClaimProvider(): array
    {
        return [
            'verified bool true' => [true, true],
            'verified string true' => ['true', true],
            'verified int one' => [1, true],
            'unverified bool false' => [false, false],
            'unverified string false' => ['false', false],
            'unverified int zero' => [0, false],
            'unparseable value' => ['maybe', false],
        ];
    }

    #[DataProvider('emailVerifiedClaimProvider')]
    public function testEmailClaimIsLinkableHonoursEmailVerified(mixed $claim, bool $expected): void
    {
        $service = $this->createServiceWithMocks();

        $userInfo = $this->createMock(\Poweradmin\Domain\ValueObject\UserInfoInterface::class);
        $userInfo->method('getEmail')->willReturn('admin@tenant.test');
        $userInfo->method('getRawData')->willReturn(['email_verified' => $claim]);

        $method = (new ReflectionClass(UserProvisioningService::class))->getMethod('emailClaimIsLinkable');
        $method->setAccessible(true);

        $this->assertSame($expected, $method->invoke($service, $userInfo, []));
    }

    /**
     * SAML has no email_verified equivalent, so an absent claim stays permissive
     * and the superuser-account rule carries the protection.
     */
    public function testEmailClaimIsLinkableWhenClaimIsAbsent(): void
    {
        $service = $this->createServiceWithMocks();

        $userInfo = $this->createMock(\Poweradmin\Domain\ValueObject\UserInfoInterface::class);
        $userInfo->method('getEmail')->willReturn('admin@tenant.test');
        $userInfo->method('getRawData')->willReturn(['sub' => 'abc']);

        $method = (new ReflectionClass(UserProvisioningService::class))->getMethod('emailClaimIsLinkable');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($service, $userInfo, []));
    }

    /**
     * require_verified_email turns the permissive default around: a provider that
     * never states email_verified may no longer match an existing account.
     */
    public function testAbsentClaimIsRefusedWhenVerifiedEmailIsRequired(): void
    {
        $service = $this->createServiceWithMocks();

        $userInfo = $this->createMock(\Poweradmin\Domain\ValueObject\UserInfoInterface::class);
        $userInfo->method('getEmail')->willReturn('admin@tenant.test');
        $userInfo->method('getRawData')->willReturn(['sub' => 'abc']);

        $method = (new ReflectionClass(UserProvisioningService::class))->getMethod('emailClaimIsLinkable');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($service, $userInfo, ['require_verified_email' => true]));
        $this->assertTrue(
            $method->invoke($service, $userInfo, ['require_verified_email' => false]),
            'The option defaults off, so an absent claim stays linkable unless it is switched on'
        );
    }

    /**
     * The option only governs the absent case: a provider that says false is
     * refused either way.
     */
    public function testRequireVerifiedEmailDoesNotOverrideAnExplicitClaim(): void
    {
        $service = $this->createServiceWithMocks();

        $userInfo = $this->createMock(\Poweradmin\Domain\ValueObject\UserInfoInterface::class);
        $userInfo->method('getEmail')->willReturn('admin@tenant.test');
        $userInfo->method('getRawData')->willReturn(['email_verified' => true]);

        $method = (new ReflectionClass(UserProvisioningService::class))->getMethod('emailClaimIsLinkable');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($service, $userInfo, ['require_verified_email' => true]));
    }

    public function testUserHoldsSuperuserPermissionFailsClosedOnDatabaseError(): void
    {
        $repository = $this->createMock(UserRepositoryInterface::class);
        $repository->method('hasAdminPermission')->willThrowException(new \RuntimeException('connection lost'));
        $service = $this->createServiceWithMocks(null, $repository);

        $reflection = new ReflectionClass(UserProvisioningService::class);
        $method = $reflection->getMethod('userHoldsSuperuserPermission');
        $method->setAccessible(true);

        $this->assertTrue(
            $method->invoke($service, 1),
            'An unreadable permission state must block email linking rather than allow it'
        );
    }

    public function testUserHoldsSuperuserPermissionDelegatesToRepository(): void
    {
        $repository = $this->createMock(UserRepositoryInterface::class);
        $repository->method('hasAdminPermission')->willReturnCallback(
            static fn(int $userId): bool => $userId === 1
        );
        $service = $this->createServiceWithMocks(null, $repository);

        $reflection = new ReflectionClass(UserProvisioningService::class);
        $method = $reflection->getMethod('userHoldsSuperuserPermission');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($service, 1));
        $this->assertFalse($method->invoke($service, 2));
    }

    /**
     * @param array<string, int> $templates name => id known to the repository
     */
    private function templateRepository(array $templates): UserRepositoryInterface
    {
        $repository = $this->createMock(UserRepositoryInterface::class);
        $repository->method('templateGrantsUberuser')->willReturn(false);
        $repository->method('findPermissionTemplateIdByName')->willReturnCallback(
            static fn(string $name): ?int => $templates[$name] ?? null
        );

        return $repository;
    }
}
