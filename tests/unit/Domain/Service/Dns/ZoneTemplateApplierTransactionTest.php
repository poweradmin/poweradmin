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
 * The applier's local transaction only exists when the backend joins it: on the
 * API backend an open SQLite write lock would block PowerDNS's own write.
 */
class ZoneTemplateApplierTransactionTest extends TestCase
{
    private TransactionInterface&MockObject $transaction;
    private DnsBackendProviderInterface&MockObject $backend;
    private TemplateRecordLinkRepositoryInterface&MockObject $links;
    private ZoneTemplateRepositoryInterface&MockObject $templates;

    protected function setUp(): void
    {
        $this->transaction = $this->createMock(TransactionInterface::class);
        $this->backend = $this->createMock(DnsBackendProviderInterface::class);
        $this->links = $this->createMock(TemplateRecordLinkRepositoryInterface::class);
        $this->templates = $this->createMock(ZoneTemplateRepositoryInterface::class);
        $this->templates->method('listZoneRowIds')->willReturn([7]);
    }

    public function testApiBackendNeverOpensATransactionAndStillApplies(): void
    {
        $this->backend->method('supportsLocalWriteTransaction')->willReturn(false);
        $this->backend->method('recordIdsAreNumeric')->willReturn(false);
        $this->links->method('listEncodedLinks')->willReturn([]);
        $this->transaction->method('inTransaction')->willReturn(false);
        $this->transaction->expects($this->never())->method('begin');
        $this->transaction->expects($this->never())->method('commit');
        $this->transaction->expects($this->never())->method('rollBack');
        $this->templates->expects($this->once())->method('assignTemplateToZone')->with(1, 5);

        $result = $this->applier()->applyTemplate(1, 5, 300, false);

        $this->assertTrue($result->success);
    }

    public function testApiBackendFailureRollsNothingBack(): void
    {
        $this->backend->method('supportsLocalWriteTransaction')->willReturn(false);
        $this->backend->method('recordIdsAreNumeric')->willReturn(false);
        $this->links->method('listEncodedLinks')->willThrowException(new \RuntimeException('boom'));
        $this->transaction->method('inTransaction')->willReturn(false);
        $this->transaction->expects($this->never())->method('begin');
        $this->transaction->expects($this->never())->method('rollBack');

        $result = $this->applier()->applyTemplate(1, 5, 300, false);

        $this->assertFalse($result->success);
        $this->assertSame('Failed to update zone records: boom', $result->message);
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

    private function applier(): ZoneTemplateApplier
    {
        return new ZoneTemplateApplier(
            $this->transaction,
            $this->backend,
            $this->createMock(SOARecordManagerInterface::class),
            $this->createMock(DomainRepositoryInterface::class),
            $this->templates,
            $this->links,
            $this->createMock(ZoneTemplateSyncRepositoryInterface::class),
            $this->createMock(ZoneTemplatePlaceholders::class),
            $this->createMock(RecordChangeWriterInterface::class),
            new NullLogger()
        );
    }
}
