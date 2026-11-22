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

namespace Poweradmin\Tests\Unit\Application\Controller;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\BulkRegistrationController;
use Poweradmin\Application\Http\Request;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Service\Dns\DomainManagerInterface;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Poweradmin\Infrastructure\Utility\IpAddressRetriever;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/**
 * Bulk registration refuses a name that already carries records, per line, as the single-zone form does.
 */
class BulkRegistrationControllerTakeoverGuardTest extends TestCase
{
    private array $savedPost = [];

    protected function setUp(): void
    {
        $this->savedPost = $_POST;
    }

    protected function tearDown(): void
    {
        $_POST = $this->savedPost;
        $_SESSION = [];
    }

    public function testANameWithExistingRecordsIsRefusedAndTheRestIsStillCreated(): void
    {
        $_SESSION['userid'] = 5;
        $_POST = ['dom_type' => 'MASTER', 'zone_template' => 'none', 'owner' => '5', 'domains' => "taken.example\nfree.example"];

        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('domainExists')->willReturn(false);
        $domains->method('getZoneIdFromName')->willReturn(9);
        $records = $this->createMock(RecordRepositoryInterface::class);
        $records->method('hasNonDelegationRecords')->willReturnCallback(fn(string $name): bool => $name === 'taken.example');
        $manager = $this->createMock(DomainManagerInterface::class);
        $manager->expects($this->once())->method('addDomain')->with($this->anything(), 'free.example')->willReturn(true);

        $this->expectExceptionObject(new RuntimeException('Some zone(s) could not be added.'));
        $this->register($domains, $records, $manager);
    }

    public function testTheOverlapRefusalComesBeforeTheRecordsCheck(): void
    {
        $_SESSION['userid'] = 5;
        $_POST = ['dom_type' => 'MASTER', 'zone_template' => 'none', 'owner' => '5', 'domains' => 'www.parent.example'];

        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('domainExists')->willReturn(false);
        $records = $this->createMock(RecordRepositoryInterface::class);
        $records->expects($this->never())->method('hasNonDelegationRecords');
        $manager = $this->createMock(DomainManagerInterface::class);
        $manager->expects($this->never())->method('addDomain');

        $this->expectExceptionObject(new RuntimeException('Some zone(s) could not be added.'));
        $this->register($domains, $records, $manager, 'Cannot create this zone because it overlaps an existing zone owned by another user.');
    }

    private function register(DomainRepositoryInterface $domains, RecordRepositoryInterface $records, DomainManagerInterface $manager, ?string $overlap = null): void
    {
        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(fn(string $group, string $key, mixed $default = null): mixed => $default);

        $controller = new class ([]) extends BulkRegistrationController {
            use ControllerTestStubs;

            public ?DomainManagerInterface $manager = null;
            public ?string $overlap = null;

            public function wire(PDO $db, ConfigurationManager $config): void
            {
                $this->db = $db;
                $this->config = $config;
                (new ReflectionProperty(BulkRegistrationController::class, 'request'))->setValue($this, new Request());
                (new ReflectionProperty(BulkRegistrationController::class, 'userContextService'))->setValue($this, new UserContextService());
                (new ReflectionProperty(BulkRegistrationController::class, 'ipAddressRetriever'))->setValue($this, new IpAddressRetriever([]));
                (new ReflectionProperty(BulkRegistrationController::class, 'auditLogger'))->setValue($this, new LegacyLogger($db));
                $this->setValidationConstraints([]);
            }

            protected function createDomainManager(): DomainManagerInterface
            {
                return $this->manager;
            }

            protected function getZoneOverlapError(string $zoneName): ?string
            {
                return $this->overlap;
            }

            public function setMessage(string $script, string $type, string $content): void
            {
                if ($type !== 'success') {
                    throw new RuntimeException($content);
                }
            }

            public function redirect(string $url, array $args = []): void
            {
            }
        };
        $db = new PDO('sqlite::memory:');
        $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, perm_templ INTEGER)');
        $db->exec('CREATE TABLE perm_templ (id INTEGER PRIMARY KEY)');
        $db->exec('CREATE TABLE perm_items (id INTEGER PRIMARY KEY, name TEXT)');
        $db->exec('CREATE TABLE perm_templ_items (templ_id INTEGER, perm_id INTEGER)');
        $db->exec('CREATE TABLE user_groups (id INTEGER PRIMARY KEY, perm_templ INTEGER)');
        $db->exec('CREATE TABLE user_group_members (user_id INTEGER, group_id INTEGER)');
        $controller->wire($db, $config);
        $controller->domains = $domains;
        $controller->records = $records;
        $controller->manager = $manager;
        $controller->overlap = $overlap;

        (new ReflectionMethod(BulkRegistrationController::class, 'doBulkRegistration'))->invoke($controller);
    }
}
