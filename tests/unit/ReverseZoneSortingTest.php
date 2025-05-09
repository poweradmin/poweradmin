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

    public function testGetSortOrderWithNaturalSort(): void
    {
        $field = 'domains.name';
        $dbType = 'mysql';

        $result = $this->reverseZoneSorting->getSortOrder($field, $dbType, 'ASC', 'natural');

        // Since we're delegating to ReverseDomainNaturalSorting, we just ensure
        // the result is non-empty and contains expected SQL fragments
        $this->assertNotEmpty($result);
        $this->assertStringContainsString($field, $result);
        $this->assertStringContainsString('ASC', $result);
    }

    public function testGetSortOrderWithHierarchicalSort(): void
    {
        $field = 'domains.name';
        $dbType = 'mysql';

        $result = $this->reverseZoneSorting->getSortOrder($field, $dbType, 'ASC', 'hierarchical');

        // Since we're delegating to ReverseDomainHierarchySorting, we just ensure
        // the result is non-empty and contains expected SQL fragments
        $this->assertNotEmpty($result);
        $this->assertStringContainsString($field, $result);
        $this->assertStringContainsString('ASC', $result);

        // Should contain the hierarchical sort's CASE WHEN clause
        $this->assertStringContainsString('CASE WHEN', $result);
        $this->assertStringContainsString('LIKE \'%.in-addr.arpa\'', $result);
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
            '1.10.in-addr.arpa',
            '2.10.in-addr.arpa'
        ];

        $result = $this->reverseZoneSorting->sortDomains($domains, 'hierarchical');

        // Verify 10.in-addr.arpa network appears first in hierarchical sorting
        $this->assertEquals('10.in-addr.arpa', $result[0]);

        // Network 10 domains should be grouped together
        $firstTenNetIndex = array_search('10.in-addr.arpa', $result);
        $oneTenNetIndex = array_search('1.10.in-addr.arpa', $result);
        $twoTenNetIndex = array_search('2.10.in-addr.arpa', $result);

        // The 10.in-addr.arpa (most general) should come before 1.10.in-addr.arpa
        $this->assertLessThan($oneTenNetIndex, $firstTenNetIndex);

        // Network 10 domains should all come before network 172 domains
        $oneSevenTwoNetworkIndex = array_search('16.172.in-addr.arpa', $result);
        $this->assertLessThan($oneSevenTwoNetworkIndex, $firstTenNetIndex);
        $this->assertLessThan($oneSevenTwoNetworkIndex, $oneTenNetIndex);
        $this->assertLessThan($oneSevenTwoNetworkIndex, $twoTenNetIndex);

        // Make sure result has same count as input
        $this->assertCount(count($domains), $result);
    }

    public function testSortDomainsWithMixedIpVersions(): void
    {
        // Test with a mix of IPv4 and IPv6 reverse zones
        $domains = [
            '1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa',
            '10.in-addr.arpa',
            '1.10.in-addr.arpa'
        ];

        // Test natural sort
        $naturalResult = $this->reverseZoneSorting->sortDomains($domains, 'natural');

        // IPv4 should come before IPv6 in both sorting methods
        $this->assertStringContainsString('in-addr.arpa', $naturalResult[0]);
        $this->assertStringContainsString('ip6.arpa', $naturalResult[count($naturalResult) - 1]);

        // Test hierarchical sort
        $hierarchicalResult = $this->reverseZoneSorting->sortDomains($domains, 'hierarchical');

        // IPv4 should come before IPv6
        $this->assertStringContainsString('in-addr.arpa', $hierarchicalResult[0]);
        $this->assertStringContainsString('ip6.arpa', $hierarchicalResult[count($hierarchicalResult) - 1]);

        // In hierarchical sorting, the most general domain should come first
        $this->assertEquals('10.in-addr.arpa', $hierarchicalResult[0]);

        // Make sure all domains are present
        $this->assertCount(count($domains), $naturalResult);
        $this->assertCount(count($domains), $hierarchicalResult);
    }
}
