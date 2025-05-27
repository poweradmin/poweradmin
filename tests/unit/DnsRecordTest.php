<?php

namespace unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOCommon;

class DnsRecordTest extends TestCase
{
    private const SOA_REC = "ns1.poweradmin.org hostmaster.poweradmin.org 2022082600 28800 7200 604800 86400";
    private DnsRecord $dnsRecord;

    protected function setUp(): void
    {
        $dbMock = $this->createMock(PDOCommon::class);
        $configMock = $this->createMock(ConfigurationManager::class);

        // Configure the mock to return expected values
        $configMock->method('get')
            ->willReturnCallback(function ($group, $key) {
                if ($group === 'misc' && $key === 'timezone') {
                    return 'UTC';
                }
                if ($group === 'database' && $key === 'type') {
                    return 'mysql'; // Mock database type for tests
                }
                if ($group === 'database' && $key === 'pdns_db_name') {
                    return 'pdns'; // Mock PowerDNS database name
                }
                return null;
            });

        $this->dnsRecord = new DnsRecord($dbMock, $configMock);
    }

    public function testGetUpdatedSoaRecordShouldReturnEmpty()
    {
        $result = $this->dnsRecord->getUpdatedSOARecord('');
        $this->assertSame('', $result);
    }

    public function testGetUpdatedSoaRecordShouldReturnIncrementedDate()
    {
        $result = $this->dnsRecord->getUpdatedSOARecord(self::SOA_REC);
        $expected = sprintf("ns1.poweradmin.org hostmaster.poweradmin.org %s00 28800 7200 604800 86400", date('Ymd'));
        $this->assertSame($expected, $result);
    }

    public function testGetSoaSerialShouldReturnEmpty()
    {
        $this->assertSame(null, DnsRecord::getSOASerial(""));
    }

    public function testGetSoaSerialShouldReturnSerialDate()
    {
        $this->assertSame("2022082600", DnsRecord::getSOASerial(self::SOA_REC));
    }

    public function testGetNextSerialShouldReturnZeroIfAutoSerial()
    {
        $result = $this->dnsRecord->getNextSerial(0);
        $this->assertSame(0, $result);
    }

    public function testGetNextSerialShouldReturnNextIfNotDateBased()
    {
        $result = $this->dnsRecord->getNextSerial(69);
        $this->assertSame(70, $result);
    }

    public function testGetNextSerialShouldReturnOneIfBindReleaseDate()
    {
        $result = $this->dnsRecord->getNextSerial(1979999999);
        $this->assertSame(1, $result);
    }

    public function testGetNextSerialShouldIncrementDateIfMaxRevisionAndToday()
    {
        $given = sprintf("%s99", date('Ymd'));
        $expected = sprintf("%s00", date('Ymd', strtotime("+1 day")));
        $result = $this->dnsRecord->getNextSerial($given);
        $this->assertSame($expected, $result);
    }

    public function testGetNextSerialShouldReturnIncrementedRevisionIfToday()
    {
        $given = sprintf("%s01", date('Ymd'));
        $expected = sprintf("%s02", date('Ymd'));
        $result = $this->dnsRecord->getNextSerial($given);
        $this->assertSame($expected, $result);
    }

    public function testGetNextSerialShouldReStartRevisionFromTodayIfInFuture()
    {
        $given = sprintf("%s01", date('Ymd', strtotime("+3 day")));
        $expected = sprintf("%s02", date('Ymd', strtotime("+3 day")));
        $result = $this->dnsRecord->getNextSerial($given);
        $this->assertSame($expected, $result);
    }

    public function testGetNextSerialShouldReStartRevisionFromTodayIfInFutureAndMaxPerDay()
    {
        $given = sprintf("%s99", date('Ymd', strtotime("+3 day")));
        $expected = sprintf("%s00", date('Ymd', strtotime("+4 day")));
        $result = $this->dnsRecord->getNextSerial($given);
        $this->assertSame($expected, $result);
    }

    public function testGetNextSerialShouldRestartRevisionFromTodayIfInThePast()
    {
        $given = sprintf("%s01", date('Ymd', strtotime("-4 day")));
        $expected = sprintf("%s00", date('Ymd'));
        $result = $this->dnsRecord->getNextSerial($given);
        $this->assertSame($expected, $result);
    }

    public function testGetNextSerialShouldIncrementSerialWhenInFuture()
    {
        $given = sprintf("%s01", date('Ymd', strtotime("+1 day")));
        $expected = sprintf("%s02", date('Ymd', strtotime("+1 day")));
        $result = $this->dnsRecord->getNextSerial($given);
        $this->assertSame($expected, $result);
    }

    public function testGetNextSerialShouldIncrementDayWhenInFuture()
    {
        $given = sprintf("%s99", date('Ymd', strtotime("+1 day")));
        $expected = sprintf("%s00", date('Ymd', strtotime("+2 day")));
        $result = $this->dnsRecord->getNextSerial($given);
        $this->assertSame($expected, $result);
    }

    public function testGetDomainLevel()
    {
        $this->assertSame(DnsRecord::getDomainLevel('com'), 1);
        $this->assertSame(DnsRecord::getDomainLevel('example.com'), 2);
        $this->assertSame(DnsRecord::getDomainLevel('www.example.com'), 3);
    }

    public function testGetSecondLevelDomain()
    {
        $this->assertSame(DnsRecord::getSecondLevelDomain('www.example.com'), 'example.com');
        $this->assertSame(DnsRecord::getSecondLevelDomain('ftp.ru.example.com'), 'example.com');
    }

    public function testGetNextDate()
    {
        $this->assertSame(DnsRecord::getNextDate('20110526'), '20110527');
        $this->assertSame(DnsRecord::getNextDate('20101231'), '20110101');
        $this->assertSame(DnsRecord::getNextDate('20110228'), '20110301');
    }
}
