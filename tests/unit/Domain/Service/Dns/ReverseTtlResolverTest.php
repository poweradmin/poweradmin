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

namespace Poweradmin\Tests\Unit\Domain\Service\Dns;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Repository\RecordTypeDefaultRepositoryInterface;
use Poweradmin\Domain\Service\Dns\ReverseTtlResolver;
use TestHelpers\FakeConfiguration;

class ReverseTtlResolverTest extends TestCase
{
    /**
     * @param array<string, int> $typeDefaults
     */
    private function createResolver(mixed $reverseTtl, int $defaultTtl = 86400, array $typeDefaults = []): ReverseTtlResolver
    {
        $config = new FakeConfiguration(['dns' => ['ttl_reverse' => $reverseTtl, 'ttl' => $defaultTtl]]);

        $repo = $this->createMock(RecordTypeDefaultRepositoryInterface::class);
        $repo->method('find')->willReturnCallback(fn(string $type) => $typeDefaults[strtoupper($type)] ?? null);
        $repo->expects($this->atMost(1))->method('findAll')->willReturn($typeDefaults);

        return new ReverseTtlResolver($config, $repo);
    }

    public function testGetDefaultTtlReturnsDnsTtlWhenReverseTtlUnsetAndReverseZone(): void
    {
        $resolver = $this->createResolver(reverseTtl: null);
        $this->assertSame(86400, $resolver->getDefaultTtl(true));
    }

    public function testGetDefaultTtlReturnsDnsTtlWhenReverseTtlUnsetAndForwardZone(): void
    {
        $resolver = $this->createResolver(reverseTtl: null);
        $this->assertSame(86400, $resolver->getDefaultTtl(false));
    }

    public function testGetDefaultTtlReturnsReverseTtlWhenSetAndReverseZone(): void
    {
        $resolver = $this->createResolver(reverseTtl: 300);
        $this->assertSame(300, $resolver->getDefaultTtl(true));
    }

    public function testGetDefaultTtlIgnoresReverseTtlOnForwardZone(): void
    {
        $resolver = $this->createResolver(reverseTtl: 300);
        $this->assertSame(86400, $resolver->getDefaultTtl(false));
    }

    public function testGetDefaultTtlFallsBackOnEmptyStringReverseTtl(): void
    {
        $resolver = $this->createResolver(reverseTtl: '');
        $this->assertSame(86400, $resolver->getDefaultTtl(true));
    }

    public function testGetDefaultTtlRespectsExplicitZeroReverseTtl(): void
    {
        $resolver = $this->createResolver(reverseTtl: 0);
        $this->assertSame(0, $resolver->getDefaultTtl(true));
    }

    public function testGetDefaultTtlCoercesStringReverseTtlToInt(): void
    {
        $resolver = $this->createResolver(reverseTtl: '600');
        $this->assertSame(600, $resolver->getDefaultTtl(true));
    }

    public function testResolvePtrTtlReturnsForwardTtlWhenReverseTtlUnset(): void
    {
        $resolver = $this->createResolver(reverseTtl: null);
        $this->assertSame(300, $resolver->resolvePtrTtl(300));
    }

    public function testResolvePtrTtlPrefersReverseTtlWhenSet(): void
    {
        $resolver = $this->createResolver(reverseTtl: 900);
        $this->assertSame(900, $resolver->resolvePtrTtl(300));
    }

    public function testResolvePtrTtlFallsBackOnEmptyReverseTtl(): void
    {
        $resolver = $this->createResolver(reverseTtl: '');
        $this->assertSame(300, $resolver->resolvePtrTtl(300));
    }

    public function testResolvePtrTtlRespectsExplicitZero(): void
    {
        $resolver = $this->createResolver(reverseTtl: 0);
        $this->assertSame(0, $resolver->resolvePtrTtl(300));
    }

    public function testGetConfiguredReverseTtlReturnsNullWhenUnset(): void
    {
        $resolver = $this->createResolver(reverseTtl: null);
        $this->assertNull($resolver->getConfiguredReverseTtl());
    }

    public function testGetConfiguredReverseTtlReturnsNullWhenEmptyString(): void
    {
        $resolver = $this->createResolver(reverseTtl: '');
        $this->assertNull($resolver->getConfiguredReverseTtl());
    }

