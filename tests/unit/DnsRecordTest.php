<?php

namespace unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\AppConfiguration;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Infrastructure\Database\PDOLayer;

class DnsRecordTest extends TestCase
{
    const SOA_REC = "ns1.poweradmin.org hostmaster.poweradmin.org 2022082600 28800 7200 604800 86400";
    private DnsRecord $dnsRecord;

    protected function setUp(): void
    {
        $dbMock = $this->createMock(PDOLayer::class);
        $configMock = $this->createMock(AppConfiguration::class);

        $this->dnsRecord = new DnsRecord($dbMock, $configMock);
    }

    public function testGetUpdatedSoaRecordShouldReturnEmpty()
    {
        $result = $this->dnsRecord->get_updated_soa_record('');
        $this->assertSame('', $result);
    }

    public function testGetUpdatedSoaRecordShouldReturnIncrementedDate()
    {
        $result = $this->dnsRecord->get_updated_soa_record(self::SOA_REC);
        $expected = sprintf("ns1.poweradmin.org hostmaster.poweradmin.org %s00 28800 7200 604800 86400", date('Ymd'));
        $this->assertSame($expected, $result);
    }

    public function testGetSoaSerialShouldReturnEmpty()
    {
        $this->assertSame(null, DnsRecord::get_soa_serial(""));
    }

    public function testGetSoaSerialShouldReturnSerialDate()
    {
        $this->assertSame("2022082600", DnsRecord::get_soa_serial(self::SOA_REC));
    }

    public function testGetNextSerialShouldReturnZeroIfAutoSerial()
    {
        $result = $this->dnsRecord->get_next_serial(0);
        $this->assertSame(0, $result);
    }

    public function testGetNextSerialShouldReturnNextIfNotDateBased()
    {
        $result = $this->dnsRecord->get_next_serial(69);
        $this->assertSame(70, $result);
    }

    public function testGetNextSerialShouldReturnOneIfBindReleaseDate()
    {
        $result = $this->dnsRecord->get_next_serial(1979999999);
        $this->assertSame(1, $result);
    }

    public function testGetNextSerialShouldIncrementDateIfMaxRevisionAndToday()
    {
        $given = sprintf("%s99", date('Ymd'));
        $expected = sprintf("%s00", date('Ymd', strtotime("+1 day")));
        $result = $this->dnsRecord->get_next_serial($given);
        $this->assertSame($expected, $result);
    }

    public function testGetNextSerialShouldReturnIncrementedRevisionIfToday()
    {
        $given = sprintf("%s01", date('Ymd'));
        $expected = sprintf("%s02", date('Ymd'));
        $result = $this->dnsRecord->get_next_serial($given);
        $this->assertSame($expected, $result);
    }

    public function testGetNextSerialShouldReStartRevisionFromTodayIfInFuture()
    {
        $given = sprintf("%s01", date('Ymd', strtotime("+3 day")));
        $expected = sprintf("%s02", date('Ymd', strtotime("+3 day")));
        $result = $this->dnsRecord->get_next_serial($given);
        $this->assertSame($expected, $result);
    }

    public function testGetNextSerialShouldReStartRevisionFromTodayIfInFutureAndMaxPerDay()
    {
        $given = sprintf("%s99", date('Ymd', strtotime("+3 day")));
        $expected = sprintf("%s00", date('Ymd', strtotime("+4 day")));
        $result = $this->dnsRecord->get_next_serial($given);
        $this->assertSame($expected, $result);
    }

    public function testGetNextSerialShouldRestartRevisionFromTodayIfInThePast()
    {
        $given = sprintf("%s01", date('Ymd', strtotime("-4 day")));
        $expected = sprintf("%s00", date('Ymd'));
        $result = $this->dnsRecord->get_next_serial($given);
        $this->assertSame($expected, $result);
    }

    public function testGetNextSerialShouldIncrementSerialWhenInFuture()
    {
        $given = sprintf("%s01", date('Ymd', strtotime("+1 day")));
        $expected = sprintf("%s02", date('Ymd', strtotime("+1 day")));
        $result = $this->dnsRecord->get_next_serial($given);
        $this->assertSame($expected, $result);
    }

    public function testGetNextSerialShouldIncrementDayWhenInFuture()
    {
        $given = sprintf("%s99", date('Ymd', strtotime("+1 day")));
        $expected = sprintf("%s00", date('Ymd', strtotime("+2 day")));
        $result = $this->dnsRecord->get_next_serial($given);
        $this->assertSame($expected, $result);
    }

    public function testGetDomainLevel()
    {
        $this->assertSame(DnsRecord::get_domain_level('com'), 1);
        $this->assertSame(DnsRecord::get_domain_level('example.com'), 2);
        $this->assertSame(DnsRecord::get_domain_level('www.example.com'), 3);
    }

    public function testGetSecondLevelDomain()
    {
        $this->assertSame(DnsRecord::get_second_level_domain('www.example.com'), 'example.com');
        $this->assertSame(DnsRecord::get_second_level_domain('ftp.ru.example.com'), 'example.com');
    }

    public function testGetNextDate()
    {
        $this->assertSame(DnsRecord::get_next_date('20110526'), '20110527');
        $this->assertSame(DnsRecord::get_next_date('20101231'), '20110101');
        $this->assertSame(DnsRecord::get_next_date('20110228'), '20110301');
    }
}
