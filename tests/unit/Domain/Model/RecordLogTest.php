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

namespace unit\Domain\Model;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\RecordLog;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use ReflectionMethod;

/**
 * Regression coverage for issue #1326: editing a record in the zone UI while the
 * API DNS backend is active produced "Trying to access array offset on null"
 * warnings, because the encoded record ID no longer resolves after the edit.
 */
class RecordLogTest extends TestCase
{
    private function makeRecordLog(array $store): RecordLog
    {
        $db = $this->createMock(PDO::class);
        $recordRepository = $this->createMock(RecordRepositoryInterface::class);

        return new class ($db, $recordRepository, $store) extends RecordLog {
            private array $store;

            public function __construct(PDO $db, RecordRepositoryInterface $recordRepository, array $store)
            {
                parent::__construct($db, $recordRepository);
                $this->store = $store;
            }

            protected function getRecord(int|string $rid): ?array
            {
                return $this->store[$rid] ?? null;
            }
        };
    }

    private function buildMessage(RecordLog $log): string
    {
        $method = new ReflectionMethod(RecordLog::class, 'buildLogMessage');

        return (string)$method->invoke($log);
    }

    public function testWriteDoesNotEmitWarningsWhenAfterRecordMissing(): void
    {
        $prior = [
            'type' => 'A',
            'name' => 'host.example.com',
            'content' => '192.0.2.1',
            'ttl' => 3600,
            'prio' => 0,
        ];

        // Simulate the API backend: the prior record resolves, the post-edit
        // lookup by the stale encoded ID returns nothing.
        $log = $this->makeRecordLog(['old-id' => $prior]);
        $log->logPrior('old-id', 5, '');
        $log->logAfter('new-id');

        $errors = [];
        set_error_handler(function (int $errno, string $errstr) use (&$errors): bool {
            $errors[] = $errstr;
            return true;
        });

        try {
            $message = $this->buildMessage($log);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $errors, 'write() must not emit warnings when the after-record is null');
        $this->assertStringContainsString('old_record_type:A', $message);
        $this->assertStringContainsString('old_content:192.0.2.1', $message);
    }

    public function testHasChangedDoesNotWarnWhenNamesAreNull(): void
    {
        // With the API backend the prior record's encoded id no longer resolves,
        // so record_prior carries no name; a row can likewise reach hasChanged()
        // without a name. Neither must trigger a strtolower(null) deprecation.
        $log = $this->makeRecordLog([]);
        $log->logPrior('missing-id', 5, '');

        $record = ['rid' => 'missing-id', 'zid' => 5, 'type' => 'A', 'content' => '192.0.2.1'];

        $errors = [];
        set_error_handler(function (int $errno, string $errstr) use (&$errors): bool {
            $errors[] = $errstr;
            return true;
        });

        try {
            $log->hasChanged($record);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $errors, 'hasChanged() must not emit warnings when either name is null');
    }

    public function testLogAfterFallsBackToSubmittedRecordData(): void
    {
        $prior = [
            'type' => 'A',
            'name' => 'host.example.com',
            'content' => '192.0.2.1',
            'ttl' => 3600,
            'prio' => 0,
        ];

        $submitted = [
            'rid' => 'old-id',
            'zid' => 5,
            'type' => 'A',
            'name' => 'host.example.com',
            'content' => '192.0.2.99',
            'ttl' => 7200,
            'prio' => 0,
        ];

        $log = $this->makeRecordLog(['old-id' => $prior]);
        $log->logPrior('old-id', 5, '');
        $log->logAfter('new-id', $submitted);

        $message = $this->buildMessage($log);

        // The "after" side of the audit entry reflects the submitted values.
        $this->assertStringContainsString('content:192.0.2.99', $message);
        $this->assertStringContainsString('ttl:7200', $message);
    }
}
