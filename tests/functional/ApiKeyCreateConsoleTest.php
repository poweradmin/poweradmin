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

declare(strict_types=1);

namespace Poweradmin\Tests\Functional;

use PDO;
use TestHelpers\ConsoleSqliteTestCase;

/**
 * bin/poweradmin api-keys:create, end to end against the shared SQLite fixture
 * with the API enabled.
 */
class ApiKeyCreateConsoleTest extends ConsoleSqliteTestCase
{
    private static string $apiSettings;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$apiSettings = self::$fixtureDir . '/api-settings.php';
        file_put_contents(
            self::$apiSettings,
            "<?php\nreturn ['database' => ['type' => 'sqlite', 'file' => " . var_export(self::$fixtureDir . '/poweradmin.sqlite', true) . "], 'api' => ['enabled' => true, 'max_keys_per_user' => 5]];\n"
        );
    }

    public function testAdminIssuesAKeyAndTheSecretIsPrintedOnceAndStoredHashed(): void
    {
        [$exit, $stdout, $stderr] = $this->runConsole(['api-keys:create', '--user=1', '--name=deploy', '--expires=2099-01-31', '--readonly'], self::$apiSettings);

        $this->assertSame(0, $exit, $stderr);
        $this->assertMatchesRegularExpression('/^pwa_[0-9a-f]{64}\n$/', $stdout);
        $secret = trim($stdout);
        $this->assertStringContainsString('Created API key', $stderr);
        $this->assertStringNotContainsString($secret, $stderr);

        $row = $this->fetchKey('deploy');
        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row['created_by']);
        $this->assertSame(1, (int) $row['is_readonly']);
        $this->assertStringStartsWith('2099-01-31', (string) $row['expires_at']);
        $this->assertNotSame($secret, $row['secret_key']);
        $this->assertSame('sha256$' . hash('sha256', $secret), $row['secret_key']);
    }

    public function testAnInactiveUserGetsNoKey(): void
    {
        $db = new \PDO('sqlite:' . self::$fixtureDir . '/poweradmin.sqlite');
        $db->exec('UPDATE users SET active = 0 WHERE id = 1');
        try {
            [$exit, $stdout, $stderr] = $this->runConsole(['api-keys:create', '--user=1', '--name=inactive'], self::$apiSettings);
        } finally {
            $db->exec('UPDATE users SET active = 1 WHERE id = 1');
        }

        $this->assertSame(1, $exit);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('User 1 is inactive', $stderr);
        $this->assertNull($this->fetchKey('inactive'));
    }

    public function testUserWithoutApiPermissionIsRefused(): void
    {
        foreach ([self::VIEWER_USER, self::GUEST_USER] as $user) {
            [$exit, $stdout, $stderr] = $this->runConsole(['api-keys:create', '--user=' . $user, '--name=nope' . $user], self::$apiSettings);

            $this->assertSame(1, $exit);
            $this->assertSame('', $stdout);
            $this->assertStringContainsString('permission', $stderr);
            $this->assertNull($this->fetchKey('nope' . $user));
        }
    }

    public function testRefusedWhenTheApiIsDisabled(): void
    {
        [$exit, $stdout, $stderr] = $this->runConsole(['api-keys:create', '--user=1', '--name=off']);

        $this->assertSame(1, $exit);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('disabled', $stderr);
        $this->assertNull($this->fetchKey('off'));
    }

    public function testUnknownUserAndBadOptionsAreUsageErrors(): void
    {
        $cases = [
            'unknown user' => [['api-keys:create', '--user=99', '--name=x'], 'User 99 does not exist'],
            'no user' => [['api-keys:create', '--name=x'], 'needs --user=<id>'],
            'username' => [['api-keys:create', '--user=admin', '--name=x'], 'expects a positive integer'],
            'no name' => [['api-keys:create', '--user=1'], 'Option --name expects a label'],
            'empty name' => [['api-keys:create', '--user=1', '--name='], 'Option --name expects a label'],
            'bad date' => [['api-keys:create', '--user=1', '--name=x', '--expires=soon'], 'Option --expires expects a future date'],
            'impossible date' => [['api-keys:create', '--user=1', '--name=x', '--expires=2027-02-30'], 'Option --expires expects a future date'],
            'past date' => [['api-keys:create', '--user=1', '--name=x', '--expires=2000-01-01'], 'Option --expires expects a future date'],
            'five-digit year' => [['api-keys:create', '--user=1', '--name=x', '--expires=99999-01-01'], 'Option --expires expects a future date'],
            'name too long' => [['api-keys:create', '--user=1', '--name=' . str_repeat('a', 256)], 'up to 255 characters'],
            'control character in name' => [['api-keys:create', '--user=1', "--name=ab\ncd"], 'without control characters'],
            'two different users' => [['--as-user=1', 'api-keys:create', '--user=2', '--name=x'], 'name different users'],
            'readonly value' => [['api-keys:create', '--user=1', '--name=x', '--readonly=yes'], 'takes no value'],
            'unknown option' => [['api-keys:create', '--user=1', '--name=x', '--zone=1'], 'Unknown option "--zone"'],
            'stray argument' => [['api-keys:create', 'extra', '--user=1', '--name=x'], 'takes no arguments'],
        ];

        foreach ($cases as $label => [$argv, $message]) {
            [$exit, $stdout, $stderr] = $this->runConsole($argv, self::$apiSettings);

            $this->assertSame(1, $exit, $label);
            $this->assertSame('', $stdout, $label);
            $this->assertStringContainsString($message, $stderr, $label);
        }
        $this->assertNull($this->fetchKey('x'));
    }

    public function testHelpListsTheCommand(): void
    {
        [$exit, $stdout] = $this->runConsole(['--help']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('api-keys:create [--user=<id>] [--name=<label>] [--expires=<date>] [--readonly]', $stdout);
    }

    /** @return array<string, mixed>|null */
    private function fetchKey(string $name): ?array
    {
        $pdo = new PDO('sqlite:' . self::$fixtureDir . '/poweradmin.sqlite');
        $stmt = $pdo->prepare('SELECT * FROM api_keys WHERE name = ?');
        $stmt->execute([$name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }
}
