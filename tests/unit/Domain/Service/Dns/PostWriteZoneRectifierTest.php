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

namespace Poweradmin\Tests\Unit\Domain\Service\Dns;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Port\ZoneRectifierInterface;
use Poweradmin\Domain\Service\Dns\PostWriteZoneRectifier;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use TestHelpers\FakeConfiguration;

#[CoversClass(PostWriteZoneRectifier::class)]
class PostWriteZoneRectifierTest extends TestCase
{
    public function testDisabledDnssecNeverBuildsTheProvider(): void
    {
        $built = false;
        $rectifier = new PostWriteZoneRectifier(
            new FakeConfiguration(['dnssec' => ['enabled' => false]]),
            function () use (&$built): ZoneRectifierInterface {
                $built = true;
                return $this->createMock(ZoneRectifierInterface::class);
            },
            new NullLogger()
        );

        $rectifier->rectify('example.com');

        $this->assertFalse($built);
    }

    public function testEnabledDnssecRectifiesThroughOneProvider(): void
    {
        $provider = $this->createMock(ZoneRectifierInterface::class);
        $provider->expects($this->exactly(2))->method('rectifyZone')->willReturn(true);
        $builds = 0;
        $rectifier = new PostWriteZoneRectifier(
            new FakeConfiguration(['dnssec' => ['enabled' => true]]),
            function () use (&$builds, $provider): ZoneRectifierInterface {
                $builds++;
                return $provider;
            },
            new NullLogger()
        );

        $rectifier->rectify('a.example');
        $rectifier->rectify('b.example');

        $this->assertSame(1, $builds);
    }

    public function testARefusedRectifyIsLoggedNotRaised(): void
    {
        $provider = $this->createMock(ZoneRectifierInterface::class);
        $provider->method('rectifyZone')->willReturn(false);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with('Zone rectify refused for {zone}', ['zone' => 'example.com']);

        (new PostWriteZoneRectifier(new FakeConfiguration(['dnssec' => ['enabled' => true]]), static fn() => $provider, $logger))
            ->rectify('example.com');
    }

    public function testAFailingRectifyIsLoggedNotRaised(): void
    {
        $provider = $this->createMock(ZoneRectifierInterface::class);
        $provider->method('rectifyZone')->willThrowException(new RuntimeException('connection refused'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')
            ->with('Zone rectify failed for {zone}: {error}', ['zone' => 'example.com', 'error' => 'connection refused']);

        (new PostWriteZoneRectifier(new FakeConfiguration(['dnssec' => ['enabled' => true]]), static fn() => $provider, $logger))
            ->rectify('example.com');
    }
}
