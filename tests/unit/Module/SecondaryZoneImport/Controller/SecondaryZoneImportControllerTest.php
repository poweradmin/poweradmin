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

namespace Poweradmin\Tests\Unit\Module\SecondaryZoneImport\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Service\Dns\DomainManagerInterface;
use Poweradmin\Domain\Service\ZoneManagementService;
use Poweradmin\Domain\Service\ZoneOwnershipResolution;
use Poweradmin\Module\SecondaryZoneImport\Controller\SecondaryZoneImportController;
use Poweradmin\Tests\Unit\Application\Controller\ControllerHalt;
use Poweradmin\Tests\Unit\Application\Controller\ZoneCreateControllerTestCase;

/**
 * Characterizes the secondary zone import: its gates, the secondary it
 * creates, the transfer it requests and the page it shows afterwards.
 */
#[CoversClass(SecondaryZoneImportController::class)]
class SecondaryZoneImportControllerTest extends ZoneCreateControllerTestCase
{
    private const PAGE = 'import';

    /** @var DomainManagerInterface&MockObject */
    private DomainManagerInterface $domainManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->granted = [Permission::PERM_ZONE_SLAVE_ADD];

        $this->domainManager = $this->createMock(DomainManagerInterface::class);
        $this->domainManager->method('retrieveZone')->willReturn(true);
        $this->factory->method('domainManager')->willReturn($this->domainManager);
    }

    private function makeController(): TestableSecondaryZoneImportController
    {
        return new TestableSecondaryZoneImportController($_GET + $_POST, $this->environment($this->configure()));
    }

    /** @param array<string, mixed> $fields */
    private function submit(array $fields): void
    {
        $this->post($fields + ['domain' => 'example.com', 'slave_master' => '192.0.2.53', 'owner' => '4']);
    }

    public function testTheSlaveAddPermissionIsRequired(): void
    {
        $this->granted = [];

        $halt = $this->haltOf(fn() => $this->makeController()->run());

        $this->assertSame(ControllerHalt::KIND_PERMISSION, $halt->kind);
        $this->assertSame('You do not have the permission to import a secondary zone.', $halt->target);
    }

    public function testAGetShowsTheForm(): void
    {
        $controller = $this->makeController();
        $controller->run();

        $this->assertSame('@secondary_zone_import/import.html', $controller->rendered[0][0]);
        $this->assertFalse($controller->rendered[0][1]['imported']);
    }

    public function testNameAndPrimaryAreBothRequired(): void
    {
        $this->submit(['slave_master' => '']);

        $controller = $this->makeController();
        $controller->run();

        $this->assertSame([['error', 'Zone name and primary server address are required.']], $this->messagesFor(self::PAGE));
        $this->assertCount(0, $this->createCalls);
    }

    public function testAnOwnershipRefusalIsWordedForTheForm(): void
    {
        $this->ownership = ZoneOwnershipResolution::error('api wording', 400, ZoneOwnershipResolution::GROUPS_NOT_MEMBER, [5]);
        $this->submit([]);

        $controller = $this->makeController();
        $controller->run();

        $this->assertSame([['error', 'You can only assign groups you are a member of (disallowed: 5)']], $this->messagesFor(self::PAGE));
        $this->assertCount(0, $this->createCalls);
    }

    public function testARefusedCreationIsWordedForTheForm(): void
    {
        $this->createResults = [self::refusedWith(ZoneManagementService::ERR_OVERLAP, 'overlap')];
        $this->submit([]);

        $controller = $this->makeController();
        $controller->run();

        $this->assertSame(
            [['error', 'Cannot create this zone because it overlaps an existing zone owned by another user.']],
            $this->messagesFor(self::PAGE)
        );
        $this->assertFalse($controller->rendered[0][1]['imported']);
        $this->assertSame([], $this->audited);
    }

    public function testAnImportedZoneIsAuditedTransferredAndShownWithItsUtf8Name(): void
    {
        $this->domainManager->expects($this->once())->method('retrieveZone')->with(1);
        $this->submit(['domain' => ' bücher.example ']);

        $controller = $this->makeController();
        $controller->run();

        $this->assertSame(
            ['xn--bcher-kva.example', 'SLAVE', 4, '192.0.2.53', 'none', false, [], self::USER_ID],
            array_slice($this->createCalls[0], 0, 8)
        );
        $this->assertSame([['logSecondaryZoneImport', 1, 'xn--bcher-kva.example', '192.0.2.53']], $this->audited);

        [$template, $params] = $controller->rendered[0];
        $this->assertSame('@secondary_zone_import/import.html', $template);
        $this->assertTrue($params['imported']);
        $this->assertSame(1, $params['imported_zone_id']);
        $this->assertSame('bücher.example', $params['imported_zone_name']);
        $this->assertTrue($params['transfer_requested']);
        $this->assertSame([], $this->messagesFor(self::PAGE));
    }
}
