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

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Port\DnsBackendProviderInterface;
use Poweradmin\Domain\Port\RecordChangeWriterInterface;
use Poweradmin\Domain\Port\TransactionInterface;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\TemplateRecordLinkRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneTemplateRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneTemplateSyncRepositoryInterface;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Domain\Service\Dns\ZoneTemplateApplier;
use Poweradmin\Domain\Service\Template\ZoneTemplatePlaceholders;
use Psr\Log\NullLogger;

/**
 * On the API backend the local transaction opens only after the last PowerDNS
 * call, so an open SQLite write lock never blocks PowerDNS's own write.
 */
class ZoneTemplateApplierTransactionTest extends TestCase
{
    private TransactionInterface&MockObject $transaction;
    private DnsBackendProviderInterface&MockObject $backend;
    private TemplateRecordLinkRepositoryInterface&MockObject $links;
    private ZoneTemplateRepositoryInterface&MockObject $templates;

    /** @var list<string> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->transaction = $this->createMock(TransactionInterface::class);
        $this->backend = $this->createMock(DnsBackendProviderInterface::class);
        $this->links = $this->createMock(TemplateRecordLinkRepositoryInterface::class);
        $this->templates = $this->createMock(ZoneTemplateRepositoryInterface::class);
        $this->templates->method('listZoneRowIds')->willReturn([7]);
    }

    public function testApiBackendBeginsOnlyAfterTheBackendCallsAndCommits(): void
    {
        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('getDomainNameById')->willReturn('example.com');
        $this->templates->method('getZoneTemplateRecords')->willReturn([
            ['name' => '[ZONE]', 'type' => 'TXT', 'content' => 'hello', 'ttl' => 300, 'prio' => 0],
        ]);
        $this->backend->method('supportsLocalWriteTransaction')->willReturn(false);
        $this->backend->method('recordIdsAreNumeric')->willReturn(false);
        $this->backend->method('recordExists')->willReturn(false);
        $this->backend->expects($this->once())->method('addRecordGetId')->willReturnCallback(function () {
            $this->events[] = 'backend';
            return 'rec-1';
        });
        $this->links->method('listEncodedLinks')->willReturn([]);
        $this->transaction->method('inTransaction')->willReturn(true);
        $this->transaction->expects($this->once())->method('begin')->willReturnCallback(function () {
            $this->events[] = 'begin';
        });
        $this->transaction->expects($this->once())->method('commit')->willReturnCallback(function () {
            $this->events[] = 'commit';
        });
        $this->transaction->expects($this->never())->method('rollBack');
        $this->templates->expects($this->once())->method('assignTemplateToZone')->with(1, 5)
            ->willReturnCallback(function () {
                $this->events[] = 'link';
            });

        $result = $this->applier($domains)->applyTemplate(1, 5, 300, true);

        $this->assertTrue($result->success);
        $this->assertSame(['backend', 'begin', 'link', 'commit'], $this->events);
    }

    public function testApiBackendFailureBeforeTheLocalWritesOpensNothing(): void
    {
        $this->backend->method('supportsLocalWriteTransaction')->willReturn(false);
        $this->backend->method('recordIdsAreNumeric')->willReturn(false);
        $this->links->method('listEncodedLinks')->willThrowException(new \RuntimeException('boom'));
        $this->transaction->method('inTransaction')->willReturn(false);
        $this->transaction->expects($this->never())->method('begin');
        $this->transaction->expects($this->never())->method('rollBack');

        $result = $this->applier()->applyTemplate(1, 5, 300, false);

        $this->assertFalse($result->success);
        $this->assertSame('An error occurred. Please try again.', $result->message);
    }

    public function testApiBackendRollsBackWhenTheLocalLinkWriteFails(): void
    {
        $this->backend->method('supportsLocalWriteTransaction')->willReturn(false);
        $this->backend->method('recordIdsAreNumeric')->willReturn(false);
        $this->links->method('listEncodedLinks')->willReturn([]);
        $this->transaction->method('inTransaction')->willReturn(true);
        $this->transaction->expects($this->once())->method('begin');
        $this->transaction->expects($this->never())->method('commit');
        $this->transaction->expects($this->once())->method('rollBack');
        $this->templates->method('assignTemplateToZone')->willThrowException(new \RuntimeException('locked'));

        $result = $this->applier()->applyTemplate(1, 5, 300, false);

        $this->assertFalse($result->success);
    }

    public function testSqlBackendBeginsAndCommits(): void
    {
        $this->backend->method('supportsLocalWriteTransaction')->willReturn(true);
        $this->backend->method('recordIdsAreNumeric')->willReturn(true);
        $this->links->method('removeLinkedRecords')->willReturn([]);
        $this->transaction->method('inTransaction')->willReturn(true);
        $this->transaction->expects($this->once())->method('begin');
        $this->transaction->expects($this->once())->method('commit');
        $this->transaction->expects($this->never())->method('rollBack');

        $result = $this->applier()->applyTemplate(1, 5, 300, false);

        $this->assertTrue($result->success);
    }

    public function testSqlBackendRollsBackOnFailure(): void
    {
        $this->backend->method('supportsLocalWriteTransaction')->willReturn(true);
        $this->backend->method('recordIdsAreNumeric')->willReturn(true);
        $this->links->method('removeLinkedRecords')->willThrowException(new \RuntimeException('boom'));
        $this->transaction->method('inTransaction')->willReturn(true);
        $this->transaction->expects($this->once())->method('begin');
        $this->transaction->expects($this->never())->method('commit');
        $this->transaction->expects($this->once())->method('rollBack');

        $result = $this->applier()->applyTemplate(1, 5, 300, false);

        $this->assertFalse($result->success);
    }

    public function testSqlBackendRollsBackWhenAnErrorIsThrown(): void
    {
        $this->backend->method('supportsLocalWriteTransaction')->willReturn(true);
        $this->backend->method('recordIdsAreNumeric')->willReturn(true);
        $this->links->method('removeLinkedRecords')->willThrowException(new \TypeError('bad type'));
        $this->transaction->method('inTransaction')->willReturn(true);
        $this->transaction->expects($this->once())->method('begin');
        $this->transaction->expects($this->never())->method('commit');
        $this->transaction->expects($this->once())->method('rollBack');

        $result = $this->applier()->applyTemplate(1, 5, 300, false);

        $this->assertFalse($result->success);
        $this->assertSame('An error occurred. Please try again.', $result->message);
        $this->assertStringNotContainsString('bad type', (string)$result->message);
    }

    public function testTheThrownMessageIsLoggedNotShown(): void
    {
        $this->backend->method('supportsLocalWriteTransaction')->willReturn(true);
        $this->backend->method('recordIdsAreNumeric')->willReturn(true);
        $this->links->method('removeLinkedRecords')->willThrowException(new \PDOException('SQLSTATE[HY000]: /var/www/secret/path'));
        $this->transaction->method('inTransaction')->willReturn(true);

        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with(
            $this->anything(),
            $this->callback(static fn(array $context): bool => str_contains((string)$context['error'], '/var/www/secret/path'))
        );

        $result = $this->applier(null, $logger)->applyTemplate(1, 5, 300, false);

        $this->assertSame('An error occurred. Please try again.', $result->message);
    }

    private function applier(?DomainRepositoryInterface $domains = null, ?\Psr\Log\LoggerInterface $logger = null): ZoneTemplateApplier
    {
        return new ZoneTemplateApplier(
            $this->transaction,
            $this->backend,
            $this->createMock(SOARecordManagerInterface::class),
            $domains ?? $this->createMock(DomainRepositoryInterface::class),
            $this->templates,
            $this->links,
            $this->createMock(ZoneTemplateSyncRepositoryInterface::class),
            $this->createMock(ZoneTemplatePlaceholders::class),
            $this->createMock(RecordChangeWriterInterface::class),
            $logger ?? new NullLogger()
        );
    }
}
