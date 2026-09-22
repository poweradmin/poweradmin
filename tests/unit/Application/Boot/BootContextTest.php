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

namespace Poweradmin\Tests\Unit\Application\Boot;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Boot\BootContext;
use Poweradmin\Application\Module\ModuleRegistry;
use Poweradmin\Application\Service\ControllerServiceFactory;
use Psr\Log\NullLogger;
use TestHelpers\BootContexts;
use TestHelpers\FakeConfiguration;
use TestHelpers\StubActor;

class BootContextTest extends TestCase
{
    public function testExposesWhatTheKernelBooted(): void
    {
        $config = new FakeConfiguration();
        $logger = new NullLogger();
        $registry = new ModuleRegistry($config);

        $context = new BootContext($config, $logger, $registry);

        $this->assertSame($config, $context->config);
        $this->assertSame($logger, $context->logger);
        $this->assertSame($registry, $context->moduleRegistry);
    }

    public function testOpensTheConfiguredDatabaseOnceOnFirstUse(): void
    {
        $context = BootContexts::over(new FakeConfiguration(['database' => ['type' => 'sqlite', 'file' => ':memory:']]));

        $db = $context->database();

        $this->assertInstanceOf(PDO::class, $db);
        $this->assertSame($db, $context->database());
    }

    public function testHandsOutAPreOpenedDatabaseInsteadOfConnecting(): void
    {
        $db = $this->createMock(PDO::class);

        $context = BootContexts::over(new FakeConfiguration(), $db);

        $this->assertSame($db, $context->database());
    }

    public function testServicesBuildTheGraphOverTheContextsDatabase(): void
    {
        $context = BootContexts::over(new FakeConfiguration(['dns' => ['backend' => 'sql']]), $this->createMock(PDO::class));

        $services = $context->services(new StubActor(7));

        $this->assertInstanceOf(ControllerServiceFactory::class, $services);
        $this->assertSame(7, $services->actor()->userId());
    }
}
