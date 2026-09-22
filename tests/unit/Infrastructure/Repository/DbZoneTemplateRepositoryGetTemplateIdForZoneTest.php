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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Poweradmin\Infrastructure\Repository\DbZoneTemplateRepository;
use TestHelpers\SqliteIntegrationTestCase;

/**
 * Issue #935: getTemplateIdForZone() must return an int for every row shape a
 * backend can produce, including a NULL zone_templ_id and a missing zone,
 * rather than tripping the return type on PHP 8.4.
 *
 * @see https://github.com/poweradmin/poweradmin/issues/935
 */
#[CoversClass(DbZoneTemplateRepository::class)]
class DbZoneTemplateRepositoryGetTemplateIdForZoneTest extends SqliteIntegrationTestCase
{
    private DbZoneTemplateRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        // zone_templ_id is NOT NULL in Poweradmin's own schema, but a hand-migrated
        // or PostgreSQL-imported install can still hold NULL there
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, zone_templ_id INTEGER)");
        $this->db->exec("INSERT INTO zones (domain_id, owner, zone_templ_id) VALUES
            (1, " . self::ADMIN_USER_ID . ", 5),
            (2, " . self::ADMIN_USER_ID . ", 0),
            (3, " . self::ADMIN_USER_ID . ", NULL)");

        $this->repository = new DbZoneTemplateRepository($this->db);
    }

    #[Test]
    public function aZoneLinkedToATemplateReturnsItsId(): void
    {
        $this->assertSame(5, $this->repository->getTemplateIdForZone(1));
    }

    #[Test]
    public function aZoneWithoutATemplateReturnsZero(): void
    {
        $this->assertSame(0, $this->repository->getTemplateIdForZone(2));
    }

    #[Test]
    public function aNullTemplateColumnReturnsZero(): void
    {
        $this->assertSame(0, $this->repository->getTemplateIdForZone(3));
    }

    #[Test]
    public function anUnknownZoneReturnsZero(): void
    {
        $this->assertSame(0, $this->repository->getTemplateIdForZone(99999));
    }

    #[Test]
    public function assigningATemplateIsReadBack(): void
    {
        $this->repository->assignTemplateToZone(2, 7);

        $this->assertSame(7, $this->repository->getTemplateIdForZone(2));
    }
}
