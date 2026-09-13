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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\ControllerServiceFactory;
use Poweradmin\BaseController;
use Poweradmin\Domain\Service\DnssecProvider;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Every record write, web or API, must rectify the zone or signed zones served
 * from SQL answer with stale NSEC/NSEC3 data.
 */
#[CoversClass(BaseController::class)]
class BaseControllerRectifyTest extends TestCase
{
    private DnssecProvider&MockObject $dnssecProvider;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dnssecProvider = $this->createMock(DnssecProvider::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function rectify(bool $dnssecEnabled, string $zoneName): void
    {
        $controller = new class extends BaseController {
            // Skip BaseController's bootstrap (config, DB, session).
            public function __construct()
            {
            }

            public function run(): void
            {
            }

            public function probe(string $zoneName): void
            {
                $this->rectifyZoneAfterWrite($zoneName);
            }
        };

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->with('dnssec', 'enabled', false)->willReturn($dnssecEnabled);
        $factory = $this->createMock(ControllerServiceFactory::class);
        $factory->method('dnssecProvider')->willReturn($this->dnssecProvider);

        $base = new ReflectionClass(BaseController::class);
        foreach (['config' => $config, 'serviceFactory' => $factory, 'logger' => $this->logger] as $name => $value) {
            $base->getProperty($name)->setValue($controller, $value);
        }

        $controller->probe($zoneName);
    }

    public function testRectifiesTheZoneWhenDnssecIsEnabled(): void
    {
        $this->dnssecProvider->expects($this->once())->method('rectifyZone')->with('example.com');

        $this->rectify(true, 'example.com');
    }

    public function testDoesNothingWhenDnssecIsDisabled(): void
    {
        $this->dnssecProvider->expects($this->never())->method('rectifyZone');

        $this->rectify(false, 'example.com');
    }

    public function testRectifyFailureIsLoggedNotThrown(): void
    {
        $this->dnssecProvider->method('rectifyZone')->willThrowException(new \RuntimeException('pdnsutil missing'));
        $this->logger->expects($this->once())->method('warning');

        $this->rectify(true, 'example.com');
    }
}
