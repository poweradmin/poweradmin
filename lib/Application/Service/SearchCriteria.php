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

namespace Poweradmin\Application\Service;

use Poweradmin\Domain\Service\DnsValidation\IPAddressValidator;

/**
 * Value object holding the search parameters the search page works with.
 *
 * The parameters are resolved from three precedence layers, highest first:
 *
 * 1. Filters embedded in the query itself ("type:txt", "content:spf") beat the
 *    corresponding form fields and also force-enable record search, since a
 *    typed-in filter can only apply to records.
 * 2. Submitted form fields (checkbox toggles, type/content filter dropdowns)
 *    beat the defaults; an unsubmitted checkbox means "off".
 * 3. Defaults apply when the form was not submitted at all (initial page load):
 *    zones, records, wildcard and reverse search on, comments off, no filters.
 *
 * Two special rules sit on top:
 * - A bare IP query always searches records and reverse zones, even when those
 *   boxes were not ticked - that is almost certainly a PTR lookup.
 * - When record search ends up disabled, the type/content filters are cleared,
 *   as they only ever apply to records.
 */
final class SearchCriteria
{
    /**
     * @param array $parameters The fully resolved search parameters
     */
    private function __construct(private readonly array $parameters)
    {
    }

    /**
     * Build the criteria from a submitted search form, or the defaults when the
     * page was requested without a submission.
     *
     * @param array|null $postParams The POST parameters, or null when the request was not a POST
     */
    public static function fromRequest(?array $postParams): self
    {
        $parameters = [
            'query' => '',
            'zones' => true,
            'records' => true,
            'wildcard' => true,
            'reverse' => true,
            'comments' => false,
            'type_filter' => '',
            'content_filter' => '',
        ];

        if ($postParams === null) {
            return new self($parameters);
        }

        $query = $postParams['query'] ?? null;
        $rawQuery = !empty($query) ? $query : '';

        // Parse query for embedded filters
        list($cleanQuery, $extractedFilters) = self::parseQueryFilters($rawQuery);

        // Keep the original query for display in the search box
        $displayed_query = $rawQuery;

        // Use the cleaned query (without filters) for actual searching
        $parameters['query'] = $cleanQuery;

        // Store the original query for display purposes
        $parameters['displayed_query'] = $displayed_query;

        $parameters['zones'] = $postParams['zones'] ?? false;
        $parameters['records'] = $postParams['records'] ?? false;
        $parameters['wildcard'] = $postParams['wildcard'] ?? false;
        $parameters['reverse'] = $postParams['reverse'] ?? false;
        $parameters['comments'] = $postParams['comments'] ?? false;

        // A bare IP query should always search records and reverse zones, even when
        // the user did not tick those boxes - that is almost certainly a PTR lookup.
        $ipValidator = new IPAddressValidator();
        if ($ipValidator->isValidIPv4($parameters['query']) || $ipValidator->isValidIPv6($parameters['query'])) {
            $parameters['records'] = true;
            $parameters['reverse'] = true;
        }

        // Only use extracted type and content filters from the query string
        // This ensures filters from the search box always take precedence
        if (!empty($extractedFilters['type'])) {
            $parameters['type_filter'] = $extractedFilters['type'];
            // Enable records search if type filter is found in query
            $parameters['records'] = true;
        } else {
            // Only use form field if no filter in query string
            $parameters['type_filter'] = $postParams['type_filter'] ?? '';
        }

        if (!empty($extractedFilters['content'])) {
            $parameters['content_filter'] = $extractedFilters['content'];
            // Enable records search if content filter is found in query
            $parameters['records'] = true;
        } else {
            // Only use form field if no filter in query string
            $parameters['content_filter'] = $postParams['content_filter'] ?? '';
        }

        // If records search is disabled, clear the filters
        if (!$parameters['records']) {
            $parameters['type_filter'] = '';
            $parameters['content_filter'] = '';
        }

        return new self($parameters);
    }

    /**
     * The resolved parameters in the exact shape the search services and
     * templates consume; 'displayed_query' is present only for submissions.
     */
    public function toArray(): array
    {
        return $this->parameters;
    }

    /**
     * Parse query string for embedded filters like "type:txt" or "content:spf"
     *
     * @param string $query The search query to parse
     * @return array Array containing the cleaned query and extracted filters
     */
    private static function parseQueryFilters(string $query): array
    {
        $filters = [
            'type' => '',
            'content' => '',
        ];

        // Match patterns like "type:txt" or "type: txt" or "type:TXT" (case insensitive)
        if (preg_match('/\btype:\s*([a-z0-9_]+)\b/i', $query, $matches)) {
            $filters['type'] = strtoupper($matches[1]); // Convert to uppercase for consistency
            $query = str_replace($matches[0], '', $query); // Remove from query
        }

        // Match patterns like "content:spf" or "content: value"
        if (preg_match('/\bcontent:\s*([^\s]+)\b/i', $query, $matches)) {
            $filters['content'] = $matches[1];
            $query = str_replace($matches[0], '', $query); // Remove from query
        }

        // Cleanup query (remove extra spaces)
        $query = trim(preg_replace('/\s+/', ' ', $query));

        return [$query, $filters];
    }
}
