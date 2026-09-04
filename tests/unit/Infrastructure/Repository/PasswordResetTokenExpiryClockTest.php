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
 */

namespace Poweradmin\Tests\Unit\Infrastructure\Repository;

use PDO;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Repository\DbPasswordResetTokenRepository;

/**
 * expires_at is written with PHP's clock, so the validity check must use PHP's
 * clock too. SQLite's datetime('now') is UTC, so under a PHP timezone west of
 * UTC the old DbCompat::now() comparison marked freshly-created tokens as
 * already expired (audit H11).
 */
class PasswordResetTokenExpiryClockTest extends TestCase
{
    private function makeRepository(PDO $db): DbPasswordResetTokenRepository
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            fn ($group, $key, $default = null) => ($group === 'database' && $key === 'type') ? 'sqlite' : $default
        );

        return new DbPasswordResetTokenRepository($db, $config);
    }

    private function makeDb(): PDO
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec("CREATE TABLE password_reset_tokens (
            id INTEGER PRIMARY KEY,
            email TEXT NOT NULL,
            token TEXT NOT NULL,
            created_at TEXT,
            expires_at TEXT,
            ip_address TEXT,
            used INTEGER NOT NULL DEFAULT 0
        )");
        return $db;
    }

    #[RunInSeparateProcess]
    public function testFreshTokenIsValidUnderWestOfUtcTimezone(): void
    {
        date_default_timezone_set('America/Los_Angeles');

        $db = $this->makeDb();
        $repo = $this->makeRepository($db);

        $repo->create([
            'email' => 'user@example.com',
            'token' => 'fresh-token',
            'expires_at' => date('Y-m-d H:i:s', time() + 3600),
            'ip_address' => '203.0.113.1',
        ]);

        // The repository stores and looks up tokens by their hash (as the service does).
        $found = $repo->findByToken(DbPasswordResetTokenRepository::hashToken('fresh-token'));

        $this->assertNotNull($found, 'A token expiring an hour from now must be valid regardless of the DB clock timezone.');
        $this->assertSame('user@example.com', $found['email']);
    }

    #[RunInSeparateProcess]
    public function testExpiredTokenIsRejectedUnderWestOfUtcTimezone(): void
    {
        date_default_timezone_set('America/Los_Angeles');

        $db = $this->makeDb();
        $repo = $this->makeRepository($db);

        $repo->create([
            'email' => 'user@example.com',
            'token' => 'stale-token',
            'expires_at' => date('Y-m-d H:i:s', time() - 60),
            'ip_address' => '203.0.113.1',
        ]);

        $this->assertNull($repo->findByToken(DbPasswordResetTokenRepository::hashToken('stale-token')));
    }
}
