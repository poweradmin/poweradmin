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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Service\User\UpdateUserCommand;
use Poweradmin\Infrastructure\Repository\DbUserRepository;
use TestHelpers\FakeConfiguration;

/**
 * Changing an account's email drops the reset links and recovery requests
 * sent to the old address.
 */
#[CoversClass(DbUserRepository::class)]
class DbUserRepositoryEmailChangeTest extends TestCase
{
    private PDO $db;
    private DbUserRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach (
            [
                "CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, fullname TEXT, email TEXT NOT NULL DEFAULT '')",
                "CREATE TABLE password_reset_tokens (id INTEGER PRIMARY KEY, email TEXT NOT NULL)",
                "CREATE TABLE username_recovery_requests (id INTEGER PRIMARY KEY, email TEXT NOT NULL)",
                "INSERT INTO users VALUES (1, 'alice', 'Alice', 'Alice@Example.com'), (2, 'bob', 'Bob', 'bob@example.com')",
                "INSERT INTO password_reset_tokens (email) VALUES ('Alice@Example.com'), ('bob@example.com')",
                "INSERT INTO username_recovery_requests (email) VALUES ('alice@example.com'), ('bob@example.com')",
            ] as $sql
        ) {
            $this->db->exec($sql);
        }

        $this->repository = new DbUserRepository($this->db, new FakeConfiguration(['database' => ['type' => 'sqlite']]), false);
    }

    private function rows(string $where): int
    {
        return (int)$this->db->query("SELECT COUNT(*) FROM $where")->fetchColumn();
    }

    public function testChangingEmailRemovesRequestsForTheOldAddress(): void
    {
        $this->assertTrue($this->repository->updateUser(1, new UpdateUserCommand(email: 'new@example.com')));

        $this->assertSame(0, $this->rows("password_reset_tokens WHERE LOWER(email) = 'alice@example.com'"));
        $this->assertSame(0, $this->rows("username_recovery_requests WHERE LOWER(email) = 'alice@example.com'"));
        $this->assertSame(1, $this->rows("password_reset_tokens WHERE email = 'bob@example.com'"));
        $this->assertSame(1, $this->rows("username_recovery_requests WHERE email = 'bob@example.com'"));
    }

    public function testAProviderEmailChangeRemovesRequestsForTheOldAddress(): void
    {
        // SSO and LDAP sync rewrite the address without the edit path
        $this->repository->updateProvisionedUser(1, ['email' => 'new@example.com']);

        $this->assertSame(0, $this->rows("password_reset_tokens WHERE LOWER(email) = 'alice@example.com'"));
        $this->assertSame(0, $this->rows("username_recovery_requests WHERE LOWER(email) = 'alice@example.com'"));
        $this->assertSame(1, $this->rows("password_reset_tokens WHERE email = 'bob@example.com'"));
    }

    public function testKeepingTheSameEmailKeepsRequests(): void
    {
        $this->assertTrue($this->repository->updateUser(1, new UpdateUserCommand(fullname: 'Alice B', email: 'alice@example.com')));

        $this->assertSame(1, $this->rows("password_reset_tokens WHERE LOWER(email) = 'alice@example.com'"));
    }
}
