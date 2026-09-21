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

namespace Poweradmin\Tests\Unit\Application\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\AuditService;
use Poweradmin\Application\Service\ZoneBatchCreateOutcome;
use Poweradmin\Application\Service\ZoneCreateOutcome;
use Poweradmin\Application\Service\ZoneCreateRequest;
use Poweradmin\Application\Service\ZoneCreateService;
use Poweradmin\Application\Service\ZoneOwnershipFormResolver;
use Poweradmin\Domain\Service\Auth\ApiPermissionService;
use Poweradmin\Domain\Service\ZoneManagementService;
use Poweradmin\Domain\Service\ZoneOwnershipResolution;
use Poweradmin\Domain\Service\ZoneSigningOutcome;
use Poweradmin\Domain\Service\ZoneSigningResult;

/**
 * Every zone creation form goes through one flow: the name is normalised,
 * the owners resolved once, the zone created and audited, and each refusal
 * worded for the form.
 */
#[CoversClass(ZoneCreateService::class)]
#[CoversClass(ZoneCreateOutcome::class)]
#[CoversClass(ZoneBatchCreateOutcome::class)]
class ZoneCreateServiceTest extends TestCase
{
    private const CALLER = 7;

    private ZoneOwnershipResolution $ownership;
    private bool $dnssecAllowed = true;

    /** @var list<array<string, mixed>> */
    private array $createResults = [];

    /** @var list<array<int, mixed>> */
    private array $createCalls = [];

    /** @var list<array<int, mixed>> */
    private array $audited = [];

    protected function setUp(): void
    {
        $this->ownership = ZoneOwnershipResolution::success(self::CALLER, []);
    }

    private function service(): ZoneCreateService
    {
        $resolver = $this->createMock(ZoneOwnershipFormResolver::class);
        $resolver->method('resolveInputs')->willReturnCallback(fn(): ZoneOwnershipResolution => $this->ownership);

        $zones = $this->createMock(ZoneManagementService::class);
        $zones->method('createZone')->willReturnCallback(function (...$args): array {
            $this->createCalls[] = $args;
            return array_shift($this->createResults)
                ?? ['success' => true, 'zone_id' => count($this->createCalls), 'domain' => $args[0], 'type' => $args[1], 'dnssec' => null];
        });

        $permissions = $this->createMock(ApiPermissionService::class);
        $permissions->method('canManageDnssecForNewZone')->willReturnCallback(fn(): bool => $this->dnssecAllowed);

        $audit = $this->createMock(AuditService::class);
        foreach (['logZoneAdd', 'logSecondaryZoneImport'] as $method) {
            $audit->method($method)->willReturnCallback(function (...$args) use ($method): void {
                $this->audited[] = [$method, ...$args];
            });
        }

        return new ZoneCreateService($resolver, $zones, $permissions, $audit);
    }

    /** @param array<string, mixed> $overrides */
    private static function request(array $overrides = []): ZoneCreateRequest
    {
        return new ZoneCreateRequest(...$overrides + [
            'name' => 'example.com',
            'type' => 'MASTER',
            'ownerInput' => '7',
            'groupsInput' => null,
            'callerUserId' => self::CALLER,
        ]);
    }

    public function testTheNameIsTrimmedAndStoredAsPunycode(): void
    {
        $outcome = $this->service()->create(self::request(['name' => ' Bücher.example ']));

        $this->assertTrue($outcome->success);
        $this->assertSame('xn--bcher-kva.example', $outcome->zoneName);
        $this->assertSame('xn--bcher-kva.example', $this->createCalls[0][0]);
    }

    public function testTheReverseFormTurnsANetworkIntoTheArpaZone(): void
    {
        $outcome = $this->service()->create(self::request(['name' => '192.168.1.0/24', 'reverseNetwork' => true]));

        $this->assertSame('1.168.192.in-addr.arpa', $outcome->zoneName);
        $this->assertTrue($outcome->isReverseZone());
    }

    public function testTheReverseFormRefusesAForwardNameBeforeResolvingOwners(): void
    {
        $outcome = $this->service()->create(self::request(['name' => 'example.com', 'reverseNetwork' => true]));

        $this->assertFalse($outcome->success);
        $this->assertStringStartsWith('Enter a network in CIDR notation', (string)$outcome->message);
        $this->assertSame('example.com', $outcome->zoneName);
        $this->assertCount(0, $this->createCalls);
    }

