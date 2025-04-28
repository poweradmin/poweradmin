<?php

namespace unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\RecordType;
use Poweradmin\Domain\Service\RecordTypeService;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;

class RecordTypeServiceTest extends TestCase
{
    /**
     * @var \PHPUnit\Framework\MockObject\MockObject|ConfigurationInterface
     */
    private $configManagerMock;

    /**
     * @var RecordTypeService
     */
    private $service;

    protected function setUp(): void
    {
        $this->configManagerMock = $this->createMock(ConfigurationInterface::class);
        $this->service = new RecordTypeService($this->configManagerMock);
    }

    public function testGetAllTypesWithNoConfiguration(): void
    {
        // Configure mock to return null for configuration values
        $this->configManagerMock->method('get')
            ->willReturn(null);

        $result = $this->service->getAllTypes();

        // Verify we get a non-empty array
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);

        // Verify the array contains expected basic record types
        $this->assertContains('A', $result);
        $this->assertContains('AAAA', $result);
        $this->assertContains('MX', $result);
        $this->assertContains('NS', $result);
        $this->assertContains('SOA', $result);
        $this->assertContains('TXT', $result);
        $this->assertContains('PTR', $result);

        // Verify DNSSEC types are included
        $this->assertContains('DNSKEY', $result);
        $this->assertContains('DS', $result);
        $this->assertContains('RRSIG', $result);

        // Verify less common types are included
        $this->assertContains('CAA', $result);
        $this->assertContains('TLSA', $result);
        $this->assertContains('SSHFP', $result);

