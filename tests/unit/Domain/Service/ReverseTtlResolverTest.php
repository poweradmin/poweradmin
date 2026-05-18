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

namespace Poweradmin\Tests\Unit\Domain\Service;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\ReverseTtlResolver;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

class ReverseTtlResolverTest extends TestCase
{
    private function createResolver(mixed $reverseTtl, int $defaultTtl = 86400): ReverseTtlResolver
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(function (string $group, string $key, mixed $default = null) use ($reverseTtl, $defaultTtl) {
            if ($group === 'dns' && $key === 'ttl_reverse') {
                return $reverseTtl;
            }
            if ($group === 'dns' && $key === 'ttl') {
                return $defaultTtl;
            }
            return $default;
        });

        return new ReverseTtlResolver($config);
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
}
