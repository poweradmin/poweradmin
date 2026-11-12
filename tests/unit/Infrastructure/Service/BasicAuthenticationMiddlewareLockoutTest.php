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

namespace Poweradmin\Tests\Unit\Infrastructure\Service;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOCommon;
use Poweradmin\Infrastructure\Service\BasicAuthenticationMiddleware;
use ReflectionMethod;

/**
 * An unknown username must cost the same password hash a wrong password does,
 * or the response time tells which usernames exist.
 */
class BasicAuthenticationMiddlewareLockoutTest extends TestCase
{
    public function testUnknownUsernameCostsAPasswordHash(): void
    {
        $db = new PDOCommon('sqlite::memory:', '', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username VARCHAR(64) NOT NULL, password VARCHAR(128) NOT NULL,
            active INTEGER, use_ldap INTEGER NOT NULL DEFAULT 0)");

        $settings = ['security.password_encryption' => 'bcrypt', 'security.password_cost' => 10, 'database.type' => 'sqlite'];
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(
            fn(string $group, string $key, mixed $default = null): mixed => $settings["$group.$key"] ?? $default
        );
        $middleware = new BasicAuthenticationMiddleware($db, $config);

        $start = microtime(true);
        $result = (new ReflectionMethod(BasicAuthenticationMiddleware::class, 'authenticateAndGetUserId'))
            ->invoke($middleware, 'nobody', 'whatever');

        $this->assertSame(0, $result);
        // A bcrypt cost-10 verify takes tens of milliseconds; the old early return took under one
        $this->assertGreaterThan(0.01, microtime(true) - $start);
    }
}