    public function testAnOwnershipRefusalIsWordedForTheFormAndStopsTheCreate(): void
    {
        $this->ownership = ZoneOwnershipResolution::error('api wording', 400, ZoneOwnershipResolution::UNKNOWN_OWNER, [42]);

        $outcome = $this->service()->create(self::request());

        $this->assertFalse($outcome->success);
        $this->assertSame('Unknown user ID: 42', $outcome->message);
        $this->assertCount(0, $this->createCalls);
    }

    public function testSigningNeedsTheDnssecPermissionForTheResolvedOwners(): void
    {
        $this->dnssecAllowed = false;

        $outcome = $this->service()->create(self::request(['signRequested' => true]));

        $this->assertSame('You do not have permission to manage DNSSEC for this zone.', $outcome->message);
        $this->assertCount(0, $this->createCalls);
    }

    public function testTheResolvedOwnersAndEveryOptionReachTheZoneService(): void
    {
        $this->ownership = ZoneOwnershipResolution::success(4, [2, 3]);
        $signed = new ZoneSigningResult(ZoneSigningOutcome::SIGNED);
        $this->createResults = [['success' => true, 'zone_id' => 9, 'domain' => 'example.com', 'type' => 'MASTER', 'dnssec' => $signed]];

        $outcome = $this->service()->create(self::request([
            'slaveMaster' => '192.0.2.53',
            'template' => '7',
            'signRequested' => true,
            'soaEditApi' => 'EPOCH',
        ]));

        $this->assertSame(['example.com', 'MASTER', 4, '192.0.2.53', '7', true, [2, 3], self::CALLER, 'EPOCH'], $this->createCalls[0]);
        $this->assertSame(9, $outcome->zoneId);
        $this->assertSame($signed, $outcome->dnssec);
        $this->assertSame([['logZoneAdd', 9, 'example.com', 'MASTER', '7', '192.0.2.53']], $this->audited);
    }

    public function testARefusedCreationIsWordedForTheFormAndNotAudited(): void
    {
        $this->createResults = [['success' => false, 'message' => 'Domain already exists', 'status' => 409, 'code' => ZoneManagementService::ERR_EXISTS]];

        $outcome = $this->service()->create(self::request());

        $this->assertFalse($outcome->success);
        $this->assertSame('There is already a zone with this name.', $outcome->message);
        $this->assertSame([], $this->audited);
    }

    public function testAReplicatingZoneIsAuditedWithoutATemplate(): void
    {
        $this->service()->create(self::request(['type' => 'SLAVE', 'slaveMaster' => '192.0.2.53']));

        $this->assertSame([['logZoneAdd', 1, 'example.com', 'SLAVE', null, '192.0.2.53']], $this->audited);
    }

    public function testAnImportIsAuditedAsOne(): void
    {
        $this->service()->create(self::request(['type' => 'SLAVE', 'slaveMaster' => '192.0.2.53', 'importedFromPrimary' => true]));

        $this->assertSame([['logSecondaryZoneImport', 1, 'example.com', '192.0.2.53']], $this->audited);
    }

    // ------------------------------------------------------------ batches

    public function testABatchResolvesTheOwnersOnceAndTriesEveryName(): void
    {
        $this->ownership = ZoneOwnershipResolution::success(4, [2]);
        $this->createResults = [
            ['success' => false, 'message' => 'Invalid domain name', 'status' => 400, 'code' => ZoneManagementService::ERR_INVALID_NAME],
        ];

        $batch = $this->service()->createMany(self::request(['name' => '', 'template' => '7']), ['bad..example', 'good.example']);

        $this->assertNull($batch->message);
        $this->assertSame([['name' => 'bad..example', 'reason' => 'Invalid hostname.']], $batch->failed());
        $this->assertSame(['good.example'], $batch->added());
        $this->assertSame([4, [2]], [$this->createCalls[1][2], $this->createCalls[1][6]]);
        $this->assertSame([['logZoneAdd', 2, 'good.example', 'MASTER', '7', null]], $this->audited);
    }

    public function testABatchIsStoppedByAnOwnershipRefusalBeforeAnyNameIsTried(): void
    {
        $this->ownership = ZoneOwnershipResolution::error('api wording', 400, ZoneOwnershipResolution::NO_OWNER);

        $batch = $this->service()->createMany(self::request(['name' => '']), ['one.example', 'two.example']);

        $this->assertSame('At least one user or group must be selected as owner.', $batch->message);
        $this->assertSame([], $batch->outcomes);
        $this->assertCount(0, $this->createCalls);
    }
}
