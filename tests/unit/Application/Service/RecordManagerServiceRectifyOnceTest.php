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

namespace Poweradmin\Tests\Unit\Application\Service;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\RecordCommentService;
use Poweradmin\Application\Service\RecordManagerService;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\LegacyLogger;

/**
 * RecordManager::addRecordGetId() rectifies a DNSSEC zone after the write, so the
 * service adding the record must not rectify it a second time.
 */
class RecordManagerServiceRectifyOnceTest extends TestCase
{
    public function testAnAddedRecordLeavesTheRectifyToTheRecordManager(): void
    {
        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('getDomainNameById')->willReturn('example.com');
        $records = $this->createMock(RecordManagerInterface::class);
        $records->expects($this->once())->method('addRecordGetId')->willReturn(1);

        $asked = [];
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(function (string $group, string $key) use (&$asked): bool {
            $asked[] = "$group.$key";
            return true;
        });

        $logger = $this->createMock(LegacyLogger::class);
        $logger->expects($this->once())->method('logInfo');

        $service = new RecordManagerService($this->createMock(PDO::class), $domains, $records, $this->createMock(RecordCommentService::class), $logger, $config, null);

        $this->assertTrue($service->createRecord(1, 'www.example.com', 'A', '192.0.2.1', 3600, 0, '', 'admin', '127.0.0.1'));
        $this->assertNotContains('dnssec.enabled', $asked);
    }
}
