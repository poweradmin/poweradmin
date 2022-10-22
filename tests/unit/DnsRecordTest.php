<?php

namespace Poweradmin;

use PHPUnit\Framework\TestCase;

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
}
