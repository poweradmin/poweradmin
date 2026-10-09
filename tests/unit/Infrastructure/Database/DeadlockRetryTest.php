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

namespace Poweradmin\Tests\Unit\Infrastructure\Database;

use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Database\DeadlockRetry;
use RuntimeException;

class DeadlockRetryTest extends TestCase
{
    private static function pdo(string $message, ?array $errorInfo): PDOException
    {
        $e = new PDOException($message);
        $e->errorInfo = $errorInfo;

        return $e;
    }

    /** @return array<string, array{\Throwable, bool}> */
    public static function errors(): array
    {
        return [
            'mysql deadlock' => [self::pdo('Deadlock found', ['40001', 1213, 'Deadlock found']), true],
            'mysql lock wait timeout is not replayed' => [self::pdo('Lock wait timeout exceeded', ['HY000', 1205, 'Lock wait timeout exceeded']), false],
            'postgres deadlock' => [self::pdo('deadlock detected', ['40P01', 7, 'deadlock detected']), true],
            'postgres serialization failure' => [self::pdo('could not serialize access', ['40001', 7, 'could not serialize access']), true],
            'sqlite busy' => [self::pdo('SQLSTATE[HY000]: General error: 5 database is locked', ['HY000', 5, 'database is locked']), true],
            'sqlite locked table' => [self::pdo('SQLSTATE[HY000]: General error: 6 database table is locked', ['HY000', 6, 'database table is locked']), true],
            'duplicate key' => [self::pdo('Duplicate entry', ['23000', 1062, 'Duplicate entry']), false],
            'syntax error' => [self::pdo('Syntax error', ['42000', 1064, 'Syntax error']), false],
            'postgres small driver code is not sqlite busy' => [self::pdo('relation does not exist', ['42P01', 5, 'relation does not exist']), false],
            'no error info' => [self::pdo('boom', null), false],
            'not a pdo exception' => [new RuntimeException('Deadlock found'), false],
        ];
    }

    #[DataProvider('errors')]
    public function testClassifiesLockConflicts(\Throwable $error, bool $retryable): void
    {
        $this->assertSame($retryable, DeadlockRetry::isRetryable($error));
    }

    public function testRetriesUntilTheAttemptSucceeds(): void
    {
        $calls = 0;
        $pauses = [];

        $result = DeadlockRetry::run(
            function () use (&$calls): string {
                if (++$calls < 3) {
                    throw self::pdo('Deadlock found', ['40001', 1213, 'Deadlock found']);
                }

                return 'done';
            },
            function (int $try) use (&$pauses): void {
                $pauses[] = $try;
            }
        );

        $this->assertSame('done', $result);
        $this->assertSame(3, $calls);
        $this->assertSame([1, 2], $pauses);
    }

    public function testGivesUpAfterTheLastAttemptAndRethrowsTheDeadlock(): void
    {
        $calls = 0;
        $error = self::pdo('Deadlock found', ['40001', 1213, 'Deadlock found']);

        $thrown = $this->thrownBy(function () use (&$calls, $error): string {
            $calls++;
            throw $error;
        }, 3);

        $this->assertSame($error, $thrown);
        $this->assertSame(3, $calls);
    }

    public function testOtherErrorsAreNotRetried(): void
    {
        $calls = 0;

        $thrown = $this->thrownBy(function () use (&$calls): string {
            $calls++;
            throw self::pdo('Duplicate entry', ['23000', 1062, 'Duplicate entry']);
        });

        $this->assertInstanceOf(PDOException::class, $thrown);
        $this->assertSame(1, $calls);
    }

    private function thrownBy(callable $attempt, int $maxAttempts = DeadlockRetry::MAX_ATTEMPTS): ?\Throwable
    {
        try {
            DeadlockRetry::run($attempt, static function (int $try): void {
            }, $maxAttempts);
        } catch (\Throwable $e) {
            return $e;
        }

        return null;
    }
}
