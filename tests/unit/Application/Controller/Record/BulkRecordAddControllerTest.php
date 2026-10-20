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

namespace Poweradmin\Tests\Unit\Application\Controller\Record;

use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use Poweradmin\Application\Controller\Record\BulkRecordAddController;
use Poweradmin\Application\Controller\RequestHalted;
use Poweradmin\Application\Service\Record\RecordManagerService;
use Poweradmin\Domain\Port\TransactionInterface;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\Dns\RecordWriteResult;
use Poweradmin\Domain\Service\Dns\ReverseTtlResolver;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Domain\Service\User\UserPreferenceService;
use Poweradmin\Domain\Service\Validation\Refusal;
use Poweradmin\Infrastructure\Logger\RecordChangeLogger;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;
use RuntimeException;

/**
 * The bulk add form: how many lines one submission may carry, what the user gets
 * back when it carries too many, and how the list is written as one batch.
 */
#[CoversClass(BulkRecordAddController::class)]
final class BulkRecordAddControllerTest extends SeamControllerTestCase
{
    private const ZONE_ID = 12;

    /** @var RecordManagerService&MockObject */
    private RecordManagerService $records;

    /** @var ReverseTtlResolver&MockObject */
    private ReverseTtlResolver $ttl;

    /** @var list<string> The batch's transaction, serial and finalize calls, in order */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();

        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('hasPermission')->willReturn(true);
        $permissions->method('getEditPermissionLevel')->willReturn('all');
        $permissions->method('getEditPermissionLevelForZone')->willReturn('all');
        $permissions->method('getChangeRequestPermissionLevelForZone')->willReturn('none');
        $permissions->method('userOwnsZone')->willReturn(true);

        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('getDomainType')->willReturn('MASTER');
        $domains->method('getDomainNameById')->willReturn('example.com');

        $this->ttl = $this->createMock(ReverseTtlResolver::class);
        $this->ttl->method('resolveTtlForType')->willReturn(3600);
        $this->ttl->method('resolveTtlsForTypes')->willReturnCallback(
            static fn(array $types): array => array_fill_keys(array_map('strtoupper', $types), 3600)
        );

        $transaction = $this->createMock(TransactionInterface::class);
        $open = false;
        $transaction->method('begin')->willReturnCallback(function () use (&$open): void {
            $open = true;
            $this->events[] = 'begin';
        });
        $transaction->method('commit')->willReturnCallback(function () use (&$open): void {
            $open = false;
            $this->events[] = 'commit';
        });
        $transaction->method('rollBack')->willReturnCallback(function () use (&$open): void {
            $open = false;
            $this->events[] = 'rollBack';
        });
        $transaction->method('inTransaction')->willReturnCallback(static function () use (&$open): bool {
            return $open;
        });

        $soa = $this->createMock(SOARecordManagerInterface::class);
        $soa->method('updateSOASerial')->willReturnCallback(function (int $zoneId): bool {
            $this->events[] = "serial:$zoneId";
            return true;
        });

        $recordManager = $this->createMock(RecordManagerInterface::class);
        $recordManager->method('finalizeZone')->willReturnCallback(function (int $zoneId, bool $bumpSerial = true): void {
            $this->events[] = "finalize:$zoneId:" . ($bumpSerial ? 'bump' : 'nobump');
        });

        $changeLog = $this->createMock(RecordChangeLogger::class);
        $changeLog->method('changeCommentRequired')->willReturn(false);
        $changeLog->method('withChangeset')->willReturnCallback(static fn(?int $zoneId, ?string $comment, callable $work): mixed => $work());

        $preferences = $this->createMock(UserPreferenceService::class);
        $preferences->method('getDisplayHostnameOnly')->willReturn(false);

        $this->records = $this->createMock(RecordManagerService::class);

