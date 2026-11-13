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

namespace Poweradmin\Tests\Unit\Application\Service\Factory;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\Auth\ApiKeyActor;
use Poweradmin\Application\Service\ControllerServiceFactory;
use Poweradmin\Domain\Service\Zone\DnssecKeyOutcome;
use Poweradmin\Domain\Service\Zone\ZoneSigningOutcome;
use Poweradmin\Infrastructure\Session\ArraySession;
use Psr\Log\NullLogger;
use TestHelpers\FakeConfiguration;

/**
 * dnssec.enabled switches the signing and key services off for every entry point,
 * web pages and API alike, because both build them here.
 */
class ZoneServicesDnssecFlagTest extends TestCase
{
    private function factory(bool $dnssecEnabled): ControllerServiceFactory
    {
        return new ControllerServiceFactory(
            $this->createMock(PDO::class),
            new FakeConfiguration(['database' => ['type' => 'mysql'], 'dns' => ['backend' => 'sql'], 'dnssec' => ['enabled' => $dnssecEnabled]]),
            new NullLogger(),
            new ApiKeyActor(7, 'alice'),
            new ArraySession()
        );
    }

    public function testSigningAndUnsigningAreRefusedWhileDnssecIsOff(): void
    {
        $service = $this->factory(false)->zoneSigningService();

        $this->assertSame(ZoneSigningOutcome::SERVER_DISABLED, $service->sign(1, 'example.com')->outcome);
        $this->assertSame(ZoneSigningOutcome::SERVER_DISABLED, $service->unsign(1, 'example.com')->outcome);
    }

    public function testKeyChangesAreRefusedWhileDnssecIsOff(): void
    {
        $service = $this->factory(false)->dnssecKeyService();

        $this->assertSame(DnssecKeyOutcome::SERVER_DISABLED, $service->removeKey(1, 'example.com', 5)->outcome);
        $this->assertSame(DnssecKeyOutcome::SERVER_DISABLED, $service->toggleKey(1, 'example.com', 5)->outcome);
    }
}
