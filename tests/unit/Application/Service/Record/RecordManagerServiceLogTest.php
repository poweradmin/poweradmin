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

namespace Poweradmin\Tests\Unit\Application\Service\Record;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Service\Web\AuditService;
use Poweradmin\Application\Service\Record\RecordCommentService;
use Poweradmin\Application\Service\Record\RecordManagerService;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\Dns\RecordWriteResult;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Domain\Service\Validation\Refusal;

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
    private function makeService(AuditService $audit, ?RecordWriteResult $write = null, ?RecordCommentService $comments = null): RecordManagerService
    {
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $recordManager = $this->createMock(RecordManagerInterface::class);
        $domainRepository->method('getDomainNameById')->willReturn('example.com');
        $recordManager->method('addRecordGetId')->willReturn($write ?? RecordWriteResult::ok(1));

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturn(false);

        return new RecordManagerService(
            $domainRepository,
            $this->createMock(RecordRepositoryInterface::class),
            $recordManager,
            $comments ?? $this->createMock(RecordCommentService::class),
            $audit,
            $config
        );
    }

    /**
     * Whether the controller passes a full FQDN ('host.example.com', the real
     * flow) or a bare hostname ('host'), the log line must show the FQDN once.
     */
    #[DataProvider('recordNameProvider')]
    public function testRecordNameIsLoggedOnceAsFqdn(string $inputName): void
    {
        $audit = $this->createMock(AuditService::class);
        $audit->expects($this->once())
            ->method('logRecordAdd')
            ->with(1, 'A', 'host.example.com', '192.0.2.1', 3600, 0);

        $service = $this->makeService($audit);
        $service->createRecord(1, $inputName, 'A', '192.0.2.1', 3600, 0, '', 'admin');
    }

    /**
     * A refused write returns the manager's result untouched: no audit line, no
     * comment, and the reason travels with the result rather than the session.
     */
    public function testRefusedWriteIsReturnedWithoutLoggingOrComments(): void
    {
        $audit = $this->createMock(AuditService::class);
        $audit->expects($this->never())->method('logRecordAdd');
        $comments = $this->createMock(RecordCommentService::class);
        $comments->expects($this->never())->method('createCommentForRecord');

        $service = $this->makeService($audit, RecordWriteResult::failure('Invalid IP address', Refusal::INVALID_INPUT), $comments);
        $result = $service->createRecord(1, 'host', 'A', 'not-an-ip', 3600, 0, 'a comment', 'admin');

        $this->assertFalse($result->success);
        $this->assertSame('Invalid IP address', $result->message);
        $this->assertSame(Refusal::INVALID_INPUT, $result->refusal);
        $this->assertNull($result->field);
    }

    public static function recordNameProvider(): array
    {
        return [
            'already an FQDN' => ['host.example.com'],
            'bare hostname' => ['host'],
        ];
    }
}
