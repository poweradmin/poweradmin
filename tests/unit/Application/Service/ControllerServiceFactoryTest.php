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
use Poweradmin\Application\Service\ControllerServiceFactory;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Psr\Log\NullLogger;

/**
 * The factory must hand out per-request shared instances where state matters
 * (backend provider, permission cache) and fresh instances elsewhere.
 */
class ControllerServiceFactoryTest extends TestCase
{
    private function makeFactory(): ControllerServiceFactory
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            fn(string $group, string $key, $default = null) => ($group === 'database' && $key === 'type') ? 'mysql' : $default
        );

        return new ControllerServiceFactory(
            $this->createMock(PDO::class),
            $config,
            new NullLogger()
        );
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

    public function testRepositoryFactoryMemoizesTheSharedProviderPath(): void
    {
        $factory = $this->makeFactory();

        $this->assertSame($factory->repositoryFactory(), $factory->repositoryFactory());

        // Controllers routinely hand back the very provider this factory memoized;
        // that must reuse the shared wiring rather than duplicate it
        $shared = $factory->repositoryFactory($factory->dnsBackendProvider());
        $this->assertSame($factory->repositoryFactory(), $shared);
    }

    public function testRepositoryFactoryGivesDedicatedWiringForAnotherProvider(): void
    {
        $factory = $this->makeFactory();

        $other = $this->createMock(DnsBackendProvider::class);
        $explicit = $factory->repositoryFactory($other);

        $this->assertNotSame($factory->repositoryFactory(), $explicit);
    }
}
