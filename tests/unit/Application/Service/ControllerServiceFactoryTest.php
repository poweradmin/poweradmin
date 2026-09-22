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

namespace Poweradmin\Tests\Unit\Application\Service;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\Auth\ApiKeyActor;
use Poweradmin\Application\Service\ControllerServiceFactory;
use Poweradmin\Application\Service\Mail\EmailTemplateService;
use Poweradmin\Application\Service\Auth\LoginAttemptService;
use Poweradmin\Application\Service\Mail\MailService;
use Poweradmin\Application\Service\Auth\OidcConfigurationService;
use Poweradmin\Application\Service\User\PasswordGenerationService;
use Poweradmin\Application\Service\User\PasswordPolicyService;
use Poweradmin\Application\Service\Backend\PowerdnsStatusService;
use Poweradmin\Application\Service\Auth\RecaptchaService;
use Poweradmin\Application\Service\Auth\SamlConfigurationService;
use Poweradmin\Domain\Port\DnsBackendProviderInterface;
use Poweradmin\Domain\Service\DnsValidation\DnsValidatorRegistry;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Infrastructure\Service\ZoneSyncService;
use Psr\Log\NullLogger;
use ReflectionProperty;

/**
 * The factory must hand out per-request shared instances where state matters
 * (backend provider, permission cache) and fresh instances elsewhere.
 */
class ControllerServiceFactoryTest extends TestCase
{
    private function makeFactory(): ControllerServiceFactory
    {
        $config = $this->createMock(ConfigurationInterface::class);
        $config->method('get')->willReturnCallback(
            fn(string $group, string $key, $default = null) => ($group === 'database' && $key === 'type') ? 'mysql' : $default
        );

        return new ControllerServiceFactory(
            $this->createMock(PDO::class),
            $config,
            new NullLogger(),
            new ApiKeyActor(0, null)
        );
    }

    public function testServicesBuiltBeforeBindActorSeeTheReboundActor(): void
    {
        $factory = $this->makeFactory();
        $logger = $factory->recordChangeLog();
        $this->assertNull($factory->actor()->userId());

        $factory->bindActor(new ApiKeyActor(42, 'apikey-owner'));

        $this->assertSame(42, $factory->actor()->userId());
        $this->assertSame('apikey-owner', $factory->actor()->username());
        $this->assertSame($factory->actor(), (new ReflectionProperty($logger, 'actor'))->getValue($logger));
    }

    public function testDnsBackendProviderIsMemoized(): void
    {
        $factory = $this->makeFactory();

        $this->assertSame($factory->dnsBackendProvider(), $factory->dnsBackendProvider());
    }

    public function testPermissionServiceIsMemoized(): void
    {
        $factory = $this->makeFactory();

        $this->assertSame($factory->permissionService(), $factory->permissionService());
    }

    public function testUserPreferenceServiceIsMemoized(): void
    {
        $factory = $this->makeFactory();

        // Shared so the per-request preference cache spans all consumers
        $this->assertSame($factory->userPreferenceService(), $factory->userPreferenceService());
    }

    public function testAuthenticationServiceAndItsSessionAndRedirectPartsAreMemoized(): void
    {
        $factory = $this->makeFactory();

        $this->assertSame($factory->sessionService(), $factory->sessionService());
        $this->assertSame($factory->redirectService(), $factory->redirectService());
        $this->assertSame($factory->authenticationService(), $factory->authenticationService());
    }

    public function testClientContextIsResolvedOncePerRequest(): void
    {
        $factory = $this->makeFactory();

        $this->assertSame($factory->clientContext(), $factory->clientContext());
    }

    public function testMfaServiceAndItsRepositoryAreMemoized(): void
    {
        $factory = $this->makeFactory();

        $this->assertSame($factory->userMfaRepository(), $factory->userMfaRepository());
        $this->assertSame($factory->mfaService(), $factory->mfaService());
    }

    public function testRequestScopedRepositoriesAndProvisioningAreMemoized(): void
    {
        $factory = $this->makeFactory();

        $this->assertSame($factory->apiKeyRepository(), $factory->apiKeyRepository());
        $this->assertSame($factory->recordTypeDefaultRepository(), $factory->recordTypeDefaultRepository());
        $this->assertSame($factory->userProvisioningService(), $factory->userProvisioningService());
    }

