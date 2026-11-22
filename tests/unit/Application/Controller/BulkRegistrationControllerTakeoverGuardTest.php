<?php

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols

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

namespace Poweradmin\Tests\Unit\Application\Controller;

use DomainException;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\BulkRegistrationController;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use ReflectionMethod;
use ReflectionProperty;

require_once __DIR__ . '/ControllerTestStubs.php';

/**
 * Bulk registration refuses a name that already carries records, as the single-zone
 * form does.
 */
class BulkRegistrationControllerTakeoverGuardTest extends TestCase
{
    private array $savedPost = [];

    protected function setUp(): void
    {
        $this->savedPost = $_POST;
        $_SESSION['userid'] = 5;
    }

    protected function tearDown(): void
    {
        $_POST = $this->savedPost;
        unset($_SESSION['userid']);
    }

    #[RunInSeparateProcess]
    public function testANameWithExistingRecordsIsNotCreated(): void
    {
        $this->assertSame('Some zone(s) could not be added.', $this->outcomeFor('taken.example'));
    }

    private function outcomeFor(string $domain): ?string
    {
        $_POST = ['dom_type' => 'MASTER', 'zone_template' => 'none', 'owner' => '5', 'domains' => $domain];

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(fn(string $group, string $key, mixed $default = null): mixed => $group === 'database' && $key === 'type' ? 'sqlite' : $default);

        $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('CREATE TABLE domains (id INTEGER PRIMARY KEY, name TEXT NOT NULL, type TEXT NOT NULL)');
        $db->exec('CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT)');
        $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, perm_templ INTEGER)');
        $db->exec('CREATE TABLE perm_templ (id INTEGER PRIMARY KEY)');
        $db->exec('CREATE TABLE perm_items (id INTEGER PRIMARY KEY, name TEXT)');
        $db->exec('CREATE TABLE perm_templ_items (templ_id INTEGER, perm_id INTEGER)');
        $db->exec('CREATE TABLE user_groups (id INTEGER PRIMARY KEY, perm_templ INTEGER)');
        $db->exec('CREATE TABLE user_group_members (user_id INTEGER, group_id INTEGER)');
        $db->exec("INSERT INTO records (domain_id, name, type, content) VALUES (NULL, 'taken.example', 'A', '192.0.2.1')");

        $controller = new class ([], $db, $config) extends BulkRegistrationController {
            use ControllerTestStubs;

            protected function getZoneOverlapError(string $zoneName): ?string
            {
                return null;
            }

            public function setMessage(string $script, string $type, string $content): void
            {
                throw new DomainException($content);
            }
        };
        (new ReflectionProperty(BulkRegistrationController::class, 'userContextService'))->setValue($controller, new UserContextService());

        try {
            (new ReflectionMethod(BulkRegistrationController::class, 'doBulkRegistration'))->invoke($controller);
        } catch (DomainException $message) {
            return $message->getMessage();
        }

        return null;
    }
}
