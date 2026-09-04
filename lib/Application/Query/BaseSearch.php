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

namespace Poweradmin\Application\Query;

use PDO;
use Poweradmin\Domain\Service\DnsIdnService;
use Poweradmin\Domain\Service\DnsValidation\IPAddressValidator;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Domain\Utility\DomainUtility;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;
use Poweradmin\Infrastructure\Database\DbCompat;

abstract class BaseSearch
{
    protected object $db;
    protected string $db_type;
    protected ConfigurationInterface $config;
    protected IPAddressValidator $ipValidator;
    protected UserContextService $userContext;

    public function __construct($db, $config, string $db_type, ?IPAddressValidator $ipValidator = null, ?UserContextService $userContext = null)
    {
        $this->db = $db;
        $this->config = $config;
        $this->db_type = $db_type;
        $this->ipValidator = $ipValidator ?? new IPAddressValidator();
        $this->userContext = $userContext ?? new UserContextService();
    }

    /**
     * Builds the search string for the given parameters.
     *
     * @param array $parameters An array containing search parameters, including
     *                          'reverse' for reverse DNS lookup, 'query' for the search term,
     *                          and 'wildcard' for enabling/disabling wildcard search.
     * @return array An array containing the reverse search string, the updated parameters, and
     *               the generated search string.
     */
    protected function buildSearchString(array $parameters): array
    {
        $reverse_search_string = '';

        if ($parameters['reverse']) {
            // Anchor the reversed query to the full PTR name (e.g. "5.0.0.10.in-addr.arpa")
            // so the LIKE pattern matches reverse-zone record names instead of any string
            // that merely contains the reversed octets.
            if ($this->ipValidator->isValidIPv4($parameters['query'])) {
                $reverse_search_string = DomainUtility::convertIPv4AddrToPtrRec($parameters['query']);
            } elseif ($this->ipValidator->isValidIPv6($parameters['query'])) {
                $reverse_search_string = DomainUtility::convertIPv6AddrToPtrRec($parameters['query']);
            } else {
                $parameters['reverse'] = false;
            }

            $reverse_search_string = '%' . $reverse_search_string . '%';
        }

        if (isset($parameters['comments']) && $parameters['comments']) {
            $parameters['wildcard'] = true;
        }

        $needle = DnsIdnService::toPunycode(trim($parameters['query']));
        $search_string = ($parameters['wildcard'] ? '%' : '') . $needle . ($parameters['wildcard'] ? '%' : '');
        return array($reverse_search_string, $parameters, $search_string);
    }

    /**
     * The needle for free-text columns. Record content and comments hold whatever the
     * user typed, so the punycoded form of the query cannot match them.
     */
    protected function buildRawSearchString(array $parameters): string
    {
        $needle = trim((string)($parameters['query'] ?? ''));
        $wildcard = !empty($parameters['wildcard']);
        return ($wildcard ? '%' : '') . $needle . ($wildcard ? '%' : '');
    }

    /**
     * Handles SQL mode for MySQL database connection by disabling 'ONLY_FULL_GROUP_BY' if needed.
     *
     * @return string The original SQL mode if modified, or an empty string if no change was needed or not using MySQL.
     */
    /**
     * Punycode names whose decoded form contains the query.
     *
     * The punycode of a substring is not a substring of the punycode, so a partial
     * query like "munch" can never match "xn--mnchen-3ya" through LIKE. Decoding the
     * IDN names and comparing in PHP is bounded to the rows that are actually punycode.
     *
     * @return string[]
     */
    protected function idnNamesMatching(string $table, string $column, string $query): array
    {
        $needle = mb_strtolower(trim($query), 'UTF-8');
        if ($needle === '') {
            return [];
        }

        $stmt = $this->db->prepare("SELECT DISTINCT $column FROM $table WHERE $column LIKE 'xn--%' OR $column LIKE '%.xn--%'");
        $stmt->execute();

        $matching = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN, 0) as $name) {
            if (str_contains(mb_strtolower(DnsIdnService::toUtf8((string)$name), 'UTF-8'), $needle)) {
                $matching[] = $name;
            }
        }

        return $matching;
    }

    /**
     * Extend a name predicate so partial IDN queries match. Returns the SQL to append,
     * binding one placeholder per matching name into $params.
     */
    protected function idnNameCondition(string $table, string $column, string $query, string $prefix, array &$params): string
    {
        $names = $this->idnNamesMatching($table, $column, $query);
        if ($names === []) {
            return '';
        }

        $placeholders = [];
        foreach (array_values($names) as $index => $name) {
            $placeholder = ":{$prefix}{$index}";
            $placeholders[] = $placeholder;
            $params[$placeholder] = $name;
        }

        return " OR $column IN (" . implode(', ', $placeholders) . ")";
    }

    protected function handleSqlMode(): string
    {
        return DbCompat::handleSqlMode($this->db, $this->db_type);
    }

    /**
     * Restores the original SQL mode for the MySQL database connection if needed.
     *
     * @param string $originalSqlMode The original SQL mode to be restored.
     * @return void
     */
    protected function restoreSqlMode(string $originalSqlMode): void
    {
        DbCompat::restoreSqlMode($this->db, $this->db_type, $originalSqlMode);
    }
}
