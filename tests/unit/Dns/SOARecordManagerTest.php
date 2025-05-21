<?php

namespace Tests\Unit\Dns;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\Dns\SOARecordManager;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOCommon;

class SOARecordManagerTest extends TestCase
{
    private $dbMock;
    private $configMock;
    private $soaRecordManager;

    protected function setUp(): void
    {
        $this->dbMock = $this->createMock(PDOCommon::class);
        $this->configMock = $this->createMock(ConfigurationManager::class);
        $this->soaRecordManager = new SOARecordManager($this->dbMock, $this->configMock);
    }

    #[DataProvider('soaSerialProvider')]
    public function testGetSOASerial(string $soaRec, ?string $expected): void
    {
        $this->assertEquals($expected, SOARecordManager::getSOASerial($soaRec));
    }

    public static function soaSerialProvider(): array
    {
        return [
            'Valid SOA record' => ['ns1.example.com. hostmaster.example.com. 2023060100 28800 7200 604800 86400', '2023060100'],
            'SOA record with missing parts' => ['ns1.example.com. hostmaster.example.com.', null],
            'Empty SOA record' => ['', null],
        ];
    }

    #[DataProvider('setSOASerialProvider')]
    public function testSetSOASerial(string $soaRec, string $serial, string $expected): void
    {
        $this->assertEquals($expected, SOARecordManager::setSOASerial($soaRec, $serial));
    }

    public static function setSOASerialProvider(): array
    {
        return [
            'Set new serial' => [
                'ns1.example.com. hostmaster.example.com. 2023060100 28800 7200 604800 86400',
                '2023060101',
                'ns1.example.com. hostmaster.example.com. 2023060101 28800 7200 604800 86400'
            ],
            'Set same serial' => [
                'ns1.example.com. hostmaster.example.com. 2023060100 28800 7200 604800 86400',
                '2023060100',
                'ns1.example.com. hostmaster.example.com. 2023060100 28800 7200 604800 86400'
            ]
        ];
    }

    #[DataProvider('nextDateProvider')]
    public function testGetNextDate(string $currentDate, string $expected): void
    {
        $this->assertEquals($expected, SOARecordManager::getNextDate($currentDate));
    }

    public static function nextDateProvider(): array
    {
        return [
            'Normal date' => ['20230601', '20230602'],
            'Month end' => ['20230630', '20230701'],
            'Year end' => ['20231231', '20240101'],
        ];
    }

    #[DataProvider('nextSerialProvider')]
    public function testGetNextSerial($currentSerial, $expected): void
    {
        $this->assertEquals($expected, $this->soaRecordManager->getNextSerial($currentSerial));
    }

    public static function nextSerialProvider(): array
    {
        return [
            'Autoserial' => [0, 0],
            'Not date based serial' => [123456, 123457],
            'Reset serial at limit' => [1979999999, 1],
        ];
    }

    public function testGetUpdatedSOARecord(): void
    {
        $soaRec = 'ns1.example.com. hostmaster.example.com. 2023060100 28800 7200 604800 86400';
        $expectedNewRec = 'ns1.example.com. hostmaster.example.com. 2023060101 28800 7200 604800 86400';

        // Mock getNextSerial to return a specific value
        $soaManagerMock = $this->getMockBuilder(SOARecordManager::class)
            ->setConstructorArgs([$this->dbMock, $this->configMock])
            ->onlyMethods(['getNextSerial'])
            ->getMock();

        $soaManagerMock->expects($this->once())
            ->method('getNextSerial')
            ->with('2023060100')
            ->willReturn('2023060101');

        $this->assertEquals($expectedNewRec, $soaManagerMock->getUpdatedSOARecord($soaRec));
    }

    public function testGetUpdatedSOARecordWithEmptyInput(): void
    {
        $this->assertEquals('', $this->soaRecordManager->getUpdatedSOARecord(''));
    }
}