        // Verify the array is sorted
        $sortedResult = $result;
        sort($sortedResult);
        $this->assertSame($sortedResult, $result);
    }

    public function testGetAllTypesWithOnlyDomainTypesConfigured(): void
    {
        // Configure mock to return configured domain types only
        $this->configManagerMock->method('get')
            ->willReturnCallback(function ($section, $key) {
                if ($section === 'dns' && $key === 'domain_record_types') {
                    return ['A', 'AAAA', 'CNAME', 'TXT', 'MX'];
                }
                return null;
            });

        $result = $this->service->getAllTypes();

        // Verify we get a non-empty array with domain + default reverse types
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);

        // Verify configured domain types
        $this->assertContains('A', $result);
        $this->assertContains('AAAA', $result);
        $this->assertContains('CNAME', $result);
        $this->assertContains('TXT', $result);
        $this->assertContains('MX', $result);

        // Verify default reverse types
        $this->assertContains('PTR', $result);
        $this->assertContains('LOC', $result);

        // Verify the array is sorted
        $sortedResult = $result;
        sort($sortedResult);
        $this->assertSame($sortedResult, $result);
    }

    public function testGetAllTypesWithOnlyReverseTypesConfigured(): void
    {
        // Configure mock to return configured reverse types only
        $this->configManagerMock->method('get')
            ->willReturnCallback(function ($section, $key) {
                if ($section === 'dns' && $key === 'reverse_record_types') {
                    return ['PTR', 'CNAME', 'TXT'];
                }
                return null;
            });

        $result = $this->service->getAllTypes();

        // Verify we get a non-empty array with default domain + reverse types
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);

        // Verify default domain types
        $this->assertContains('A', $result);
        $this->assertContains('AAAA', $result);
        $this->assertContains('MX', $result);
        $this->assertContains('NS', $result);
        $this->assertContains('SOA', $result);

        // Verify configured reverse types
        $this->assertContains('PTR', $result);
        $this->assertContains('CNAME', $result);
        $this->assertContains('TXT', $result);

        // Verify array is sorted
        $sortedResult = $result;
        sort($sortedResult);
        $this->assertSame($sortedResult, $result);
    }

    public function testGetAllTypesWithBothTypesConfigured(): void
    {
        // Configure mock to return both domain and reverse types
        $this->configManagerMock->method('get')
            ->willReturnCallback(function ($section, $key) {
                if ($section === 'dns' && $key === 'domain_record_types') {
                    return ['A', 'AAAA', 'CNAME', 'TXT', 'MX', 'CAA'];
                }
                if ($section === 'dns' && $key === 'reverse_record_types') {
                    return ['PTR', 'CNAME', 'TXT', 'NS'];
                }
                return null;
            });

        $result = $this->service->getAllTypes();

        // Verify we get a non-empty array with both configured types
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);

        // Verify configured domain types
        $this->assertContains('A', $result);
        $this->assertContains('AAAA', $result);
        $this->assertContains('CNAME', $result);
        $this->assertContains('TXT', $result);
        $this->assertContains('MX', $result);
        $this->assertContains('CAA', $result);

        // Verify configured reverse types
        $this->assertContains('PTR', $result);
        $this->assertContains('NS', $result);

        // Verify duplicates are removed (CNAME and TXT appear in both configs)
        $this->assertSame(count(array_unique($result)), count($result));

        // Verify the array is sorted
        $sortedResult = $result;
        sort($sortedResult);
        $this->assertSame($sortedResult, $result);
    }

    public function testGetDomainZoneTypesWithoutConfiguration(): void
    {
        // Configure mock to return null for domain types
        $this->configManagerMock->method('get')
            ->willReturn(null);

        // Test with DNSSEC disabled
        $result = $this->service->getDomainZoneTypes(false);
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        $this->assertContains('A', $result);
        $this->assertContains('AAAA', $result);
        $this->assertContains('MX', $result);
        $this->assertNotContains('DNSKEY', $result); // DNSSEC type should not be included

        // Test with DNSSEC enabled
        $result = $this->service->getDomainZoneTypes(true);
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        $this->assertContains('A', $result);
        $this->assertContains('AAAA', $result);
        $this->assertContains('MX', $result);
        $this->assertContains('DNSKEY', $result); // DNSSEC type should be included
        $this->assertContains('DS', $result); // DNSSEC type should be included
    }

    public function testGetDomainZoneTypesWithConfiguration(): void
    {
        // Configure mock to return configured domain types
        $this->configManagerMock->method('get')
            ->willReturnCallback(function ($section, $key) {
                if ($section === 'dns' && $key === 'domain_record_types') {
                    return ['A', 'AAAA', 'CNAME', 'TXT', 'MX', 'CAA'];
                }
                return null;
            });

        // Test with DNSSEC disabled
        $result = $this->service->getDomainZoneTypes(false);
        $this->assertIsArray($result);
        $this->assertCount(6, $result); // Only the configured types
        $this->assertContains('A', $result);
        $this->assertContains('AAAA', $result);
        $this->assertContains('CNAME', $result);
        $this->assertContains('TXT', $result);
        $this->assertContains('MX', $result);
        $this->assertContains('CAA', $result);
        $this->assertNotContains('DNSKEY', $result); // DNSSEC type should not be included

        // Test with DNSSEC enabled
        $result = $this->service->getDomainZoneTypes(true);
        $this->assertIsArray($result);
        $this->assertContains('A', $result);
        $this->assertContains('AAAA', $result);
        $this->assertContains('CNAME', $result);
        $this->assertContains('TXT', $result);
        $this->assertContains('MX', $result);
        $this->assertContains('CAA', $result);
        $this->assertContains('DNSKEY', $result); // DNSSEC type should be included
        $this->assertContains('DS', $result); // DNSSEC type should be included
    }

    public function testGetReverseZoneTypesWithoutConfiguration(): void
    {
        // Configure mock to return null for reverse types
        $this->configManagerMock->method('get')
            ->willReturn(null);

        // Test with DNSSEC disabled
        $result = $this->service->getReverseZoneTypes(false);
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        $this->assertContains('PTR', $result);
        $this->assertContains('CNAME', $result);
        $this->assertContains('NS', $result);
        $this->assertNotContains('DNSKEY', $result); // DNSSEC type should not be included

        // Test with DNSSEC enabled
        $result = $this->service->getReverseZoneTypes(true);
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        $this->assertContains('PTR', $result);
        $this->assertContains('CNAME', $result);
        $this->assertContains('NS', $result);
        $this->assertContains('DNSKEY', $result); // DNSSEC type should be included
        $this->assertContains('DS', $result); // DNSSEC type should be included
    }

    public function testGetReverseZoneTypesWithConfiguration(): void
    {
        // Configure mock to return configured reverse types
        $this->configManagerMock->method('get')
            ->willReturnCallback(function ($section, $key) {
                if ($section === 'dns' && $key === 'reverse_record_types') {
                    return ['PTR', 'CNAME', 'TXT', 'NS'];
                }
                return null;
            });

        // Test with DNSSEC disabled
        $result = $this->service->getReverseZoneTypes(false);
        $this->assertIsArray($result);
        $this->assertCount(4, $result); // Only the configured types
        $this->assertContains('PTR', $result);
        $this->assertContains('CNAME', $result);
        $this->assertContains('TXT', $result);
        $this->assertContains('NS', $result);
        $this->assertNotContains('DNSKEY', $result); // DNSSEC type should not be included

        // Test with DNSSEC enabled
        $result = $this->service->getReverseZoneTypes(true);
        $this->assertIsArray($result);
        $this->assertContains('PTR', $result);
        $this->assertContains('CNAME', $result);
        $this->assertContains('TXT', $result);
        $this->assertContains('NS', $result);
        $this->assertContains('DNSKEY', $result); // DNSSEC type should be included
        $this->assertContains('DS', $result); // DNSSEC type should be included
    }
}