    public function testRecordChangeLogIsTheSameInstanceAsTheWriter(): void
    {
        $factory = $this->makeFactory();

        $this->assertSame($factory->recordChangeLogger(), $factory->recordChangeLog());
    }

    public function testRepositoryFactoryMemoizesTheSharedProviderPath(): void
    {
        $factory = $this->makeFactory();

        $this->assertSame($factory->repositoryFactory(), $factory->repositoryFactory());

        // Controllers routinely hand back the very provider this factory memoized;
        // that must reuse the shared wiring rather than duplicate it
        $shared = $factory->repositoryFactory($factory->dnsBackendProvider());
        $this->assertSame($factory->repositoryFactory(), $shared);
    }

    public function testFlatAccessorsHandOutTheConcernFactoriesInstances(): void
    {
        $factory = $this->makeFactory();

        $this->assertSame($factory->backend()->dnsBackendProvider(), $factory->dnsBackendProvider());
        $this->assertSame($factory->users()->permissionService(), $factory->permissionService());
        $this->assertSame($factory->auth()->clientContext(), $factory->clientContext());
        $this->assertSame($factory->records()->recordChangeLogger(), $factory->recordChangeLogger());
        $this->assertSame($factory->zones()->zoneOwnershipModeService(), $factory->zoneOwnershipModeService());
    }

    public function testConcernFactoriesAreOnePerRequest(): void
    {
        $factory = $this->makeFactory();

        $this->assertSame($factory->backend(), $factory->backend());
        $this->assertSame($factory->users(), $factory->users());
        $this->assertSame($factory->auth(), $factory->auth());
        $this->assertSame($factory->zones(), $factory->zones());
        $this->assertSame($factory->records(), $factory->records());
        $this->assertSame($factory->backend()->repositoryFactory(), $factory->repositoryFactory());
    }

    public function testDomainAndRecordManagersAreMemoized(): void
    {
        $factory = $this->makeFactory();

        $this->assertSame($factory->domainManager(), $factory->domainManager());
        $this->assertSame($factory->recordManager(), $factory->recordManager());
        $this->assertSame($factory->zoneTemplateRepository(), $factory->zoneTemplateRepository());
        $this->assertSame($factory->zoneChangeRequestRepository(), $factory->zoneChangeRequestRepository());
    }

    public function testRepositoryFactoryGivesDedicatedWiringForAnotherProvider(): void
    {
        $factory = $this->makeFactory();

        $other = $this->createMock(DnsBackendProviderInterface::class);
        $explicit = $factory->repositoryFactory($other);

        $this->assertNotSame($factory->repositoryFactory(), $explicit);
    }

    /**
     * The collaborators controllers used to build inline are now handed out
     * once per request by the concern factory that owns them.
     */
    public function testFormerlyInlineCollaboratorsAreMemoizedOnTheirConcernFactory(): void
    {
        $factory = $this->makeFactory();

        $expected = [
            'mailService' => [MailService::class, $factory->auth()],
            'samlConfigurationService' => [SamlConfigurationService::class, $factory->auth()],
            'oidcConfigurationService' => [OidcConfigurationService::class, $factory->auth()],
            'recaptchaService' => [RecaptchaService::class, $factory->auth()],
            'loginAttemptService' => [LoginAttemptService::class, $factory->auth()],
            'powerdnsStatusService' => [PowerdnsStatusService::class, $factory->backend()],
            'zoneSyncService' => [ZoneSyncService::class, $factory->backend()],
            'dnsValidatorRegistry' => [DnsValidatorRegistry::class, $factory->records()],
            'emailTemplateService' => [EmailTemplateService::class, $factory->records()],
            'passwordPolicyService' => [PasswordPolicyService::class, $factory->users()],
            'passwordGenerationService' => [PasswordGenerationService::class, $factory->users()],
        ];

        foreach ($expected as $accessor => [$class, $owner]) {
            $instance = $factory->$accessor();
            $this->assertInstanceOf($class, $instance, $accessor);
            $this->assertSame($instance, $factory->$accessor(), "$accessor is memoized");
            $this->assertSame($instance, $owner->$accessor(), "$accessor delegates to its concern factory");
        }
    }
}
