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

namespace Poweradmin\Infrastructure\Repository;

use PDO;
use Poweradmin\Domain\Database\CanonicalZoneSql;
use Poweradmin\Domain\Port\DnsBackendProviderInterface;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\DnsValidation\IPAddressValidator;
use Poweradmin\Domain\Utility\DnsIdnService;

/**
 * Shared parts of the API-mode searches: query preprocessing that mirrors
 * BaseSearch, and the ownership lookups against Poweradmin's own zones table.
 */
abstract class ApiSearchBase
{
    public function __construct(
        protected PDO $db,
        protected DnsBackendProviderInterface $backendProvider,
        protected ZoneRepositoryInterface $zoneRepository
    ) {
    }

    /**
     * Punycode normalization and reverse IP expansion, matching what
     * BaseSearch::buildSearchString() does for the SQL search.
     */
    protected function preprocessSearchQuery(array $parameters): array
    {
        $query = trim($parameters['query'] ?? '');

        $query = DnsIdnService::toPunycode($query);

        $parameters['query'] = $query;

        $reverseQuery = '';
        if (!empty($parameters['reverse'])) {
            $ipValidator = new IPAddressValidator();
            if ($ipValidator->isValidIPv4($query)) {
                $reverseQuery = implode('.', array_reverse(explode('.', $query)));
            } elseif ($ipValidator->isValidIPv6($query)) {
                $hex = unpack('H*hex', inet_pton($query));
                $reverseQuery = implode('.', array_reverse(str_split($hex['hex'])));
            }
        }
        $parameters['reverse_query'] = $reverseQuery;

        return $parameters;
    }

    /**
     * @return int[]
     */
    protected function ownedZoneIds(int $userId): array
    {
        return $this->zoneRepository->getOwnedZoneIds($userId);
    }

    protected function canonicalZoneId(string $alias): string
    {
        return CanonicalZoneSql::canonicalIdColumn($alias, $this->backendProvider->allocatesZoneIdsLocally());
    }
}
