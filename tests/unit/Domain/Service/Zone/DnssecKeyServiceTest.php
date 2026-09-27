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

namespace Poweradmin\Tests\Unit\Domain\Service\Zone;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\CryptoKey;
use Poweradmin\Domain\Model\PdnsCapabilities;
use Poweradmin\Domain\Port\AuditLoggerInterface;
use Poweradmin\Domain\Port\DnssecProviderInterface;
use Poweradmin\Domain\Service\Validation\Refusal;
use Poweradmin\Domain\Service\Zone\DnssecKeyOutcome;
use Poweradmin\Domain\Service\Zone\DnssecKeyService;

#[CoversClass(DnssecKeyService::class)]
class DnssecKeyServiceTest extends TestCase
{
    private const ZONE_ID = 7;
    private const ZONE = 'example.com';

    /** @var DnssecProviderInterface&MockObject */
    private DnssecProviderInterface $dnssec;

    /** @var AuditLoggerInterface&MockObject */
    private AuditLoggerInterface $audit;

    private bool $enabled = true;
    private bool $presigned = false;

    /** @var CryptoKey[]|null */
    private ?array $keys = [];

    protected function setUp(): void
    {
        $this->dnssec = $this->createMock(DnssecProviderInterface::class);
        $this->dnssec->method('isDnssecEnabled')->willReturnCallback(fn(): bool => $this->enabled);
        $this->dnssec->method('isZonePresigned')->willReturnCallback(fn(): bool => $this->presigned);
        $this->audit = $this->createMock(AuditLoggerInterface::class);
    }

    private function service(): DnssecKeyService
    {
        $this->dnssec->method('fetchZoneKeys')->willReturnCallback(fn(): ?array => $this->keys);

        return new DnssecKeyService($this->dnssec, $this->audit);
    }

    private static function key(int $id, bool $active): CryptoKey
    {
        return new CryptoKey($id, 'zsk', 256, 'ECDSAP256SHA256', $active, '256 3 13 AAAA', []);
    }

    private static function caps(string $version = '4.9.0'): PdnsCapabilities
    {
        return PdnsCapabilities::fromServerInfo(['version' => $version]);
    }

    public function testListTellsAnOutageApartFromAZoneWithoutKeys(): void
    {
        $this->assertSame(DnssecKeyOutcome::LISTED, $this->service()->listKeys(self::ZONE)->outcome);

        $this->keys = null;
        $result = $this->service()->listKeys(self::ZONE);

        $this->assertSame(DnssecKeyOutcome::UNREACHABLE, $result->outcome);
        $this->assertSame(Refusal::BACKEND_UNREACHABLE, $result->refusal);
    }

    public function testListReturnsTheKeys(): void
    {
        $this->keys = [self::key(1, true), self::key(2, false)];

        $result = $this->service()->listKeys(self::ZONE);

        $this->assertSame($this->keys, $result->keys);
        $this->assertNull($result->refusal);
    }

    public function testFindTellsFoundMissingAndUnreachableApart(): void
    {
        $this->keys = [self::key(3, true)];
        $found = $this->service()->findKey(self::ZONE, 3);
        $this->assertSame(DnssecKeyOutcome::FOUND, $found->outcome);
        $this->assertSame(3, $found->key?->getId());

        $missing = $this->service()->findKey(self::ZONE, 4);
        $this->assertSame(DnssecKeyOutcome::NOT_FOUND, $missing->outcome);
        $this->assertSame(Refusal::NOT_FOUND, $missing->refusal);

        $this->keys = null;
        $this->assertSame(DnssecKeyOutcome::UNREACHABLE, $this->service()->findKey(self::ZONE, 3)->outcome);
    }

    /** @return array<string, array{0: string, 1: string, 2: ?int, 3: DnssecKeyOutcome}> */
    public static function invalidKeyProvider(): array
    {
        return [
            'unknown type' => ['rsa', 'ecdsa256', 256, DnssecKeyOutcome::INVALID_TYPE],
            'upper-case type' => ['KSK', 'ecdsa256', 256, DnssecKeyOutcome::INVALID_TYPE],
            'unknown algorithm' => ['ksk', 'gost', 256, DnssecKeyOutcome::INVALID_ALGORITHM],
            'size mismatch' => ['ksk', 'ecdsa256', 384, DnssecKeyOutcome::INVALID_BITS],
            'no size' => ['ksk', 'ecdsa256', null, DnssecKeyOutcome::INVALID_BITS],
        ];
    }

    #[DataProvider('invalidKeyProvider')]
    public function testValidationRefusesInOrder(string $type, string $algorithm, ?int $bits, DnssecKeyOutcome $expected): void
    {
        $result = $this->service()->validateNewKey($type, $algorithm, $bits, self::caps());

        $this->assertSame($expected, $result?->outcome);
        $this->assertSame(Refusal::INVALID_INPUT, $result->refusal);
    }

