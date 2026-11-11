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

use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Dns\DomainManager;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOCommon;

/**
 * A secondary zone is never created with a primaries list that is not valid addresses.
 */
class DomainManagerAddSecondaryMastersTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SESSION['userid']);
    }

    public function testAnInvalidPrimariesListIsRefusedBeforeAnythingElseRuns(): void
    {
        $_SESSION['userid'] = 7;
        $db = $this->createMock(PDOCommon::class);
        $db->expects($this->never())->method('prepare');

        $this->assertFalse($this->manager($db)->addDomain($db, 'example.com', 7, 'SLAVE', '192.0.2.1,not-an-ip', 'none'));
    }

    private function manager(PDOCommon $db): DomainManager
    {
        return new DomainManager(
            $db,
            $this->createMock(ConfigurationManager::class),
            $this->createMock(SOARecordManagerInterface::class),
            $this->createMock(DomainRepositoryInterface::class)
        );
    }
}
