<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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

namespace Poweradmin\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use PDO;
use PDOStatement;
use Poweradmin\Domain\Repository\RecordRepository;
use Poweradmin\Infrastructure\Database\PDOCommon;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

class RecordRepositoryApexSortingTest extends TestCase
{
    private PDOCommon&MockObject $db;
    private ConfigurationManager&MockObject $config;
    private RecordRepository $repository;

    protected function setUp(): void
    {
        // Create mock PDO
        $this->db = $this->createMock(PDOCommon::class);

        // Create mock config
        $this->config = $this->createMock(ConfigurationManager::class);
        $this->config->method('get')
            ->willReturnCallback(function ($section, $key, $default = null) {
                if ($section === 'database' && $key === 'pdns_name') {
                    return null; // No prefix
                }
                if ($section === 'database' && $key === 'type') {
                    return 'mysql';
                }
                return $default;
            });

        $this->repository = new RecordRepository($this->db, $this->config);
    }

    public function testApexRecordSortingInQuery(): void
    {
        $domainId = 1;
        $expectedQuery = null;
        $expectedParams = null;

        // Create mock statement
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')
            ->willReturnCallback(function ($params) use (&$expectedParams) {
                $expectedParams = $params;
                return true;
            });
        $stmt->method('fetchAll')->willReturn([]);

        // Capture the prepared query
        $this->db->method('prepare')
            ->willReturnCallback(function ($query) use (&$expectedQuery, $stmt) {
                $expectedQuery = $query;
                return $stmt;
            });

        // Call the method with name sorting ASC
        $this->repository->getRecordsFromDomainId(
            'mysql',
            $domainId,
            0,
            100,
            'name',
            'ASC',
            false
        );

        // Verify the query contains apex record sorting
        $this->assertStringContainsString("records.type = 'SOA' DESC", $expectedQuery);
        $this->assertStringContainsString("records.type = 'NS' DESC", $expectedQuery);
        $this->assertStringContainsString("records.name = (SELECT name FROM domains WHERE id = :domain_id_apex) DESC", $expectedQuery);

        // Verify parameters include both domain_id and domain_id_apex
        $this->assertArrayHasKey(':domain_id', $expectedParams);
        $this->assertArrayHasKey(':domain_id_apex', $expectedParams);
        $this->assertEquals($domainId, $expectedParams[':domain_id']);
        $this->assertEquals($domainId, $expectedParams[':domain_id_apex']);
    }

    public function testNoApexSortingWhenSortingByOtherColumns(): void
    {
        $domainId = 1;
        $expectedQuery = null;

        // Create mock statement
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchAll')->willReturn([]);

        // Capture the prepared query
        $this->db->method('prepare')
            ->willReturnCallback(function ($query) use (&$expectedQuery, $stmt) {
                $expectedQuery = $query;
                return $stmt;
            });

        // Call the method with type sorting
        $this->repository->getRecordsFromDomainId(
            'mysql',
            $domainId,
            0,
            100,
            'type',
            'ASC',
            false
        );

        // Verify the query does NOT contain apex record sorting
        $this->assertStringNotContainsString("records.name = (SELECT name FROM domains WHERE id = :domain_id_apex)", $expectedQuery);
    }

    public function testNoApexSortingWhenSortingNameDescending(): void
    {
        $domainId = 1;
        $expectedQuery = null;
        $expectedParams = null;

        // Create mock statement
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('execute')
            ->willReturnCallback(function ($params) use (&$expectedParams) {
                $expectedParams = $params;
                return true;
            });
        $stmt->method('fetchAll')->willReturn([]);

        // Capture the prepared query
        $this->db->method('prepare')
            ->willReturnCallback(function ($query) use (&$expectedQuery, $stmt) {
                $expectedQuery = $query;
                return $stmt;
            });

        // Call the method with name sorting DESC
        $this->repository->getRecordsFromDomainId(
            'mysql',
            $domainId,
            0,
            100,
            'name',
            'DESC',
            false
        );

        // Verify the query does NOT contain apex record sorting for DESC
        $this->assertStringNotContainsString("records.name = (SELECT name FROM domains WHERE id = :domain_id_apex)", $expectedQuery);

        // Verify only domain_id parameter is set
        $this->assertArrayHasKey(':domain_id', $expectedParams);
        $this->assertArrayNotHasKey(':domain_id_apex', $expectedParams);
    }
}
