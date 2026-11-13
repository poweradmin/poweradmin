<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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

namespace Poweradmin\Tests\Unit\Domain\Service\Dns;

use PDO;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Dns\RecordManager;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Domain\Service\DnsRecordValidationServiceInterface;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use ReflectionProperty;

class RecordManagerCommentOwnerTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
        (new ReflectionProperty(Permission::class, 'permissionService'))->setValue(null, null);
    }

    public function testCommentOnAZoneWithoutARowCreatesItWithoutAnOwner(): void
    {
        $_SESSION['userid'] = 9;
        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('hasPermission')->willReturn(true);
        (new ReflectionProperty(Permission::class, 'permissionService'))->setValue(null, $permissions);

        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('CREATE TABLE zones (id INTEGER PRIMARY KEY AUTOINCREMENT, domain_id INTEGER, owner INTEGER NULL, comment TEXT, zone_templ_id INTEGER)');
        $db->exec('CREATE TABLE zones_groups (id INTEGER PRIMARY KEY AUTOINCREMENT, domain_id INTEGER, group_id INTEGER)');
        $db->exec('CREATE TABLE user_group_members (user_id INTEGER, group_id INTEGER)');

        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('getDomainType')->willReturn('NATIVE');
        $manager = new RecordManager(
            $db,
            $this->createMock(ConfigurationManager::class),
            $this->createMock(DnsRecordValidationServiceInterface::class),
            $this->createMock(SOARecordManagerInterface::class),
            $domains
        );
        (new ReflectionProperty(RecordManager::class, 'permissionService'))->setValue($manager, $permissions);

        $this->assertTrue($manager->editZoneComment(42, 'note'));

        $row = $db->query('SELECT owner, comment FROM zones WHERE domain_id = 42')->fetch(PDO::FETCH_ASSOC);
        $this->assertNull($row['owner']);
        $this->assertSame('note', $row['comment']);
    }
}