    public function testGetConfiguredReverseTtlReturnsIntWhenSet(): void
    {
        $resolver = $this->createResolver(reverseTtl: 300);
        $this->assertSame(300, $resolver->getConfiguredReverseTtl());
    }

    public function testGetConfiguredReverseTtlReturnsZeroWhenExplicitZero(): void
    {
        $resolver = $this->createResolver(reverseTtl: 0);
        $this->assertSame(0, $resolver->getConfiguredReverseTtl());
    }

    public function testResolveTtlForTypePtrInReverseZoneUsesReverseTtl(): void
    {
        $resolver = $this->createResolver(reverseTtl: 300);
        $this->assertSame(300, $resolver->resolveTtlForType('PTR', true));
    }

    public function testResolveTtlForTypePtrFallsBackToDnsTtlWhenReverseUnset(): void
    {
        $resolver = $this->createResolver(reverseTtl: null);
        $this->assertSame(86400, $resolver->resolveTtlForType('PTR', true));
    }

    public function testResolveTtlForTypeNonPtrIgnoresReverseTtl(): void
    {
        $resolver = $this->createResolver(reverseTtl: 300);
        $this->assertSame(86400, $resolver->resolveTtlForType('NS', true));
        $this->assertSame(86400, $resolver->resolveTtlForType('CNAME', true));
        $this->assertSame(86400, $resolver->resolveTtlForType('A', false));
    }

    public function testResolveTtlForTypePtrInForwardZoneKeepsDnsTtl(): void
    {
        $resolver = $this->createResolver(reverseTtl: 300);
        $this->assertSame(86400, $resolver->resolveTtlForType('PTR', false));
    }

    public function testResolveTtlForTypeIsCaseInsensitive(): void
    {
        $resolver = $this->createResolver(reverseTtl: 300);
        $this->assertSame(300, $resolver->resolveTtlForType('ptr', true));
        $this->assertSame(300, $resolver->resolveTtlForType('Ptr', true));
    }

    public function testRecordTypeDefaultBeatsReverseTtlConfig(): void
    {
        $resolver = $this->createResolver(reverseTtl: 300, typeDefaults: ['PTR' => 60]);
        $this->assertSame(60, $resolver->resolveTtlForType('PTR', true));
    }

    public function testRecordTypeDefaultAppliesToNonPtrTypes(): void
    {
        $resolver = $this->createResolver(reverseTtl: null, typeDefaults: ['MX' => 1800]);
        $this->assertSame(1800, $resolver->resolveTtlForType('MX', false));
    }

    public function testRecordTypeDefaultAppliesRegardlessOfZoneDirection(): void
    {
        $resolver = $this->createResolver(reverseTtl: null, typeDefaults: ['NS' => 7200]);
        $this->assertSame(7200, $resolver->resolveTtlForType('NS', true));
        $this->assertSame(7200, $resolver->resolveTtlForType('NS', false));
    }

    public function testFallsBackToReverseTtlConfigWhenNoTypeDefault(): void
    {
        $resolver = $this->createResolver(reverseTtl: 300, typeDefaults: ['A' => 60]);
        $this->assertSame(300, $resolver->resolveTtlForType('PTR', true));
    }

    public function testFallsBackToDnsTtlWhenNoOverridesApply(): void
    {
        $resolver = $this->createResolver(reverseTtl: null, typeDefaults: ['MX' => 1800]);
        $this->assertSame(86400, $resolver->resolveTtlForType('A', false));
    }

    public function testResolveTtlsForTypesAppliesThePrecedencePerType(): void
    {
        $resolver = $this->createResolver(3600, 86400, ['MX' => 300]);
        // Every type must agree with the single-type resolution it replaces.
        foreach (['A', 'PTR', 'MX'] as $type) {
            $this->assertSame($resolver->resolveTtlForType($type, true), $resolver->resolveTtlsForTypes([$type], true)[$type]);
        }

        $this->assertSame(
            ['A' => 86400, 'PTR' => 3600, 'MX' => 300],
            $resolver->resolveTtlsForTypes(['A', 'ptr', 'MX'], true)
        );
        $this->assertSame(['PTR' => 86400], $resolver->resolveTtlsForTypes(['PTR'], false));
        // The controller passes both maps to the form; the defaults are read once.
        $this->assertSame(['MX' => 300], $resolver->getTypeDefaults());
    }
}
