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


namespace Poweradmin\Tests\Unit\Domain\Enum;

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Enum\AccessScope;

class AccessScopeTest extends TestCase
{
    /**
     * Replaces the `$perm == 'own' || $perm == 'own_as_client'` disjunction that
     * was copy-pasted at ten sites.
     */
    public function testOwnedOnlyCoversBothOwnScopes(): void
    {
        $this->assertTrue(AccessScope::OWN->isOwnedOnly());
        $this->assertTrue(AccessScope::OWN_AS_CLIENT->isOwnedOnly());
        $this->assertFalse(AccessScope::ALL->isOwnedOnly());
        $this->assertFalse(AccessScope::NONE->isOwnedOnly());
    }

    public function testGrantsAnything(): void
    {
        $this->assertFalse(AccessScope::NONE->grantsAnything());
        $this->assertTrue(AccessScope::OWN->grantsAnything());
        $this->assertTrue(AccessScope::OWN_AS_CLIENT->grantsAnything());
        $this->assertTrue(AccessScope::ALL->grantsAnything());
    }

    public function testUnrestricted(): void
    {
        $this->assertTrue(AccessScope::ALL->isUnrestricted());
        $this->assertFalse(AccessScope::OWN->isUnrestricted());
    }

    /**
     * An unrecognised permission string must deny, never grant.
     */
    public function testUnknownValuesFallBackToNone(): void
    {
        $this->assertSame(AccessScope::NONE, AccessScope::fromString('nonsense'));
        $this->assertSame(AccessScope::NONE, AccessScope::fromString(null));
        $this->assertSame(AccessScope::NONE, AccessScope::fromString(''));
        $this->assertFalse(AccessScope::fromString('nonsense')->isOwnedOnly());
        $this->assertFalse(AccessScope::fromString('nonsense')->grantsAnything());
    }

    /**
     * The getters return lowercase; casing is not normalised, so a mismatch
     * must deny rather than silently grant.
     */
    public function testLookupIsCaseSensitive(): void
    {
        $this->assertSame(AccessScope::NONE, AccessScope::fromString('ALL'));
    }

    public function testKnownValuesRoundTrip(): void
    {
        foreach (['none', 'own', 'own_as_client', 'all'] as $value) {
            $this->assertSame($value, AccessScope::fromString($value)->value);
        }
    }
}
