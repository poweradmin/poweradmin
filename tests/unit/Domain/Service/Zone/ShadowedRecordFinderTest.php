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

namespace Poweradmin\Tests\Unit\Domain\Service\Zone;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\RecordLookupInterface;
use Poweradmin\Domain\Service\Zone\ShadowedRecordFinder;
use Poweradmin\Domain\Service\Zone\ShadowedRecords;

#[CoversClass(ShadowedRecordFinder::class)]
class ShadowedRecordFinderTest extends TestCase
{
    /**
     * @param array<string, int> $zones
     * @param list<array{name: string, type: string, content: string}> $records
     */
    private function finder(array $zones, array $records, ?int &$queriedZone = null): ShadowedRecordFinder
    {
        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('findZoneIdsByNames')->willReturnCallback(
            fn(array $names): array => array_intersect_key($zones, array_flip($names))
        );
        $domains->method('findZonesUnder')->willReturnCallback(function (string $suffix) use ($zones): array {
            $under = [];
            foreach ($zones as $name => $id) {
                if (str_ends_with((string)$name, '.' . $suffix)) {
                    $under[] = ['id' => $id, 'name' => (string)$name];
                }
            }
            return $under;
        });
        $lookup = $this->createMock(RecordLookupInterface::class);
        $lookup->method('getRecordsAtOrUnder')->willReturnCallback(function (int $id) use ($records, &$queriedZone): array {
            $queriedZone = $id;
            return $records;
        });

        return new ShadowedRecordFinder($domains, $lookup);
    }

    /**
     * @param array<string, int> $zones
     * @param list<array{name: string, type: string, content: string}> $records
     */
    private function find(array $zones, array $records, string $zoneName, ?int &$queriedZone = null): ?ShadowedRecords
    {
        $finder = $this->finder($zones, $records, $queriedZone);
        $parent = $finder->closestParent($zoneName);

        return $parent === null ? null : $finder->hiddenRecords($parent, $zoneName);
    }

    public function testNoParentZoneMeansNothingIsHidden(): void
    {
        $this->assertNull($this->finder([], [])->closestParent('sub.example.com'));
    }

    public function testTheClosestParentZoneIsScanned(): void
    {
        $queried = null;
        $found = $this->find(
            ['example.com' => 1, 'a.example.com' => 2],
            [['name' => 'www.b.a.example.com', 'type' => 'A', 'content' => '192.0.2.1']],
            'b.a.example.com.',
            $queried
        );

        $this->assertSame(2, $queried);
        $this->assertSame('a.example.com', $found?->parentZoneName);
    }

    public function testGlueIsMatchedAgainstNsTargetsWithATrailingDot(): void
    {
        $found = $this->find(['example.com' => 1], [
            ['name' => 'sub.example.com.', 'type' => 'NS', 'content' => 'NS1.sub.example.com.'],
            ['name' => 'ns1.sub.example.com.', 'type' => 'AAAA', 'content' => '2001:db8::1'],
            ['name' => 'ns1.sub.example.com.', 'type' => 'TXT', 'content' => 'not glue'],
        ], 'Sub.Example.com');

        $this->assertSame([['name' => 'ns1.sub.example.com', 'type' => 'TXT']], $found?->records);
    }

    public function testOnlyDelegationDataMeansNothingIsHidden(): void
    {
        $this->assertNull($this->find(['example.com' => 1], [
            ['name' => 'sub.example.com', 'type' => 'NS', 'content' => 'ns.other.net'],
            ['name' => 'sub.example.com', 'type' => 'DS', 'content' => '1 13 2 abcd'],
        ], 'sub.example.com'));
    }

    public function testARootZoneIsTheLastParent(): void
    {
        $found = $this->find(['.' => 9], [['name' => 'www.example', 'type' => 'A', 'content' => '192.0.2.1']], 'example');

        $this->assertSame('.', $found?->parentZoneName);
        $this->assertNull($this->finder(['.' => 9], [])->closestParent('.'));
    }

    public function testRecordsUnderAnExistingDeeperZoneAreAlreadyHidden(): void
    {
        $found = $this->find(['example.com' => 1, 'deep.sub.example.com' => 3], [
            ['name' => 'www.deep.sub.example.com', 'type' => 'A', 'content' => '192.0.2.1'],
            ['name' => 'www.sub.example.com', 'type' => 'A', 'content' => '192.0.2.2'],
        ], 'sub.example.com');

        $this->assertSame([['name' => 'www.sub.example.com', 'type' => 'A']], $found?->records);
    }

    public function testTheParentsDelegationOfADeeperZoneMovesToTheNewZone(): void
    {
        $found = $this->find(['example.com' => 1, 'deep.sub.example.com' => 3, 'deeper.deep.sub.example.com' => 4], [
            ['name' => 'deep.sub.example.com', 'type' => 'DS', 'content' => '1 13 2 abcd'],
            ['name' => 'deep.sub.example.com', 'type' => 'NS', 'content' => 'ns.deep.sub.example.com'],
            ['name' => 'ns.deep.sub.example.com', 'type' => 'A', 'content' => '192.0.2.1'],
            ['name' => 'www.deep.sub.example.com', 'type' => 'A', 'content' => '192.0.2.2'],
            ['name' => 'deeper.deep.sub.example.com', 'type' => 'NS', 'content' => 'ns.other.net'],
        ], 'sub.example.com');

        $this->assertSame([
            ['name' => 'deep.sub.example.com', 'type' => 'DS'],
            ['name' => 'deep.sub.example.com', 'type' => 'NS'],
            ['name' => 'ns.deep.sub.example.com', 'type' => 'A'],
        ], $found?->records);
    }
}
