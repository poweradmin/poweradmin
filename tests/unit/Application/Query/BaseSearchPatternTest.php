<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace Poweradmin\Tests\Unit\Application\Query;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Query\BaseSearch;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * Concrete test class to expose protected methods of BaseSearch
 */
class TestableBaseSearch extends BaseSearch
{
    public function exposeBuildSearchString(array $parameters): array
    {
        return $this->buildSearchString($parameters);
    }
}

/**
 * Test Search Pattern Handling and Wildcard Functionality
 *
 * @package Poweradmin\Tests\Unit\Application\Query
 * @covers \Poweradmin\Application\Query\BaseSearch::buildSearchString
 */
class BaseSearchPatternTest extends TestCase
{
    private $mockDb;
    private $mockConfig;
    private TestableBaseSearch $baseSearch;

    protected function setUp(): void
    {
        $this->mockDb = $this->createMock(\PDO::class);
        $this->mockConfig = $this->createMock(ConfigurationManager::class);

        $this->baseSearch = new TestableBaseSearch($this->mockDb, $this->mockConfig, 'mysql');
    }

    public function testWildcardSearchAddsPercentSigns(): void
    {
        $parameters = [
            'query' => 'example',
            'wildcard' => true,
            'reverse' => false
        ];

        [, , $searchString] = $this->baseSearch->exposeBuildSearchString($parameters);

        $this->assertEquals('%example%', $searchString);
    }

    public function testNonWildcardSearchNoPercentSigns(): void
    {
        $parameters = [
            'query' => 'example.com',
            'wildcard' => false,
            'reverse' => false
        ];

        [, , $searchString] = $this->baseSearch->exposeBuildSearchString($parameters);

        $this->assertEquals('example.com', $searchString);
    }

    public function testUnderscoreWildcardPattern(): void
    {
        $parameters = [
            'query' => 'po_eradmin.org',
            'wildcard' => true,
            'reverse' => false
        ];

        [, , $searchString] = $this->baseSearch->exposeBuildSearchString($parameters);

        // Underscore should be preserved as SQL wildcard (matches single char)
        $this->assertEquals('%po_eradmin.org%', $searchString);
        $this->assertStringContainsString('_', $searchString);
    }

    public function testPercentWildcardPattern(): void
    {
        $parameters = [
            'query' => 'po%',
            'wildcard' => true,
            'reverse' => false
        ];

        [, , $searchString] = $this->baseSearch->exposeBuildSearchString($parameters);

        // Percent should be preserved and additional wildcards added
        $this->assertEquals('%po%%', $searchString);
    }

    public function testCommentsSearchEnablesWildcard(): void
    {
        $parameters = [
            'query' => 'test',
            'wildcard' => false,
            'reverse' => false,
            'comments' => true
        ];

        [, $updatedParams, $searchString] = $this->baseSearch->exposeBuildSearchString($parameters);

        // Comments search should enable wildcard
        $this->assertTrue($updatedParams['wildcard']);
        $this->assertEquals('%test%', $searchString);
    }

    public function testIPv4ReverseSearch(): void
    {
        $parameters = [
            'query' => '192.168.1.10',
            'wildcard' => false,
            'reverse' => true
        ];

        [$reverseString, , ] = $this->baseSearch->exposeBuildSearchString($parameters);

        // IPv4 should be reversed: 192.168.1.10 -> 10.1.168.192
        $this->assertStringContainsString('10.1.168.192', $reverseString);
        $this->assertStringStartsWith('%', $reverseString);
        $this->assertStringEndsWith('%', $reverseString);
    }

    public function testIPv6ReverseSearch(): void
    {
        $parameters = [
            'query' => '2001:db8::1',
            'wildcard' => false,
            'reverse' => true
        ];

        [$reverseString, $updatedParams, ] = $this->baseSearch->exposeBuildSearchString($parameters);

        // IPv6 should be expanded and reversed
        $this->assertNotEmpty($reverseString);
        $this->assertStringStartsWith('%', $reverseString);
        $this->assertStringEndsWith('%', $reverseString);
        $this->assertTrue($updatedParams['reverse']);
    }

    public function testInvalidIPReverseSearchDisablesReverse(): void
    {
        $parameters = [
            'query' => 'not-an-ip-address',
            'wildcard' => false,
            'reverse' => true
        ];

        [, $updatedParams, ] = $this->baseSearch->exposeBuildSearchString($parameters);

        // Invalid IP should disable reverse search
        $this->assertFalse($updatedParams['reverse']);
    }

