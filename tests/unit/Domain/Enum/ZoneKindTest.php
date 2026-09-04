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
use Poweradmin\Domain\Enum\ZoneKind;
use Poweradmin\Domain\Model\ZoneType;

class ZoneKindTest extends TestCase
{
    /**
     * ZoneType is a delegating shim now; these pin the values it still exposes.
     */
    public function testZoneTypeConstantsMatchTheEnum(): void
    {
        $this->assertSame(ZoneKind::MASTER->value, ZoneType::MASTER);
        $this->assertSame(ZoneKind::SLAVE->value, ZoneType::SLAVE);
        $this->assertSame(ZoneKind::NATIVE->value, ZoneType::NATIVE);
        $this->assertSame(ZoneKind::PRODUCER->value, ZoneType::PRODUCER);
        $this->assertSame(ZoneKind::CONSUMER->value, ZoneType::CONSUMER);
    }

    public function testShimDelegatesReturnTheSameSets(): void
    {
        $this->assertSame(ZoneKind::basicValues(), ZoneType::getTypes());
        $this->assertSame(ZoneKind::values(), ZoneType::getAllTypes());
        $this->assertSame(['SLAVE', 'CONSUMER'], ZoneType::getReplicatingTypes());
    }

    /**
     * The API and import validators accept only the three basic kinds; the
     * catalog kinds are deliberately not in that set.
     */
    public function testBasicValuesExcludeTheCatalogKinds(): void
    {
        $this->assertSame(['MASTER', 'SLAVE', 'NATIVE'], ZoneKind::basicValues());
        $this->assertNotContains('PRODUCER', ZoneKind::basicValues());
        $this->assertNotContains('CONSUMER', ZoneKind::basicValues());
    }

    public function testReplicatingKinds(): void
    {
        $this->assertTrue(ZoneKind::SLAVE->replicatesFromPrimary());
        $this->assertTrue(ZoneKind::CONSUMER->replicatesFromPrimary());
        $this->assertFalse(ZoneKind::MASTER->replicatesFromPrimary());
        $this->assertFalse(ZoneKind::NATIVE->replicatesFromPrimary());
        $this->assertFalse(ZoneKind::PRODUCER->replicatesFromPrimary());
    }

    public function testNotifyingKinds(): void
    {
        $this->assertTrue(ZoneKind::MASTER->notifies());
        $this->assertTrue(ZoneKind::PRODUCER->notifies());
        $this->assertFalse(ZoneKind::SLAVE->notifies());
        $this->assertFalse(ZoneKind::NATIVE->notifies());
        $this->assertFalse(ZoneKind::CONSUMER->notifies());
    }

    public function testLookupAcceptsAnyCasingAndRejectsGarbage(): void
    {
        $this->assertSame(ZoneKind::MASTER, ZoneKind::tryFromName('master'));
        $this->assertSame(ZoneKind::SLAVE, ZoneKind::tryFromName('Slave'));
        $this->assertNull(ZoneKind::tryFromName('PRIMARY'));
        $this->assertNull(ZoneKind::tryFromName(null));
    }

    /**
     * An unknown kind must not read as read-only, which would silently lock a
     * zone's records in the UI.
     */
    public function testShimPredicatesTreatUnknownKindsAsFalse(): void
    {
        $this->assertFalse(ZoneType::isReadOnly('PRIMARY'));
        $this->assertFalse(ZoneType::isReadOnly(null));
        $this->assertFalse(ZoneType::replicatesFromPrimary('nonsense'));
        $this->assertFalse(ZoneType::notifies(null));
    }

    public function testShimPredicatesStillAcceptLowercase(): void
    {
        $this->assertTrue(ZoneType::isReadOnly('slave'));
        $this->assertTrue(ZoneType::notifies('master'));
    }

    public function testCreatableKindsDependOnCatalogSupport(): void
    {
        $this->assertSame(['MASTER', 'NATIVE'], ZoneKind::creatableValues(false, true));
        $this->assertSame(['MASTER', 'NATIVE', 'PRODUCER'], ZoneKind::creatableValues(true, false));
        $this->assertSame(['MASTER', 'NATIVE', 'PRODUCER', 'CONSUMER'], ZoneKind::creatableValues(true, true));
    }
}
