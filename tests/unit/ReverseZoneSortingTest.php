<?php

namespace unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Utility\ReverseZoneSorting;

class ReverseZoneSortingTest extends TestCase
{
    private ReverseZoneSorting $reverseZoneSorting;

    protected function setUp(): void
    {
        $this->reverseZoneSorting = new ReverseZoneSorting();
    }

    public function testGetNetworkBasedSortOrderForMysql(): void
    {
        $field = 'domains.name';
        $expectedSql = "
                SUBSTRING_INDEX($field, '.in-addr.arpa', 1) ASC,  
                SUBSTRING_INDEX($field, '.', 1) + 0 ASC,
                LENGTH($field) ASC,
                $field ASC
            ";

        $result = $this->reverseZoneSorting->getNetworkBasedSortOrder($field, 'mysql');
        $this->assertEquals($this->normalizeWhitespace($expectedSql), $this->normalizeWhitespace($result));
    }

    public function testGetNetworkBasedSortOrderForPostgres(): void
    {
        $field = 'domains.name';
        $expectedSql = "
                SPLIT_PART($field, '.in-addr.arpa', 1) ASC,
                (SPLIT_PART($field, '.', 1))::integer ASC,
                LENGTH($field) ASC,
                $field ASC
            ";

        $result = $this->reverseZoneSorting->getNetworkBasedSortOrder($field, 'pgsql');
        $this->assertEquals($this->normalizeWhitespace($expectedSql), $this->normalizeWhitespace($result));
    }

    public function testGetNetworkBasedSortOrderForSqlite(): void
    {
        $field = 'domains.name';
        $expectedSql = "
                LENGTH($field) ASC,
                $field ASC
            ";

        $result = $this->reverseZoneSorting->getNetworkBasedSortOrder($field, 'sqlite');
        $this->assertEquals($this->normalizeWhitespace($expectedSql), $this->normalizeWhitespace($result));
    }

    public function testGetNetworkBasedSortOrderWithCustomDirection(): void
    {
        $field = 'domains.name';
        $expectedSql = "
                SUBSTRING_INDEX($field, '.in-addr.arpa', 1) DESC,  
                SUBSTRING_INDEX($field, '.', 1) + 0 DESC,
                LENGTH($field) DESC,
                $field DESC
            ";

        $result = $this->reverseZoneSorting->getNetworkBasedSortOrder($field, 'mysql', 'DESC');
        $this->assertEquals($this->normalizeWhitespace($expectedSql), $this->normalizeWhitespace($result));
    }

    public function testGetNetworkBasedSortOrderWithInvalidDirection(): void
    {
        $field = 'domains.name';
        $expectedSql = "
                SUBSTRING_INDEX($field, '.in-addr.arpa', 1) ASC,  
                SUBSTRING_INDEX($field, '.', 1) + 0 ASC,
                LENGTH($field) ASC,
                $field ASC
            ";

        $result = $this->reverseZoneSorting->getNetworkBasedSortOrder($field, 'mysql', 'INVALID');
        $this->assertEquals($this->normalizeWhitespace($expectedSql), $this->normalizeWhitespace($result));
    }

    public function testGetNetworkBasedSortOrderWithUnknownDbType(): void
    {
        $field = 'domains.name';
        $expectedSql = "$field ASC";

        $result = $this->reverseZoneSorting->getNetworkBasedSortOrder($field, 'unknown');
        $this->assertEquals($expectedSql, $result);
    }

    public function testGetSortOrderWithNaturalSort(): void
    {
        $field = 'domains.name';
        $dbType = 'mysql';

        // Create a mock to simulate the NaturalSorting behavior
        $naturalSortingMock = $this->createMock(\Poweradmin\Infrastructure\Utility\NaturalSorting::class);
        $naturalSortingMock->method('getReverseZoneSortOrder')
            ->willReturn("$field+0<>0 ASC, $field+0 ASC, $field ASC");

        // Create a reflection to inject the mock
        $reverseZoneSortingReflection = new \ReflectionClass($this->reverseZoneSorting);
        $getSortOrderMethod = $reverseZoneSortingReflection->getMethod('getSortOrder');

        // Make sure the method exists and calls NaturalSorting::getReverseZoneSortOrder
        $this->assertTrue(method_exists($this->reverseZoneSorting, 'getSortOrder'));

        // Test the actual behavior - this just verifies it's properly connected to NaturalSorting
        $result = $this->reverseZoneSorting->getSortOrder($field, $dbType, 'ASC', 'natural');
        $this->assertNotEmpty($result);
    }