        $this->factory->method('permissionService')->willReturn($permissions);
        $this->factory->method('domainRepository')->willReturn($domains);
        $this->factory->method('reverseTtlResolver')->willReturn($this->ttl);
        $this->factory->method('transaction')->willReturn($transaction);
        $this->factory->method('soaRecordManager')->willReturn($soa);
        $this->factory->method('recordManager')->willReturn($recordManager);
        $this->factory->method('recordChangeLog')->willReturn($changeLog);
        $this->factory->method('recordManagerService')->willReturn($this->records);
        $this->factory->method('userPreferenceService')->willReturn($preferences);
    }

    /** @param array<string, array<string, mixed>> $config */
    private function submit(string $records, array $config = []): void
    {
        $this->query(['id' => (string)self::ZONE_ID]);
        $this->post(['records' => $records]);
        $controller = new BulkRecordAddController($this->requestData(), true, $this->environment($this->configure($config)));

        try {
            $controller->run();
        } catch (RequestHalted) {
            // A successful add redirects to the zone editor
        }
    }

    private static function lines(int $count): string
    {
        return implode("\n", array_map(static fn(int $i): string => "h$i,A,192.0.2." . ($i % 250 + 1), range(1, $count)));
    }

    public function testAListAboveTheCapIsRefusedWholeAndHandedBackForSplitting(): void
    {
        $this->records->expects($this->never())->method('createRecord');
        $text = self::lines(BulkRecordAddController::MAX_LINES + 1);

        $this->submit($text);

        $this->assertSame([['error', sprintf('At most %d records per bulk add; split the list.', BulkRecordAddController::MAX_LINES)]], $this->messagesFor('bulk_record_add'));
        $this->assertSame('bulk_record_add.html', $this->renderedTemplate());
        $this->assertSame($text, $this->renderedParams()['records_text']);
        $this->assertSame([], $this->renderedParams()['failed_records']);
    }

    public function testBlankLinesDoNotCountTowardsTheCap(): void
    {
        $this->records->expects($this->exactly(BulkRecordAddController::MAX_LINES))->method('createRecord')->willReturn(RecordWriteResult::ok(1));

        $this->submit(self::lines(BulkRecordAddController::MAX_LINES) . "\n\n   \n\n");

        $this->assertSame([['success', sprintf('%d record(s) have been added successfully.', BulkRecordAddController::MAX_LINES)]], $this->messagesFor('edit'));
    }

    public function testTheListIsWrittenInOneTransactionWithOneSerialBumpAndOneFinalize(): void
    {
        $this->records->expects($this->exactly(3))->method('createRecord')
            ->with($this->anything(), $this->anything(), $this->anything(), $this->anything(), 3600, $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), self::USER_ID, false)
            ->willReturnCallback(function (): RecordWriteResult {
                $this->events[] = 'add';
                return RecordWriteResult::ok(1);
            });
        $this->ttl->expects($this->never())->method('resolveTtlForType');
        $this->ttl->expects($this->once())->method('resolveTtlsForTypes');

        $this->submit("www,A,192.0.2.1\nmail,A,192.0.2.2\n@,MX,mail.example.com,10");

        $this->assertSame(['begin', 'add', 'add', 'add', 'serial:12', 'commit', 'finalize:12:nobump'], $this->events);
        $this->assertSame([['success', '3 record(s) have been added successfully.']], $this->messagesFor('edit'));
    }

    public function testRefusedLinesAreHandedBackWhileTheValidOnesAreCommitted(): void
    {
        $this->records->method('createRecord')->willReturnCallback(
            static fn(int $zoneId, string $name): RecordWriteResult => str_starts_with($name, 'bad')
                ? RecordWriteResult::failure('Invalid content.', Refusal::INVALID_INPUT)
                : RecordWriteResult::ok(1)
        );

        $this->submit("www,A,192.0.2.1\nbad,A,192.0.2.2\nnope,BOGUS,x");

        $this->assertSame(['begin', 'serial:12', 'commit', 'finalize:12:nobump'], $this->events);
        $this->assertSame(['bad,A,192.0.2.2 - Invalid content.', 'nope,BOGUS,x - Invalid record type.'], $this->renderedParams()['failed_records']);
        $this->assertSame([['warning', 'Some record(s) could not be added.']], $this->messagesFor('bulk_record_add'));
    }

    public function testAWriteThatThrowsRollsBackTheWholeListAndHandsItBack(): void
    {
        $this->records->method('createRecord')->willReturnCallback(
            static fn(int $zoneId, string $name): RecordWriteResult => str_starts_with($name, 'boom')
                ? throw new PDOException('deadlock')
                : RecordWriteResult::ok(1)
        );
        $text = "www,A,192.0.2.1\nboom,A,192.0.2.2\nmail,A,192.0.2.3";

        $this->submit($text);

        $this->assertSame(['begin', 'rollBack'], $this->events);
        $this->assertSame([['error', 'No records were added: boom,A,192.0.2.2 - deadlock']], $this->messagesFor('bulk_record_add'));
        $this->assertSame($text, $this->renderedParams()['records_text']);
    }

    public function testNothingAddedMeansNoSerialBumpAndNoFinalize(): void
    {
        $this->records->method('createRecord')->willReturn(RecordWriteResult::failure('Duplicate.', Refusal::CONFLICT));

        $this->submit("www,A,192.0.2.1");

        $this->assertSame(['begin', 'commit'], $this->events);
    }

    public function testTheApiBackendHasNoTransactionAndKeepsGoingPastAThrowingWrite(): void
    {
        $this->records->method('createRecord')->willReturnCallback(
            static fn(int $zoneId, string $name): RecordWriteResult => str_starts_with($name, 'boom')
                ? throw new RuntimeException('PowerDNS said no')
                : RecordWriteResult::ok(1)
        );

        $this->submit("www,A,192.0.2.1\nboom,A,192.0.2.2\nmail,A,192.0.2.3", ['dns' => ['backend' => 'api']]);

        $this->assertSame(['serial:12', 'finalize:12:nobump'], $this->events);
        $this->assertSame(['boom,A,192.0.2.2 - PowerDNS said no'], $this->renderedParams()['failed_records']);
    }
}