    public function testValidationNamesTheAcceptedValues(): void
    {
        $algorithm = $this->service()->validateNewKey('csk', 'gost', 256, self::caps());
        $this->assertNotNull($algorithm);
        $this->assertContains('ecdsa256', $algorithm->allowedAlgorithms);
        $this->assertContains('ed448', $algorithm->allowedAlgorithms);

        $bits = $this->service()->validateNewKey('csk', 'rsasha256', 768, self::caps());
        $this->assertSame([1024, 2048], $bits?->acceptedBits);

        $this->assertNull($this->service()->validateNewKey('csk', 'ecdsa256', 256, self::caps()));
    }

    public function testEd448IsOnlyOfferedByServersThatSupportIt(): void
    {
        $old = $this->service()->validateNewKey('csk', 'ed448', 456, self::caps('4.4.0'));

        $this->assertSame(DnssecKeyOutcome::INVALID_ALGORITHM, $old?->outcome);
        $this->assertNotContains('ed448', $old->allowedAlgorithms);
        $this->assertNull($this->service()->validateNewKey('csk', 'ed448', 456, self::caps('4.5.0')));
    }

    public function testAnInvalidKeyIsRefusedBeforePowerDnsIsAsked(): void
    {
        $this->dnssec->expects($this->never())->method('fetchZoneKeys');
        $this->dnssec->expects($this->never())->method('createZoneKey');
        $this->audit->expects($this->never())->method($this->anything());

        $result = (new DnssecKeyService($this->dnssec, $this->audit))->addKey(self::ZONE_ID, self::ZONE, 'csk', 'ecdsa256', 384, false, self::caps());

        $this->assertSame(DnssecKeyOutcome::INVALID_BITS, $result->outcome);
    }

    public function testAddCreatesTheKeyAndAuditsIt(): void
    {
        $created = new CryptoKey(9, 'csk', 256, 'ECDSAP256SHA256', true, '257 3 13 AAAA', ['1 13 2 AB']);
        $this->dnssec->expects($this->once())->method('createZoneKey')->with(self::ZONE, 'csk', 256, 'ecdsa256', true)->willReturn($created);
        $this->audit->expects($this->once())->method('logDnssecAddKey')->with(self::ZONE_ID, self::ZONE, 'csk', '256', 'ecdsa256');

        $result = $this->service()->addKey(self::ZONE_ID, self::ZONE, 'csk', 'ecdsa256', 256, true, self::caps());

        $this->assertSame(DnssecKeyOutcome::ADDED, $result->outcome);
        $this->assertSame($created, $result->key);
        $this->assertNull($result->refusal);
    }

    /** @return array<string, array{0: string}> */
    public static function operationProvider(): array
    {
        return [
            'add' => ['add'],
            'activate' => ['activate'],
            'toggle' => ['toggle'],
            'remove' => ['remove'],
        ];
    }

    private function runOperation(string $operation): \Poweradmin\Domain\Service\Zone\DnssecKeyResult
    {
        $service = $this->service();

        return match ($operation) {
            'add' => $service->addKey(self::ZONE_ID, self::ZONE, 'csk', 'ecdsa256', 256, false, self::caps()),
            'activate' => $service->setKeyActive(self::ZONE_ID, self::ZONE, 3, true),
            'toggle' => $service->toggleKey(self::ZONE_ID, self::ZONE, 3),
            default => $service->removeKey(self::ZONE_ID, self::ZONE, 3),
        };
    }

    private function expectNoWrite(): void
    {
        foreach (['createZoneKey', 'activateZoneKey', 'deactivateZoneKey', 'removeZoneKey'] as $write) {
            $this->dnssec->expects($this->never())->method($write);
        }
        $this->audit->expects($this->never())->method($this->anything());
    }

    #[DataProvider('operationProvider')]
    public function testAnUnreachablePowerDnsIsNotReadAsDnssecOff(string $operation): void
    {
        $this->keys = null;
        $this->enabled = false;
        $this->dnssec->expects($this->never())->method('isDnssecEnabled');
        $this->expectNoWrite();

        $result = $this->runOperation($operation);

        $this->assertSame(DnssecKeyOutcome::UNREACHABLE, $result->outcome);
        $this->assertSame(Refusal::BACKEND_UNREACHABLE, $result->refusal);
    }

    #[DataProvider('operationProvider')]
    public function testAServerWithoutDnssecRefusesChanges(string $operation): void
    {
        $this->keys = [self::key(3, false)];
        $this->enabled = false;
        $this->expectNoWrite();

        $result = $this->runOperation($operation);

        $this->assertSame(DnssecKeyOutcome::SERVER_DISABLED, $result->outcome);
        $this->assertSame(Refusal::INVALID_INPUT, $result->refusal);
    }

