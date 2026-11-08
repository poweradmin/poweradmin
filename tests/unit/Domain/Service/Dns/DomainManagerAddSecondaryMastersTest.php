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
use Poweradmin\Domain\Service\Dns\DomainManager;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use TestHelpers\SqliteIntegrationTestCase;

/**
 * A secondary zone is never created with a primaries list that is not valid addresses.
 */
class DomainManagerAddSecondaryMastersTest extends SqliteIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->db->exec("INSERT INTO perm_items (id, name) VALUES (40, 'zone_slave_add')");
        $this->db->exec("INSERT INTO perm_templ (id, name) VALUES (7, 'Caller')");
        $this->db->exec("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (7, 40)");
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (7, 'caller', 7)");
        $_SESSION['userid'] = 7;
    }

    #[RunInSeparateProcess]
    public function testAnInvalidPrimariesListIsRefusedBeforeTheBackendIsCalled(): void
    {
        $backend = $this->dnsBackendStub(false);
        $backend->expects($this->never())->method('createZone');

        $this->assertFalse($this->manager($backend)->addDomain($this->db, 'example.com', 7, 'SLAVE', '192.0.2.1,not-an-ip', 'none'));
    }

    #[RunInSeparateProcess]
    public function testAValidPrimariesListReachesTheBackend(): void
    {
        $backend = $this->dnsBackendStub(false);
        $backend->expects($this->once())->method('createZone')->with('example.com', 'SLAVE', '192.0.2.1,2001:db8::1')->willReturn(false);

        $this->assertFalse($this->manager($backend)->addDomain($this->db, 'example.com', 7, 'SLAVE', '192.0.2.1,2001:db8::1', 'none'));
    }

    private function manager($backend): DomainManager
    {
        return new DomainManager(
            $this->db,
            $this->createMock(ConfigurationManager::class),
            $this->createMock(SOARecordManagerInterface::class),
            $this->createMock(DomainRepositoryInterface::class),
            $backend
        );
    }
}
