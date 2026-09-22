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

namespace Poweradmin\Tests\Unit\Application\Service\Backend;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\Backend\RepositoryFactory;
use Poweradmin\Domain\Port\DnsBackendProviderInterface;
use Poweradmin\Infrastructure\Repository\ApiRecordSearch;
use Poweradmin\Infrastructure\Repository\ApiZoneSearch;
use Poweradmin\Infrastructure\Repository\RecordSearch;
use Poweradmin\Infrastructure\Repository\ZoneSearch;
use TestHelpers\FakeConfiguration;

/**
 * The search implementation follows the backend the same way the repositories do.
 */
class RepositoryFactorySearchTest extends TestCase
{
    private function factory(bool $api): RepositoryFactory
    {
        $backend = $this->createMock(DnsBackendProviderInterface::class);
        $backend->method('isApiBackend')->willReturn($api);

        return new RepositoryFactory($this->createMock(PDO::class), new FakeConfiguration(['database' => ['type' => 'mysql']]), $backend);
    }

    public function testSqlBackendGetsTheSqlSearches(): void
    {
        $this->assertInstanceOf(ZoneSearch::class, $this->factory(false)->createZoneSearch());
        $this->assertInstanceOf(RecordSearch::class, $this->factory(false)->createRecordSearch());
    }

    public function testApiBackendGetsTheApiSearches(): void
    {
        $this->assertInstanceOf(ApiZoneSearch::class, $this->factory(true)->createZoneSearch());
        $this->assertInstanceOf(ApiRecordSearch::class, $this->factory(true)->createRecordSearch());
    }
}
