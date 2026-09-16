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
use Poweradmin\Infrastructure\Database\PDOCommon;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\EditController;
use Poweradmin\BaseController;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Service\MessageService;
use ReflectionClass;
use RuntimeException;

/**
 * A zone-edit save must require edit permission on the zone. The page-level
 * check only proves view access, and the zone comment and SOA serial bump are
 * not re-checked per row like records are.
 */
#[CoversClass(EditController::class)]
class EditControllerSaveRecordsGateTest extends TestCase
{
    private const ZONE_ID = 42;
    private const USER_ID = 7;

    private array $sessionBackup = [];
    private array $postBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->sessionBackup = $_SESSION ?? [];
        $this->postBackup = $_POST;
        $_SESSION = ['userid' => self::USER_ID];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->sessionBackup;
        $_POST = $this->postBackup;
        parent::tearDown();
    }

    public static function deniedLevels(): array
    {
        return [
            'no edit permission' => ['none', true],
            'own but not owner' => ['own', false],
            'own_as_client but not owner' => ['own_as_client', false],
        ];
    }

    #[DataProvider('deniedLevels')]
    public function testSaveIsRefusedWithoutEditPermission(string $level, bool $owner): void
    {
        $dnsRecord = $this->createMock(DnsRecord::class);
        $dnsRecord->expects($this->never())->method('getSOARecord');

        $messages = $this->save($level, $owner, $dnsRecord);

        $this->assertSame('error', $messages[0]['type']);
        $this->assertStringContainsString('permission to edit', $messages[0]['content']);
    }

    public function testSaveProceedsWithEditPermission(): void
    {
        // Reaching the SOA lookup proves the gate passed.
        $_POST['record'] = [];
        $dnsRecord = $this->createMock(DnsRecord::class);
        $dnsRecord->method('getSOARecord')->willThrowException(new RuntimeException('reached'));

        $this->expectExceptionMessage('reached');
        $this->save('own', true, $dnsRecord);
    }

    /**
     * @return array<int, array{type: string, content: string}>
     */
    private function save(string $level, bool $owner, DnsRecord $dnsRecord): array
    {
        $db = new PDOCommon('sqlite::memory:', '', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER)');
        $db->exec('CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER, group_id INTEGER)');
        $db->exec('CREATE TABLE user_group_members (id INTEGER PRIMARY KEY, user_id INTEGER, group_id INTEGER)');
        if ($owner) {
            $db->exec('INSERT INTO zones (domain_id, owner) VALUES (' . self::ZONE_ID . ', ' . self::USER_ID . ')');
        }

        $reflection = new ReflectionClass(EditController::class);
        $controller = $reflection->newInstanceWithoutConstructor();

        $permissionService = $this->createMock(PermissionService::class);
        $permissionService->method('getEditPermissionLevelForZone')->willReturn($level);
        $messageService = new MessageService();

        $base = new ReflectionClass(BaseController::class);
        $properties = [
            'userContextService' => new UserContextService(),
            'permissionService' => $permissionService,
            'dnsRecord' => $dnsRecord,
            'db' => $db,
            'messageService' => $messageService,
        ];
        foreach ($properties as $name => $value) {
            ($reflection->hasProperty($name) ? $reflection : $base)->getProperty($name)->setValue($controller, $value);
        }

        $controller->saveRecords(self::ZONE_ID, 'example.com');

        return $messageService->getMessages('edit') ?? [];
    }
}
