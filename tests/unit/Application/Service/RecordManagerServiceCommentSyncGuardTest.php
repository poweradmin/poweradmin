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

use PDOStatement;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\RecordCommentService;
use Poweradmin\Application\Service\RecordCommentSyncService;
use Poweradmin\Application\Service\RecordManagerService;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOCommon;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use ReflectionMethod;

/**
 * The related A/PTR zone is derived from the record content, so the synced comment
 * must not be written there unless the user may edit that zone.
 */
class RecordManagerServiceCommentSyncGuardTest extends TestCase
{
    public function testDoesNotWriteIntoARelatedZoneTheUserMayNotEdit(): void
    {
        $dnsRecord = $this->createMock(DnsRecord::class);
        $dnsRecord->method('getBestMatchingZoneIdFromName')->willReturn(5);
        $dnsRecord->method('getDomainIdByName')->willReturnCallback(fn(string $name) => $name === 'example.com' ? 1 : null);
        $dnsRecord->method('currentUserMayEditZoneContent')->willReturn(false);

        $comments = $this->createMock(RecordCommentService::class);
        $comments->expects($this->never())->method('createCommentForRecord');

        // A matching record exists in the related zone, so only the guard keeps it untouched.
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('fetchAll')->willReturn([['id' => 50]]);
        $db = $this->createMock(PDOCommon::class);
        $db->method('prepare')->willReturn($statement);

        $service = new RecordManagerService(
            $db,
            $dnsRecord,
            $comments,
            $this->createMock(RecordCommentSyncService::class),
            $this->createMock(LegacyLogger::class),
            $this->createMock(ConfigurationManager::class)
        );

        $sync = new ReflectionMethod($service, 'handleSyncedComments');
        $sync->invoke($service, 1, 'host.example.com', 'A', '192.0.2.1', 'rack 4', 'admin', 'host.example.com');
        $sync->invoke($service, 5, '1.2.0.192.in-addr.arpa', 'PTR', 'host.example.com', 'rack 4', 'admin', '1.2.0.192.in-addr.arpa');
    }
}