    #[DataProvider('operationProvider')]
    public function testAPresignedZoneRefusesChanges(string $operation): void
    {
        $this->keys = [self::key(3, false)];
        $this->presigned = true;
        $this->expectNoWrite();

        $result = $this->runOperation($operation);

        $this->assertSame(DnssecKeyOutcome::PRESIGNED, $result->outcome);
        $this->assertSame(Refusal::CONFLICT, $result->refusal);
    }

    #[DataProvider('operationProvider')]
    public function testARefusedWriteFailsAndIsNotAudited(string $operation): void
    {
        $this->keys = [self::key(3, false)];
        $this->dnssec->method('createZoneKey')->willReturn(null);
        $this->dnssec->method('activateZoneKey')->willReturn(false);
        $this->dnssec->method('removeZoneKey')->willReturn(false);
        $this->audit->expects($this->never())->method($this->anything());

        $result = $this->runOperation($operation);

        $this->assertSame(DnssecKeyOutcome::FAILED, $result->outcome);
        $this->assertSame(Refusal::BACKEND_FAILURE, $result->refusal);
    }

    /** @return array<string, array{0: string}> */
    public static function keyOperationProvider(): array
    {
        return ['activate' => ['activate'], 'toggle' => ['toggle'], 'remove' => ['remove']];
    }

    #[DataProvider('keyOperationProvider')]
    public function testAnUnknownKeyIsNotFound(string $operation): void
    {
        $this->keys = [self::key(4, false)];
        $this->expectNoWrite();

        $this->assertSame(DnssecKeyOutcome::NOT_FOUND, $this->runOperation($operation)->outcome);
    }

    #[DataProvider('operationProvider')]
    public function testEachOperationAsksForTheKeysOnce(string $operation): void
    {
        $this->dnssec->expects($this->once())->method('fetchZoneKeys')->willReturn([self::key(3, false)]);
        $this->dnssec->method('createZoneKey')->willReturn(self::key(9, false));
        $this->dnssec->method('activateZoneKey')->willReturn(true);
        $this->dnssec->method('removeZoneKey')->willReturn(true);

        $this->assertNull($this->runOperation($operation)->refusal);
    }

    public function testDeactivatingChangesTheKeyAndAudits(): void
    {
        $this->keys = [self::key(3, true)];
        $this->dnssec->expects($this->once())->method('deactivateZoneKey')->with(self::ZONE, 3)->willReturn(true);
        $this->audit->expects($this->once())->method('logDnssecToggleKey')->with(self::ZONE_ID, self::ZONE, 3, 'deactivate');

        $result = $this->service()->setKeyActive(self::ZONE_ID, self::ZONE, 3, false);

        $this->assertSame(DnssecKeyOutcome::UPDATED, $result->outcome);
        $this->assertFalse($result->key?->isActive());
    }

    public function testTheCurrentStateIsLeftAlone(): void
    {
        $this->keys = [self::key(3, true)];
        $this->expectNoWrite();

        $result = $this->service()->setKeyActive(self::ZONE_ID, self::ZONE, 3, true);

        $this->assertSame(DnssecKeyOutcome::UNCHANGED, $result->outcome);
        $this->assertNull($result->refusal);
        $this->assertTrue($result->key?->isActive());
    }

    public function testToggleFlipsTheCurrentState(): void
    {
        $this->keys = [self::key(3, false)];
        $this->dnssec->expects($this->once())->method('activateZoneKey')->with(self::ZONE, 3)->willReturn(true);
        $this->dnssec->expects($this->never())->method('deactivateZoneKey');
        $this->audit->expects($this->once())->method('logDnssecToggleKey')->with(self::ZONE_ID, self::ZONE, 3, 'activate');

        $result = $this->service()->toggleKey(self::ZONE_ID, self::ZONE, 3);

        $this->assertSame(DnssecKeyOutcome::UPDATED, $result->outcome);
        $this->assertTrue($result->key?->isActive());
    }

    public function testAFailedToggleReportsTheKeyAsItStillIs(): void
    {
        $this->keys = [self::key(3, true)];
        $this->dnssec->method('deactivateZoneKey')->willReturn(false);

        $result = $this->service()->toggleKey(self::ZONE_ID, self::ZONE, 3);

        $this->assertSame(DnssecKeyOutcome::FAILED, $result->outcome);
        $this->assertTrue($result->key?->isActive());
    }

    public function testRemoveDeletesTheKeyAndAudits(): void
    {
        $this->keys = [self::key(3, true)];
        $this->dnssec->expects($this->once())->method('removeZoneKey')->with(self::ZONE, 3)->willReturn(true);
        $this->audit->expects($this->once())->method('logDnssecDeleteKey')->with(self::ZONE_ID, self::ZONE, 3);

        $this->assertSame(DnssecKeyOutcome::REMOVED, $this->service()->removeKey(self::ZONE_ID, self::ZONE, 3)->outcome);
    }
}
