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


namespace Unit\Domain\Service\Dns;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Dns\RecordManager;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Domain\Service\DnsRecordValidationServiceInterface;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use TestHelpers\SqliteIntegrationTestCase;

/**
 * A comment saved on a zone without a zones row must not make user 1 the owner of that zone.
 */
class RecordManagerZoneCommentOwnerTest extends SqliteIntegrationTestCase
{
    #[RunInSeparateProcess]
    public function testTheRowCreatedForTheCommentHasNoOwner(): void
    {
        $this->db->exec('CREATE TABLE zones (id INTEGER PRIMARY KEY AUTOINCREMENT, domain_id INTEGER, owner INTEGER, comment TEXT, zone_templ_id INTEGER NOT NULL DEFAULT 0)');

        $this->db->exec('CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER NOT NULL, group_id INTEGER NOT NULL, created_at TEXT)');

        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $domainRepository->method('getDomainType')->willReturn('MASTER');

        $manager = new RecordManager(
            $this->db,
            $this->createMock(ConfigurationManager::class),
            $this->createMock(DnsRecordValidationServiceInterface::class),
            $this->createMock(SOARecordManagerInterface::class),
            $domainRepository,
            $this->dnsBackendStub(false)
        );

        $this->assertTrue($manager->editZoneComment(42, 'hello'));

        $row = $this->db->query('SELECT owner, comment FROM zones WHERE domain_id = 42')->fetch(\PDO::FETCH_ASSOC);
        $this->assertNull($row['owner']);
        $this->assertSame('hello', $row['comment']);
    }
}
