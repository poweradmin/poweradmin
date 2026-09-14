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
use Poweradmin\Application\Service\AuditService;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Poweradmin\Infrastructure\Utility\IpAddressRetriever;

/**
 * The audit line shape is what the log views and grep filters read, so each
 * event keeps its "client_ip:.. user:.. operation:.." fields in a fixed order.
 */
class AuditServiceTest extends TestCase
{
    /** @var list<array{0: string, 1: string, 2: int|null}> method, message, zone or group id */
    private array $lines = [];

    private function makeService(): AuditService
    {
        $logger = $this->createMock(LegacyLogger::class);
        foreach (['logInfo', 'logWarn', 'logNotice', 'logGroupInfo', 'logApiInfo'] as $method) {
            $logger->method($method)->willReturnCallback(function (string $message, ?int $id = null) use ($method): void {
                $this->lines[] = [$method, $message, $id];
            });
        }

        $ip = $this->createMock(IpAddressRetriever::class);
        $ip->method('getClientIp')->willReturn('192.0.2.10');
        $user = $this->createMock(UserContextService::class);
        $user->method('getLoggedInUsername')->willReturn('alice');

        return new AuditService($this->createMock(PDO::class), $logger, $ip, $user);
    }

    public function testZoneAddCarriesOnlyTheGivenFields(): void
    {
        $service = $this->makeService();
        $service->logZoneAdd(7, 'example.com', 'MASTER', 'none');
        $service->logZoneAdd(8, 'example.net', 'SLAVE', null, '192.0.2.1');

        $this->assertSame(
            ['logInfo', 'client_ip:192.0.2.10 user:alice operation:add_zone zone:example.com zone_type:MASTER zone_template:none', 7],
            $this->lines[0]
        );
        $this->assertSame(
            ['logInfo', 'client_ip:192.0.2.10 user:alice operation:add_zone zone:example.net zone_type:SLAVE zone_master:192.0.2.1', 8],
            $this->lines[1]
        );
    }

    public function testRecordDeleteLeavesPriorityOutWhenUnknown(): void
    {
        $service = $this->makeService();
        $service->logRecordDelete(3, 'MX', 'example.com', 'mail.example.com', 3600, 10);
        $service->logRecordDelete(3, 'A', 'www.example.com', '192.0.2.5', '3600', null);

        $this->assertSame(
            'client_ip:192.0.2.10 user:alice operation:delete_record record_type:MX record:example.com content:mail.example.com ttl:3600 priority:10',
            $this->lines[0][1]
        );
        $this->assertSame(
            'client_ip:192.0.2.10 user:alice operation:delete_record record_type:A record:www.example.com content:192.0.2.5 ttl:3600',
            $this->lines[1][1]
        );
    }

    public function testRecordEditListsOldAndNewValues(): void
    {
        $this->makeService()->logRecordEdit(
            4,
            ['type' => 'A', 'name' => 'a.example.com', 'content' => '192.0.2.1', 'ttl' => 300, 'prio' => 0],
            ['type' => 'A', 'name' => 'a.example.com', 'content' => '192.0.2.2', 'ttl' => 600]
        );

        $this->assertSame(
            'client_ip:192.0.2.10 user:alice operation:edit_record'
            . ' old_record_type:A old_record:a.example.com old_content:192.0.2.1 old_ttl:300 old_priority:0'
            . ' record_type:A record:a.example.com content:192.0.2.2 ttl:600 priority:',
            $this->lines[0][1]
        );
        $this->assertSame(4, $this->lines[0][2]);
    }

    public function testTemplateNamesAreLoggedAsSingleTokens(): void
    {
        $service = $this->makeService();
        $service->logZoneTemplateAdd('Web hosting');
        $service->logApiZoneTemplateRecordAdd(2, 9, 'mail server', 'MX');

        $this->assertSame('client_ip:192.0.2.10 user:alice operation:add_zone_template template_name:Web_hosting', $this->lines[0][1]);
        $this->assertNull($this->lines[0][2]);
        $this->assertSame(
            'client_ip:192.0.2.10 user:alice operation:api_add_zone_template_record template_id:2 record_id:9 record_name:mail_server record_type:MX',
            $this->lines[1][1]
        );
    }

    public function testActorFallsBackToUnknownWithoutASession(): void
    {
        $logger = $this->createMock(LegacyLogger::class);
        $logger->expects($this->once())->method('logWarn')
            ->with('client_ip:192.0.2.10 user:unknown operation:access_denied permission:zone_master_add uri:/zones/add/master', null);
        $ip = $this->createMock(IpAddressRetriever::class);
        $ip->method('getClientIp')->willReturn('192.0.2.10');
        $user = $this->createMock(UserContextService::class);
        $user->method('getLoggedInUsername')->willReturn(null);

        (new AuditService($this->createMock(PDO::class), $logger, $ip, $user))
            ->logAccessDenied('zone_master_add', '/zones/add/master');
    }
}
