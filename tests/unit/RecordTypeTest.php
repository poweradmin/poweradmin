<?php

namespace unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\RecordType;

class RecordTypeTest extends TestCase
{
    public function testConstantsExist(): void
    {
        // Verify that the constants exist
        $this->assertTrue(defined(RecordType::class . '::DOMAIN_ZONE_COMMON_RECORDS'));
        $this->assertTrue(defined(RecordType::class . '::REVERSE_ZONE_COMMON_RECORDS'));
        $this->assertTrue(defined(RecordType::class . '::DNSSEC_TYPES'));
        $this->assertTrue(defined(RecordType::class . '::LESS_COMMON_RECORDS'));
    }

    public function testDomainZoneCommonRecordsContainExpectedTypes(): void
    {
        // Verify DOMAIN_ZONE_COMMON_RECORDS contains expected types
        $this->assertContains('A', RecordType::DOMAIN_ZONE_COMMON_RECORDS);
        $this->assertContains('AAAA', RecordType::DOMAIN_ZONE_COMMON_RECORDS);
        $this->assertContains('MX', RecordType::DOMAIN_ZONE_COMMON_RECORDS);
        $this->assertContains('NS', RecordType::DOMAIN_ZONE_COMMON_RECORDS);
        $this->assertContains('SOA', RecordType::DOMAIN_ZONE_COMMON_RECORDS);
        $this->assertContains('TXT', RecordType::DOMAIN_ZONE_COMMON_RECORDS);
    }

    public function testReverseZoneCommonRecordsContainExpectedTypes(): void
    {
        // Verify REVERSE_ZONE_COMMON_RECORDS contains expected types
        $this->assertContains('PTR', RecordType::REVERSE_ZONE_COMMON_RECORDS);
        $this->assertContains('CNAME', RecordType::REVERSE_ZONE_COMMON_RECORDS);
        $this->assertContains('NS', RecordType::REVERSE_ZONE_COMMON_RECORDS);
        $this->assertContains('SOA', RecordType::REVERSE_ZONE_COMMON_RECORDS);
    }

    public function testDnssecTypesContainExpectedTypes(): void
    {
        // Verify DNSSEC_TYPES contains expected types
        $this->assertContains('DNSKEY', RecordType::DNSSEC_TYPES);
        $this->assertContains('DS', RecordType::DNSSEC_TYPES);
        $this->assertContains('RRSIG', RecordType::DNSSEC_TYPES);
        $this->assertContains('NSEC', RecordType::DNSSEC_TYPES);
        $this->assertContains('NSEC3', RecordType::DNSSEC_TYPES);
    }

    public function testLessCommonRecordsContainExpectedTypes(): void
    {
        // Verify LESS_COMMON_RECORDS contains expected types
        $this->assertContains('CAA', RecordType::LESS_COMMON_RECORDS);
        $this->assertContains('TLSA', RecordType::LESS_COMMON_RECORDS);
        $this->assertContains('SSHFP', RecordType::LESS_COMMON_RECORDS);
        $this->assertContains('HTTPS', RecordType::LESS_COMMON_RECORDS);
    }
}
