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
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Infrastructure\Repository\DbPasswordResetTokenRepository;

/**
 * expires_at is written as UTC by PHP, so the validity check must use PHP's UTC
 * clock too, whatever the PHP or DB time zone. Comparing against the DB clock
 * marked fresh tokens expired west of UTC (audit H11); comparing local wall-clock
 * time repeated or skipped an hour at a DST change (#1850).
 */
class PasswordResetTokenExpiryClockTest extends TestCase
{
    private function makeRepository(PDO $db): DbPasswordResetTokenRepository
    {
        $config = $this->createMock(ConfigurationInterface::class);
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
        // Fixed offset without DST: local time repeats an hour at fall-back, which flakes the check
        date_default_timezone_set('Etc/GMT+8');

        $db = $this->makeDb();
        $repo = $this->makeRepository($db);

        $repo->create([
            'email' => 'user@example.com',
            'token' => 'fresh-token',
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
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
        // Fixed offset without DST: local time repeats an hour at fall-back, which flakes the check
        date_default_timezone_set('Etc/GMT+8');

        $db = $this->makeDb();
        $repo = $this->makeRepository($db);

        $repo->create([
            'email' => 'user@example.com',
            'token' => 'stale-token',
            'expires_at' => gmdate('Y-m-d H:i:s', time() - 60),
            'ip_address' => '203.0.113.1',
        ]);

        $this->assertNull($repo->findByToken(DbPasswordResetTokenRepository::hashToken('stale-token')));
    }

    #[RunInSeparateProcess]
    public function testFreshTokenIsValidUnderEastOfUtcTimezone(): void
    {
        // Comparing against local time here would read a fresh UTC expiry as 8 hours past
        date_default_timezone_set('Etc/GMT-8');

        $db = $this->makeDb();
        $repo = $this->makeRepository($db);
        $repo->create([
            'email' => 'user@example.com',
            'token' => 'east-token',
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
        ]);

        $this->assertNotNull($repo->findByToken(DbPasswordResetTokenRepository::hashToken('east-token')));
    }

    #[RunInSeparateProcess]
    public function testDeleteExpiredKeepsAFreshTokenUnderEastOfUtcTimezone(): void
    {
        date_default_timezone_set('Etc/GMT-8');

        $db = $this->makeDb();
        $repo = $this->makeRepository($db);
        $repo->create([
            'email' => 'user@example.com',
            'token' => 'keep',
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
        ]);
        $repo->create([
            'email' => 'user@example.com',
            'token' => 'drop',
            'expires_at' => gmdate('Y-m-d H:i:s', time() - 60),
        ]);

        $this->assertSame(1, $repo->deleteExpired());
        $this->assertNotNull($repo->findByToken(DbPasswordResetTokenRepository::hashToken('keep')));
    }

    public function testTokenCanBeClaimedOnlyOnce(): void
    {
        $db = $this->makeDb();
        $repo = $this->makeRepository($db);
        $repo->create([
            'email' => 'user@example.com',
            'token' => 'one-shot',
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
        ]);
        $id = (int) $repo->findByToken(DbPasswordResetTokenRepository::hashToken('one-shot'))['id'];

        $this->assertTrue($repo->markAsUsed($id));
        $this->assertFalse($repo->markAsUsed($id), 'A second redemption of the same token must not succeed.');
        $this->assertNull($repo->findByToken(DbPasswordResetTokenRepository::hashToken('one-shot')));
    }

    public function testAReleasedClaimMakesTheLinkUsableAgain(): void
    {
        $db = $this->makeDb();
        $repo = $this->makeRepository($db);
        $repo->create([
            'email' => 'user@example.com',
            'token' => 'retry',
            'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600),
        ]);
        $id = (int) $repo->findByToken(DbPasswordResetTokenRepository::hashToken('retry'))['id'];

        $this->assertTrue($repo->markAsUsed($id));
        $this->assertTrue($repo->releaseClaim($id));
        $this->assertNotNull($repo->findByToken(DbPasswordResetTokenRepository::hashToken('retry')));
        $this->assertTrue($repo->markAsUsed($id));
    }
}
