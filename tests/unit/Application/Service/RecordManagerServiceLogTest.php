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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\RecordCommentService;
use Poweradmin\Application\Service\RecordCommentSyncService;
use Poweradmin\Application\Service\RecordManagerService;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\LegacyLogger;

/**
 * Issue #1332: the zone log event for a record add showed the record name
 * doubled (e.g. "record:host.example.com.example.com") because the log line
 * appended the zone name to a record name that the controller had already
 * normalized to a full FQDN. The fix logs the FQDN exactly once.
 *
 * @see https://github.com/poweradmin/poweradmin/issues/1332
 */
class RecordManagerServiceLogTest extends TestCase
{
    private function makeService(LegacyLogger $logger): RecordManagerService
    {
        $dnsRecord = $this->createMock(DnsRecord::class);
        $dnsRecord->method('getDomainNameById')->willReturn('example.com');
        $dnsRecord->method('addRecordGetId')->willReturn(1);

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturn(false);

        return new RecordManagerService(
            $this->createMock(PDO::class),
            $dnsRecord,
            $this->createMock(RecordCommentService::class),
            $this->createMock(RecordCommentSyncService::class),
            $logger,
            $config,
            null
        );
    }

    /**
     * Whether the controller passes a full FQDN ('host.example.com', the real
     * flow) or a bare hostname ('host'), the log line must show the FQDN once.
     */
    #[DataProvider('recordNameProvider')]
    public function testRecordNameIsLoggedOnceAsFqdn(string $inputName): void
    {
        $logger = $this->createMock(LegacyLogger::class);
        $logger->expects($this->once())
            ->method('logInfo')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('record:host.example.com content:'),
                    $this->logicalNot($this->stringContains('example.com.example.com'))
                ),
                1
            );

        $service = $this->makeService($logger);
        $service->createRecord(1, $inputName, 'A', '192.0.2.1', 3600, 0, '', 'admin', '127.0.0.1');
    }

    public static function recordNameProvider(): array
    {
        return [
            'already an FQDN' => ['host.example.com'],
            'bare hostname' => ['host'],
        ];
    }
}