    public function testTrimWhitespaceInQuery(): void
    {
        $parameters = [
            'query' => '  example.com  ',
            'wildcard' => false,
            'reverse' => false
        ];

        [, , $searchString] = $this->baseSearch->exposeBuildSearchString($parameters);

        // Whitespace should be trimmed
        $this->assertEquals('example.com', $searchString);
    }

    public function testEmptyQueryWithWildcard(): void
    {
        $parameters = [
            'query' => '',
            'wildcard' => true,
            'reverse' => false
        ];

        [, , $searchString] = $this->baseSearch->exposeBuildSearchString($parameters);

        // Empty query with wildcard should produce %%
        $this->assertEquals('%%', $searchString);
    }

    public function testPartialDomainSearch(): void
    {
        $parameters = [
            'query' => 'admin',
            'wildcard' => true,
            'reverse' => false
        ];

        [, , $searchString] = $this->baseSearch->exposeBuildSearchString($parameters);

        // Should find "poweradmin.org", "admin.example.com", etc.
        $this->assertEquals('%admin%', $searchString);
    }

    public function testWildcardWithSpecialCharacters(): void
    {
        $parameters = [
            'query' => 'test-domain_special.com',
            'wildcard' => true,
            'reverse' => false
        ];

        [, , $searchString] = $this->baseSearch->exposeBuildSearchString($parameters);

        // Special characters should be preserved
        $this->assertEquals('%test-domain_special.com%', $searchString);
        $this->assertStringContainsString('-', $searchString);
        $this->assertStringContainsString('_', $searchString);
    }

    public function testMultipleUnderscoresInPattern(): void
    {
        $parameters = [
            'query' => 'a_b_c.com',
            'wildcard' => true,
            'reverse' => false
        ];

        [, , $searchString] = $this->baseSearch->exposeBuildSearchString($parameters);

        // Multiple underscores should all be preserved
        $this->assertEquals('%a_b_c.com%', $searchString);
        $this->assertEquals(2, substr_count($searchString, '_'));
    }

    public function testCombinedWildcardPatterns(): void
    {
        $parameters = [
            'query' => 'po%admin_test',
            'wildcard' => true,
            'reverse' => false
        ];

        [, , $searchString] = $this->baseSearch->exposeBuildSearchString($parameters);

        // Both % and _ should be preserved
        $this->assertEquals('%po%admin_test%', $searchString);
        $this->assertStringContainsString('%admin_', $searchString);
    }

    public function testNonWildcardPreservesUnderscoreAndPercent(): void
    {
        $parameters = [
            'query' => 'literal_with%chars',
            'wildcard' => false,
            'reverse' => false
        ];

        [, , $searchString] = $this->baseSearch->exposeBuildSearchString($parameters);

        // Without wildcard mode, literal underscores and percents should be kept
        $this->assertEquals('literal_with%chars', $searchString);
    }

    public function testIDNDomainSearch(): void
    {
        $parameters = [
            'query' => 'münchen.de',
            'wildcard' => true,
            'reverse' => false
        ];

        [, , $searchString] = $this->baseSearch->exposeBuildSearchString($parameters);

        // IDN domain should be converted to punycode
        $this->assertStringStartsWith('%', $searchString);
        $this->assertStringEndsWith('%', $searchString);
        // Punycode conversion happens via DnsIdnService::toPunycode
    }

    public function testSearchPatternForRecordContent(): void
    {
        $parameters = [
            'query' => '192.168.%',
            'wildcard' => true,
            'reverse' => false
        ];

        [, , $searchString] = $this->baseSearch->exposeBuildSearchString($parameters);

        // IP pattern with percent should work for content search
        $this->assertEquals('%192.168.%%', $searchString);
    }

    public function testExactMatchSearch(): void
    {
        $parameters = [
            'query' => 'exact.example.com',
            'wildcard' => false,
            'reverse' => false
        ];

        [, , $searchString] = $this->baseSearch->exposeBuildSearchString($parameters);

        // Exact match should have no wildcards
        $this->assertEquals('exact.example.com', $searchString);
        $this->assertStringNotContainsString('%', $searchString);
    }

    public function testQueryCaseSensitivity(): void
    {
        $parameters = [
            'query' => 'Example.COM',
            'wildcard' => true,
            'reverse' => false
        ];

        [, , $searchString] = $this->baseSearch->exposeBuildSearchString($parameters);

        // DNS is case-insensitive, search string should be lowercased
        // DnsIdnService::toPunycode() converts to lowercase
        $this->assertEquals('%example.com%', $searchString);
    }
}
