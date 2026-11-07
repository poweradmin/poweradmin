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
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Infrastructure\Repository\DbPasswordResetTokenRepository;
use Poweradmin\Infrastructure\Repository\DbUsernameRecoveryRepository;

/**
 * The per-email throttles count with the same case-insensitive match the user
 * lookup uses, so User@x and user@x share one budget.
 */
class RecoveryThrottleEmailCaseTest extends TestCase
{
    private function makeDb(): PDO
    {
        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec("CREATE TABLE password_reset_tokens (
            id INTEGER PRIMARY KEY, email TEXT NOT NULL, token TEXT NOT NULL, created_at TEXT,
            expires_at TEXT, ip_address TEXT, used INTEGER NOT NULL DEFAULT 0
        )");
        $db->exec("CREATE TABLE username_recovery_requests (
            id INTEGER PRIMARY KEY, email TEXT NOT NULL, ip_address TEXT, created_at TEXT
        )");

        return $db;
    }

    private function config(): ConfigurationInterface
    {
        $config = $this->createMock(ConfigurationInterface::class);
        $config->method('get')->willReturnCallback(
            fn ($group, $key, $default = null) => ($group === 'database' && $key === 'type') ? 'sqlite' : $default
        );

        return $config;
    }

    public function testPasswordResetCountIgnoresEmailCase(): void
    {
        $repo = new DbPasswordResetTokenRepository($this->makeDb(), $this->config());
        $repo->create(['email' => 'user@example.com', 'token' => 't', 'expires_at' => gmdate('Y-m-d H:i:s', time() + 3600)]);

        $this->assertSame(1, $repo->countRecentAttempts('User@Example.COM', 3600));
    }

    public function testUsernameRecoveryCountIgnoresEmailCase(): void
    {
        $repo = new DbUsernameRecoveryRepository($this->makeDb(), $this->config());
        $repo->create(['email' => 'user@example.com', 'ip_address' => '203.0.113.1']);

        $this->assertSame(1, $repo->countRecentAttempts('USER@example.com', 3600));
    }
}