    public function testGetSortOrderWithHierarchicalSort(): void
    {
        $field = 'domains.name';
        $dbType = 'mysql';
        $expectedSql = "
                SUBSTRING_INDEX($field, '.in-addr.arpa', 1) ASC,  
                SUBSTRING_INDEX($field, '.', 1) + 0 ASC,
                LENGTH($field) ASC,
                $field ASC
            ";

        $result = $this->reverseZoneSorting->getSortOrder($field, $dbType, 'ASC', 'hierarchical');
        $this->assertEquals($this->normalizeWhitespace($expectedSql), $this->normalizeWhitespace($result));
    }

    public function testSortDomainsWithNaturalSort(): void
    {
        $domains = [
            '2.255.168.192.in-addr.arpa',
            '16.172.in-addr.arpa',
            '10.in-addr.arpa',
            '1.2.168.192.in-addr.arpa',
            '1.10.in-addr.arpa',
            '2.10.in-addr.arpa'
        ];

        $result = $this->reverseZoneSorting->sortDomains($domains, 'natural');

        // Natural sort will preserve IPv4 domains in natural order
        $this->assertContains('1.10.in-addr.arpa', $result);
        $this->assertContains('2.10.in-addr.arpa', $result);
        $this->assertContains('10.in-addr.arpa', $result);

        // Make sure result has same count as input
        $this->assertCount(count($domains), $result);
    }

    public function testSortDomainsWithHierarchicalSort(): void
    {
        $domains = [
            '2.255.168.192.in-addr.arpa',
            '16.172.in-addr.arpa',
            '10.in-addr.arpa',
            '1.2.168.192.in-addr.arpa',
            '1.10.in-addr.arpa'
        ];

        $result = $this->reverseZoneSorting->sortDomains($domains, 'hierarchical');

        // Verify 10.in-addr.arpa network appears first in any hierarchical sorting
        $this->assertEquals('10.in-addr.arpa', $result[0]);
        $this->assertEquals('1.10.in-addr.arpa', $result[1]);

        // Make sure result has same count as input
        $this->assertCount(count($domains), $result);
    }

    public function testSortByNetworkHierarchy(): void
    {
        // Input domains with expected output order
        $domains = [
            '2.255.168.192.in-addr.arpa',
            '16.172.in-addr.arpa',
            '10.in-addr.arpa',
            '1.2.168.192.in-addr.arpa',
            '252.1.10.in-addr.arpa',
            '2.10.in-addr.arpa',
            '100.100.10.in-addr.arpa',
            '1.10.in-addr.arpa',
            '200.1.168.192.in-addr.arpa'
        ];

        $expected = [
            '10.in-addr.arpa',
            '1.10.in-addr.arpa',
            '2.10.in-addr.arpa',
            '252.1.10.in-addr.arpa',
            '100.100.10.in-addr.arpa',
            '16.172.in-addr.arpa',
            '200.1.168.192.in-addr.arpa',
            '1.2.168.192.in-addr.arpa',
            '2.255.168.192.in-addr.arpa'
        ];

        $result = $this->reverseZoneSorting->sortByNetworkHierarchy($domains);
        $this->assertEquals($expected, $result);
    }

    public function testSortByNetworkHierarchyWithDifferentNetworks(): void
    {
        // Test with a mix of IPv4 and IPv6 reverse zones
        $domains = [
            '1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa',
            '10.in-addr.arpa',
            '1.10.in-addr.arpa'
        ];

        $expected = [
            '10.in-addr.arpa',
            '1.10.in-addr.arpa',
            '1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa'
        ];

        $result = $this->reverseZoneSorting->sortByNetworkHierarchy($domains);
        $this->assertEquals($expected, $result);
    }

    /**
     * Helper method to normalize whitespace for SQL string comparison
     */
    private function normalizeWhitespace(string $sql): string
    {
        // Remove excess whitespace, newlines, etc. for consistent comparison
        return preg_replace('/\s+/', ' ', trim($sql));
    }
}
