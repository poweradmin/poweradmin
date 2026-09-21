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

namespace Poweradmin\Tests\Unit\Domain\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\AuditService;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Domain\Port\DnssecProviderInterface;
use Poweradmin\Domain\Service\ZoneSigningOutcome;
use Poweradmin\Domain\Service\ZoneSigningService;
use Poweradmin\Domain\Service\ZoneValidationService;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Psr\Log\NullLogger;

/**
 * Signing is refused before anything moves, bumps the serial only when it
 * goes ahead, and reports what PowerDNS says afterwards rather than what
 * was asked for.
 */
#[CoversClass(ZoneSigningService::class)]
class ZoneSigningServiceTest extends TestCase
{
    private const ZONE_ID = 42;
    private const ZONE = 'example.com';

    private DnssecProviderInterface&MockObject $dnssec;
    private ZoneValidationService&MockObject $validator;
    private SOARecordManagerInterface&MockObject $soa;
    private AuditService&MockObject $audit;

    protected function setUp(): void
    {
        $this->dnssec = $this->createMock(DnssecProviderInterface::class);
        $this->dnssec->method('isDnssecEnabled')->willReturn(true);
        $this->validator = $this->createMock(ZoneValidationService::class);
        $this->validator->method('validateZoneForDnssec')->willReturn(['valid' => true, 'issues' => []]);
        $this->soa = $this->createMock(SOARecordManagerInterface::class);
        $this->audit = $this->createMock(AuditService::class);
    }

    public function testSigningBumpsTheSerialSecuresRectifiesAndAudits(): void
    {
        $this->dnssec->method('isZoneSecured')->willReturnOnConsecutiveCalls(false, true);
        $this->dnssec->expects($this->once())->method('secureZone')->with(self::ZONE)->willReturn(true);
        $this->dnssec->expects($this->once())->method('rectifyZone')->with(self::ZONE)->willReturn(true);
        $this->soa->expects($this->once())->method('updateSOASerial')->with(self::ZONE_ID)->willReturn(true);
        $this->audit->expects($this->once())->method('logDnssecSignZone')->with(self::ZONE_ID, self::ZONE);

        $this->assertSame(ZoneSigningOutcome::SIGNED, $this->service()->sign(self::ZONE_ID, self::ZONE)->outcome);
    }

    public function testAnInvalidZoneIsRefusedBeforeTheSerialMoves(): void
    {
        $this->validator = $this->createMock(ZoneValidationService::class);
        $this->validator->method('validateZoneForDnssec')->willReturn(['valid' => false, 'issues' => []]);
        $this->validator->method('getFormattedErrorMessage')->willReturn('No NS records');
        $this->dnssec->method('isZoneSecured')->willReturn(false);
        $this->dnssec->expects($this->never())->method('secureZone');
        $this->soa->expects($this->never())->method('updateSOASerial');

        $result = $this->service()->sign(self::ZONE_ID, self::ZONE);

        $this->assertSame(ZoneSigningOutcome::INVALID_ZONE, $result->outcome);
        $this->assertSame('No NS records', $result->detail);
    }

    public function testPresignedAndAlreadySignedZonesAreLeftAlone(): void
    {
        $this->dnssec->method('isZonePresigned')->willReturnOnConsecutiveCalls(true, false);
        $this->dnssec->method('isZoneSecured')->willReturn(true);
        $this->dnssec->expects($this->never())->method('secureZone');

        $this->assertSame(ZoneSigningOutcome::PRESIGNED, $this->service()->sign(self::ZONE_ID, self::ZONE)->outcome);
        $this->assertSame(ZoneSigningOutcome::ALREADY_SIGNED, $this->service()->sign(self::ZONE_ID, self::ZONE)->outcome);
    }

    public function testAProviderSuccessThatDoesNotShowUpIsReportedAsUnverified(): void
    {
        $this->dnssec->method('isZoneSecured')->willReturn(false);
        $this->dnssec->method('secureZone')->willReturn(true);
        $this->dnssec->expects($this->never())->method('rectifyZone');
        $this->audit->expects($this->never())->method('logDnssecSignZone');

        $this->assertSame(ZoneSigningOutcome::VERIFY_FAILED, $this->service()->sign(self::ZONE_ID, self::ZONE)->outcome);
    }

    public function testUnsigningBumpsTheSerialOnlyAfterItTook(): void
    {
        $this->dnssec->method('isZoneSecured')->willReturnOnConsecutiveCalls(true, false);
        $this->dnssec->expects($this->once())->method('unsecureZone')->with(self::ZONE)->willReturn(true);
        $this->soa->expects($this->once())->method('updateSOASerial')->with(self::ZONE_ID)->willReturn(true);
        $this->audit->expects($this->once())->method('logDnssecUnsignZone')->with(self::ZONE_ID, self::ZONE);

        $this->assertSame(ZoneSigningOutcome::UNSIGNED, $this->service()->unsign(self::ZONE_ID, self::ZONE)->outcome);
    }

    public function testAFailedUnsignLeavesTheSerialAlone(): void
    {
        $this->dnssec->method('isZoneSecured')->willReturn(true);
        $this->dnssec->method('unsecureZone')->willReturn(false);
        $this->soa->expects($this->never())->method('updateSOASerial');

        $this->assertSame(ZoneSigningOutcome::UNSECURE_FAILED, $this->service()->unsign(self::ZONE_ID, self::ZONE)->outcome);
    }

    private function service(): ZoneSigningService
    {
        return new ZoneSigningService(
            $this->dnssec,
            $this->validator,
            $this->soa,
            $this->audit,
            $this->createMock(ConfigurationInterface::class),
            new NullLogger()
        );
    }
}
