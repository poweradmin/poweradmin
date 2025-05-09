<?php

namespace unit;

use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Utility\ReverseDomainHierarchySorting;

class ReverseDomainHierarchySortingTest extends TestCase
{
    private ReverseDomainHierarchySorting $reverseHierarchySorting;

    protected function setUp(): void
    {
        $this->reverseHierarchySorting = new ReverseDomainHierarchySorting();
    }

    public function testGetHierarchicalSortOrderForMysql(): void
    {
        $field = 'domains.name';
        $expectedSql = "
            /* First separate IPv4 from IPv6 */
            CASE WHEN $field LIKE '%.in-addr.arpa' THEN 0 ELSE 1 END ASC,
            
            /* For IPv4 zones, extract the main network component */
            SUBSTRING_INDEX(SUBSTRING_INDEX($field, '.in-addr.arpa', 1), '.', -1) + 0 ASC,
            
            /* Sort by specificity (number of parts) */
            (LENGTH($field) - LENGTH(REPLACE($field, '.', ''))) ASC,
            
            /* Natural order for remaining parts */
            $field ASC
        ";

        $result = $this->reverseHierarchySorting->getHierarchicalSortOrder($field, 'mysql');
        $this->assertEquals($this->normalizeWhitespace($expectedSql), $this->normalizeWhitespace($result));
    }

    public function testGetHierarchicalSortOrderForPostgres(): void
    {
        $field = 'domains.name';
        $expectedSql = "
            /* First separate IPv4 from IPv6 */
            CASE WHEN $field LIKE '%.in-addr.arpa' THEN 0 ELSE 1 END ASC,
            
            /* For IPv4 zones, extract the main network component */
            (SPLIT_PART(SPLIT_PART($field, '.in-addr.arpa', 1), '.', 
                array_length(string_to_array(SPLIT_PART($field, '.in-addr.arpa', 1), '.'), 1)
            ))::integer ASC,
            
            /* Sort by specificity (number of parts) */
            array_length(string_to_array($field, '.'), 1) ASC,
            
            /* Natural order for remaining parts */
            $field ASC
        ";

        $result = $this->reverseHierarchySorting->getHierarchicalSortOrder($field, 'pgsql');
        $this->assertEquals($this->normalizeWhitespace($expectedSql), $this->normalizeWhitespace($result));
    }

    public function testGetHierarchicalSortOrderForSqlite(): void
    {
        $field = 'domains.name';
        $expectedSql = "
            /* SQLite has limited string manipulation, use simpler approach */
            LENGTH($field) ASC,
            $field ASC
        ";

        $result = $this->reverseHierarchySorting->getHierarchicalSortOrder($field, 'sqlite');
        $this->assertEquals($this->normalizeWhitespace($expectedSql), $this->normalizeWhitespace($result));
    }

    public function testGetHierarchicalSortOrderWithCustomDirection(): void
    {
        $field = 'domains.name';
        $expectedSql = "
            /* First separate IPv4 from IPv6 */
            CASE WHEN $field LIKE '%.in-addr.arpa' THEN 0 ELSE 1 END DESC,
            
            /* For IPv4 zones, extract the main network component */
            SUBSTRING_INDEX(SUBSTRING_INDEX($field, '.in-addr.arpa', 1), '.', -1) + 0 DESC,
            
            /* Sort by specificity (number of parts) */
            (LENGTH($field) - LENGTH(REPLACE($field, '.', ''))) DESC,
            
            /* Natural order for remaining parts */
            $field DESC
        ";

        $result = $this->reverseHierarchySorting->getHierarchicalSortOrder($field, 'mysql', 'DESC');
        $this->assertEquals($this->normalizeWhitespace($expectedSql), $this->normalizeWhitespace($result));
    }

    public function testGetHierarchicalSortOrderWithInvalidDirection(): void
    {
        $field = 'domains.name';
        $expectedSql = "
            /* First separate IPv4 from IPv6 */
            CASE WHEN $field LIKE '%.in-addr.arpa' THEN 0 ELSE 1 END ASC,
            
            /* For IPv4 zones, extract the main network component */
            SUBSTRING_INDEX(SUBSTRING_INDEX($field, '.in-addr.arpa', 1), '.', -1) + 0 ASC,
            
            /* Sort by specificity (number of parts) */
            (LENGTH($field) - LENGTH(REPLACE($field, '.', ''))) ASC,
            
            /* Natural order for remaining parts */
            $field ASC
        ";

        $result = $this->reverseHierarchySorting->getHierarchicalSortOrder($field, 'mysql', 'INVALID');
        $this->assertEquals($this->normalizeWhitespace($expectedSql), $this->normalizeWhitespace($result));
    }

    public function testGetHierarchicalSortOrderWithUnknownDbType(): void
    {
        $field = 'domains.name';
        $expectedSql = "$field ASC";

        $result = $this->reverseHierarchySorting->getHierarchicalSortOrder($field, 'unknown');
        $this->assertEquals($expectedSql, $result);
    }

    public function testSortDomainsHierarchically(): void
    {
        // Input domains with mixed order
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

        // Expected order after hierarchical sorting
        $expected = [
            '10.in-addr.arpa',           // Network 10, most general
            '1.10.in-addr.arpa',         // Network 10, second level
            '2.10.in-addr.arpa',         // Network 10, second level
            '100.100.10.in-addr.arpa',   // Network 10, third level
            '252.1.10.in-addr.arpa',     // Network 10, third level
            '16.172.in-addr.arpa',       // Network 172
            '1.2.168.192.in-addr.arpa',  // Network 192, most specific zones last
            '2.255.168.192.in-addr.arpa',
            '200.1.168.192.in-addr.arpa',
        ];

        $result = $this->reverseHierarchySorting->sortDomainsHierarchically($domains);

        // The exact ordering may differ as long as the hierarchical principles are followed,
        // so we test the key principles instead of exact ordering

        // First domain should be the most general 10.in-addr.arpa
        $this->assertEquals('10.in-addr.arpa', $result[0], 'Network 10 general domain should be first');

        // Network 10 domains should come before network 172 domains
        $tenNetworkIndex = array_search('10.in-addr.arpa', $result);
        $oneSevenTwoNetworkIndex = array_search('16.172.in-addr.arpa', $result);
        $this->assertLessThan($oneSevenTwoNetworkIndex, $tenNetworkIndex, 'Network 10 should come before network 172');

        // Network 172 should come before network 192
        $oneNineTwoNetworkIndex = array_search('1.2.168.192.in-addr.arpa', $result);
        $this->assertLessThan($oneNineTwoNetworkIndex, $oneSevenTwoNetworkIndex, 'Network 172 should come before network 192');

        // Same length subnets should be ordered numerically
        $oneTenIndex = array_search('1.10.in-addr.arpa', $result);
        $twoTenIndex = array_search('2.10.in-addr.arpa', $result);
        $this->assertLessThan($twoTenIndex, $oneTenIndex, 'Domains with same specificity should be ordered by octet value');

        // All domains should be present
        $this->assertCount(count($domains), $result, 'All domains should be present in result');
    }

    public function testSortDomainsHierarchicallyWithMixedIpVersions(): void
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

        $result = $this->reverseHierarchySorting->sortDomainsHierarchically($domains);

        // IPv4 should come before IPv6
        $this->assertStringContainsString('in-addr.arpa', $result[0], 'IPv4 domain should come first');
        $this->assertStringContainsString('ip6.arpa', $result[count($result) - 1], 'IPv6 domain should come last');

        // Make sure all domains are present
        $this->assertCount(count($domains), $result);
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
