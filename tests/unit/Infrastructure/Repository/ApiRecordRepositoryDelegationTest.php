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

namespace Poweradmin\Tests\Unit\Infrastructure\Repository;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Infrastructure\Repository\ApiRecordRepository;

/**
 * Which records at a name block creating a zone there in API backend mode.
 */
class ApiRecordRepositoryDelegationTest extends TestCase
{
    /** @param list<string> $types */
    private function hasNonDelegationRecords(array $types): bool
    {
        $rows = array_map(fn(string $type): array => ['name' => 'sub.example.com', 'type' => $type, 'content' => 'x'], $types);
        $provider = $this->createMock(DnsBackendProvider::class);
        $provider->method('searchDnsData')->with('sub.example.com', 'record', 10000, true)->willReturn(['records' => $rows]);

        return (new ApiRecordRepository($provider))->hasNonDelegationRecords('sub.example.com');
    }

    /** @return array<string, array{list<string>, bool}> */
    public static function rrsets(): array
    {
        return [
            'nothing' => [[], false],
            'unsigned delegation' => [['NS'], false],
            'signed delegation' => [['NS', 'DS', 'NSEC', 'RRSIG'], false],
            'address at the cut' => [['NS', 'RRSIG', 'A'], true],
        ];
    }

    /** @param list<string> $types */
    #[DataProvider('rrsets')]
    public function testOnlyDelegationPointDataLetsAZoneBeCreated(array $types, bool $blocked): void
    {
        $this->assertSame($blocked, $this->hasNonDelegationRecords($types));
    }
}
