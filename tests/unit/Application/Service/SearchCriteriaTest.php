<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2026 Poweradmin Development Team
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

namespace Poweradmin\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\SearchCriteria;

class SearchCriteriaTest extends TestCase
{
    public function testInitialPageLoadUsesDefaults(): void
    {
        $parameters = SearchCriteria::fromRequest(null)->toArray();

        $this->assertSame([
            'query' => '',
            'zones' => true,
            'records' => true,
            'wildcard' => true,
            'reverse' => true,
            'comments' => false,
            'type_filter' => '',
            'content_filter' => '',
        ], $parameters);

        // 'displayed_query' only exists for actual submissions
        $this->assertArrayNotHasKey('displayed_query', $parameters);
    }

    public function testEmptySubmissionTurnsToggleDefaultsOff(): void
    {
        $parameters = SearchCriteria::fromRequest([])->toArray();

        // Unchecked checkboxes are simply absent from POST, so a submission
        // without them means "off" even though the initial defaults are "on".
        $this->assertSame('', $parameters['query']);
        $this->assertSame('', $parameters['displayed_query']);
        $this->assertFalse($parameters['zones']);
        $this->assertFalse($parameters['records']);
        $this->assertFalse($parameters['wildcard']);
        $this->assertFalse($parameters['reverse']);
        $this->assertFalse($parameters['comments']);
        $this->assertSame('', $parameters['type_filter']);
        $this->assertSame('', $parameters['content_filter']);
    }

    public function testFormFieldsOverrideDefaults(): void
    {
        $parameters = SearchCriteria::fromRequest([
            'query' => 'example.com',
            'zones' => 'true',
            'records' => 'true',
            'comments' => 'true',
            'type_filter' => 'MX',
            'content_filter' => 'mail',
        ])->toArray();

        $this->assertSame('example.com', $parameters['query']);
        $this->assertSame('example.com', $parameters['displayed_query']);
        // Submitted checkbox values pass through as-is (truthy strings)
        $this->assertSame('true', $parameters['zones']);
        $this->assertSame('true', $parameters['records']);
        $this->assertSame('true', $parameters['comments']);
        $this->assertFalse($parameters['wildcard']);
        $this->assertFalse($parameters['reverse']);
        $this->assertSame('MX', $parameters['type_filter']);
        $this->assertSame('mail', $parameters['content_filter']);
    }

    public function testQueryEmbeddedTypeFilterOverridesFormFieldAndEnablesRecords(): void
    {
        $parameters = SearchCriteria::fromRequest([
            'query' => 'example type:txt',
            'zones' => 'true',
            'type_filter' => 'A',
        ])->toArray();

        // The embedded filter wins over the form dropdown, is uppercased,
        // and force-enables record search
        $this->assertSame('TXT', $parameters['type_filter']);
        $this->assertTrue($parameters['records']);

        // The filter is stripped from the search query but kept for display
        $this->assertSame('example', $parameters['query']);
        $this->assertSame('example type:txt', $parameters['displayed_query']);
    }

    public function testQueryEmbeddedContentFilterOverridesFormFieldAndEnablesRecords(): void
    {
        $parameters = SearchCriteria::fromRequest([
            'query' => 'example content:spf1',
            'zones' => 'true',
            'content_filter' => 'other',
        ])->toArray();

        $this->assertSame('spf1', $parameters['content_filter']);
        $this->assertTrue($parameters['records']);
        $this->assertSame('example', $parameters['query']);
        $this->assertSame('example content:spf1', $parameters['displayed_query']);
    }

    public function testFormTypeFilterIsUsedWhenQueryHasNoEmbeddedFilter(): void
    {
        $parameters = SearchCriteria::fromRequest([
            'query' => 'example.com',
            'records' => 'true',
            'type_filter' => 'AAAA',
        ])->toArray();

        $this->assertSame('AAAA', $parameters['type_filter']);
    }

    public function testBareIpv4QueryForceEnablesRecordsAndReverse(): void
    {
        // Detection uses IPAddressValidator (filter_var with FILTER_VALIDATE_IP)
        // on the cleaned query, so a bare IP acts as a PTR lookup even with the
        // records and reverse boxes unticked
        $parameters = SearchCriteria::fromRequest([
            'query' => '192.168.1.1',
            'zones' => 'true',
        ])->toArray();

        $this->assertTrue($parameters['records']);
        $this->assertTrue($parameters['reverse']);
    }

    public function testBareIpv6QueryForceEnablesRecordsAndReverse(): void
    {
        $parameters = SearchCriteria::fromRequest([
            'query' => '2001:db8::1',
        ])->toArray();

        $this->assertTrue($parameters['records']);
        $this->assertTrue($parameters['reverse']);
    }

    public function testNonIpQueryDoesNotForceEnableRecordsOrReverse(): void
    {
        $parameters = SearchCriteria::fromRequest([
            'query' => '192.168.1.999',
            'zones' => 'true',
        ])->toArray();

        $this->assertFalse($parameters['records']);
        $this->assertFalse($parameters['reverse']);
    }

    public function testFiltersAreClearedWhenRecordSearchIsDisabled(): void
    {
        $parameters = SearchCriteria::fromRequest([
            'query' => 'example.com',
            'zones' => 'true',
            'type_filter' => 'A',
            'content_filter' => 'something',
        ])->toArray();

        // Records search was not enabled, so record-only filters are dropped
        $this->assertFalse($parameters['records']);
        $this->assertSame('', $parameters['type_filter']);
        $this->assertSame('', $parameters['content_filter']);
    }

    public function testFullRoundTripMatchesExpectedArrayShape(): void
    {
        $parameters = SearchCriteria::fromRequest([
            'query' => '  example   type:txt content:v=spf1 ',
            'zones' => 'true',
            'records' => 'true',
            'wildcard' => 'true',
            'reverse' => 'true',
            'comments' => 'true',
            'type_filter' => 'A',
            'content_filter' => 'ignored',
        ])->toArray();

        $this->assertSame([
            'query' => 'example',
            'zones' => 'true',
            'records' => true,
            'wildcard' => 'true',
            'reverse' => 'true',
            'comments' => 'true',
            'type_filter' => 'TXT',
            'content_filter' => 'v=spf1',
            'displayed_query' => '  example   type:txt content:v=spf1 ',
        ], $parameters);
    }
}
