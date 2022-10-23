<?php

use PHPUnit\Framework\TestCase;
use Poweradmin\DnsRecord;

class DnsRecordTest extends TestCase
{
    const SOA_REC = "ns1.poweradmin.org hostmaster.poweradmin.org 2022082600 28800 7200 604800 86400";

    public function testGetUpdatedSoaRecordShouldReturnEmpty()
    {
        $this->assertSame("", DnsRecord::get_updated_soa_record(""));
    }

    public function testGetUpdatedSoaRecordShouldReturnIncrementedDate()
    {
        $expected = sprintf("ns1.poweradmin.org hostmaster.poweradmin.org %s00 28800 7200 604800 86400", date('Ymd'));
        $this->assertSame($expected, DnsRecord::get_updated_soa_record(self::SOA_REC));
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
        $this->assertSame(0, DnsRecord::get_next_serial(0));
    }

    public function testGetNextSerialShouldReturnNextIfNotDateBased()
    {
        $this->assertSame(70, DnsRecord::get_next_serial(69));
    }

    public function testGetNextSerialShouldReturnOneIfBindReleaseDate()
    {
        $this->assertSame(1, DnsRecord::get_next_serial(1979999999));
    }

    public function testGetNextSerialShouldIncrementDateIfMaxRevisionAndToday()
    {
        $given = sprintf( "%s99", date('Ymd'));
        $expected = sprintf( "%s00", date('Ymd', strtotime("+1 day")));
        $this->assertSame($expected, DnsRecord::get_next_serial($given));
    }

    public function testGetNextSerialShouldReturnIncrementedRevisionIfToday()
    {
        $given = sprintf( "%s01", date('Ymd'));
        $expected = sprintf( "%s02", date('Ymd'));
        $this->assertSame($expected, DnsRecord::get_next_serial($given));
    }

    public function testGetNextSerialShouldReStartRevisionFromTodayIfInFuture()
    {
        $given = sprintf( "%s01", date('Ymd', strtotime("+3 day")));
        $expected = sprintf( "%s02", date('Ymd', strtotime("+3 day")));
        $this->assertSame($expected, DnsRecord::get_next_serial($given));
    }

    public function testGetNextSerialShouldReStartRevisionFromTodayIfInFutureAndMaxPerDay()
    {
        $given = sprintf( "%s99", date('Ymd', strtotime("+3 day")));
        $expected = sprintf( "%s00", date('Ymd', strtotime("+4 day")));
        $this->assertSame($expected, DnsRecord::get_next_serial($given));
    }

    public function testGetNextSerialShouldRestartRevisionFromTodayIfInThePast()
    {
        $given = sprintf( "%s01", date('Ymd', strtotime("-4 day")));
        $expected = sprintf( "%s00", date('Ymd'));
        $this->assertSame($expected, DnsRecord::get_next_serial($given));
    }

    public function testGetNextSerialShouldIncrementSerialWhenInFuture()
    {
        $given = sprintf( "%s01", date('Ymd', strtotime("+1 day")));
        $expected = sprintf( "%s02", date('Ymd', strtotime("+1 day")));
        $this->assertSame($expected, DnsRecord::get_next_serial($given));
    }

    public function testGetNextSerialShouldIncrementDayWhenInFuture()
    {
        $given = sprintf( "%s99", date('Ymd', strtotime("+1 day")));
        $expected = sprintf( "%s00", date('Ymd', strtotime("+2 day")));
        $this->assertSame($expected, DnsRecord::get_next_serial($given));
    }
}
