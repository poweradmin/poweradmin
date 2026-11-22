<?php

namespace Tests\Unit\Dns;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\Dns\SOARecordManager;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use PDO;

/**
 * A bumped serial stays within the 32-bit unsigned range and never reads as older (RFC 1982).
 */
class SOARecordManagerSerialClampTest extends TestCase
{
    private SOARecordManager $soaRecordManager;

    protected function setUp(): void
    {
        $this->soaRecordManager = new SOARecordManager(
            $this->createMock(PDO::class),
            $this->createMock(ConfigurationManager::class)
        );
    }

    public function testASerialAtTheCeilingWraps(): void
    {
        $this->assertSame(1, $this->soaRecordManager->getNextSerial(4294967295));
    }

    #[DataProvider('largeSerialProvider')]
    public function testGetNextSerialStaysWithin32Bits(int $currentSerial): void
    {
        $next = $this->soaRecordManager->getNextSerial($currentSerial);

        $this->assertLessThanOrEqual(4294967295, (float)$next, sprintf('serial %s produced %s', $currentSerial, $next));
    }

    public static function largeSerialProvider(): array
    {
        return [
            'signed 32-bit maximum' => [2147483647],
            'just below the ceiling' => [4294967290],
            'at the ceiling' => [4294967295],
            'plausible future date serial' => [4294123100],
        ];
    }

    #[DataProvider('unparseableDateSerialProvider')]
    public function testGetNextSerialNeverGoesBackwardsForASerialWithoutARealDate(int $currentSerial): void
    {
        $next = (int)$this->soaRecordManager->getNextSerial($currentSerial);

        $diff = ($next - $currentSerial) & 0xFFFFFFFF;
        $this->assertTrue($diff > 0 && $diff < 0x80000000, sprintf('serial %d produced %d', $currentSerial, $next));
    }

    public static function unparseableDateSerialProvider(): array
    {
        return [
            'month 11, day 99' => [2026119999],
            'invalid month' => [2026139999],
            'future date past the signed range' => [2147483699],
        ];
    }

    #[DataProvider('futureUnparseableSerialProvider')]
    public function testGetNextSerialBumpsAFutureSerialWithoutARealDateByOne(int $currentSerial): void
    {
        $this->assertSame($currentSerial + 1, (int)$this->soaRecordManager->getNextSerial($currentSerial));
    }

    public static function futureUnparseableSerialProvider(): array
    {
        return [
            'month 13 far in the future' => [4000139999],
            'future date past the signed range' => [2147483699],
        ];
    }
}
