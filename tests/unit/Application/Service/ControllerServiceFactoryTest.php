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

    public function testUserPreferenceServiceIsFreshPerCall(): void
    {
        $factory = $this->makeFactory();

        $this->assertNotSame($factory->userPreferenceService(), $factory->userPreferenceService());
    }

    public function testRepositoryFactoryUsesExplicitProviderWhenGiven(): void
    {
        $factory = $this->makeFactory();

        // Passing no provider must not fail and must reuse the memoized one
        $this->assertNotNull($factory->repositoryFactory());
        $this->assertNotNull($factory->repositoryFactory($factory->dnsBackendProvider()));
    }
}
