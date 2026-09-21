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

namespace Poweradmin\Tests\Unit\Application\Controller\Record;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use Poweradmin\Application\Controller\Record\EditCommentController;
use Poweradmin\Application\Service\AuditService;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\Dns\RecordManagerInterface;
use Poweradmin\Domain\Service\Dns\RecordWriteResult;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Tests\Unit\Application\Controller\ControllerHalt;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;

/**
 * Characterizes the zone comment form: who may save, and what a save that the
 * record manager refuses shows the operator.
 */
#[CoversClass(EditCommentController::class)]
class EditCommentControllerTest extends SeamControllerTestCase
{
    private const ZONE_ID = 12;

    private string $editLevel = 'all';
    private bool $ownsZone = true;
    private string $zoneType = 'MASTER';
    private ?string $storedComment = 'kept in zones.comment';

    /** @var RecordManagerInterface&MockObject */
    private RecordManagerInterface $recordManager;

    /** @var AuditService&MockObject */
    private AuditService $audit;

    protected function setUp(): void
    {
        parent::setUp();

        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('userOwnsZone')->willReturnCallback(fn(): bool => $this->ownsZone);
        $permissions->method('getViewPermissionLevel')->willReturn('all');
        $permissions->method('getEditPermissionLevel')->willReturnCallback(fn(): string => $this->editLevel);
        $permissions->method('getEditPermissionLevelForZone')->willReturnCallback(fn(): string => $this->editLevel);
        $permissions->method('hasPermission')->willReturn(false);

        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('zoneIdExists')->willReturn(true);
        $domains->method('getDomainType')->willReturnCallback(fn(): string => $this->zoneType);
        $domains->method('getDomainNameById')->willReturn('example.com');

        $this->recordManager = $this->createMock(RecordManagerInterface::class);
        $this->audit = $this->createMock(AuditService::class);

        $this->factory->method('permissionService')->willReturn($permissions);
        $this->factory->method('domainRepository')->willReturn($domains);
        $this->factory->method('recordManager')->willReturn($this->recordManager);
        $this->factory->method('auditService')->willReturn($this->audit);

        $zones = $this->createMock(ZoneRepositoryInterface::class);
        $zones->method('getZoneComment')->willReturnCallback(fn(): ?string => $this->storedComment);
        $this->factory->method('zoneRepository')->willReturn($zones);
    }

    private function makeController(): TestableEditCommentController
    {
        return new TestableEditCommentController(
            ['id' => (string)self::ZONE_ID] + array_merge($_GET, $_POST),
            $this->environment($this->configure())
        );
    }

    private function haltOf(TestableEditCommentController $controller): ControllerHalt
    {
        try {
            $controller->run();
        } catch (ControllerHalt $halt) {
            return $halt;
        }

        $this->fail('Expected the request to end early.');
    }

    public function testTheFormShowsTheStoredZoneComment(): void
    {
        $controller = $this->makeController();
        $controller->renderForms = true;

        $controller->run();

        $this->assertSame('edit_comment.html', $controller->rendered[0][0]);
        $this->assertSame('kept in zones.comment', $controller->rendered[0][1]['comment']);
        $this->assertSame(self::ZONE_ID, $controller->rendered[0][1]['zone_id']);
        $this->assertSame('example.com', $controller->rendered[0][1]['zone_name']);
    }

    public function testAZoneWithoutACommentRendersAnEmptyTextarea(): void
    {
        $this->storedComment = null;
        $controller = $this->makeController();
        $controller->renderForms = true;

        $controller->run();

        $this->assertSame('', $controller->rendered[0][1]['comment']);
    }

    public function testASavedCommentFlashesSuccessAndReturnsToTheZone(): void
    {
        $this->post(['commit' => '1', 'comment' => 'hello']);
        $this->recordManager->expects($this->once())->method('editZoneComment')->with(self::ZONE_ID, 'hello')->willReturn(RecordWriteResult::ok());
        $this->audit->expects($this->once())->method('logZoneCommentEdit')->with(self::ZONE_ID, 'example.com');

        $halt = $this->haltOf($this->makeController());

        $this->assertSame(ControllerHalt::KIND_REDIRECT, $halt->kind);
        $this->assertSame('/zones/12/edit', $halt->target);
        $this->assertSame([['success', 'The comment has been updated successfully.']], $this->messagesFor('edit'));
        $this->assertSame([], $this->messagesFor('system'));
    }

    public function testAnOperatorWithoutEditRightsSeesTheDisabledFormWithTheRefusal(): void
    {
        $this->editLevel = 'none';
        $this->post(['commit' => '1', 'comment' => 'hello']);
        $this->recordManager->expects($this->never())->method('editZoneComment');

        $controller = $this->makeController();
        $controller->run();

        $this->assertSame([[self::ZONE_ID, true]], $controller->formsShown);
        $this->assertSame([['error', 'You do not have the permission to edit this comment.']], $this->messagesFor('system'));
        $this->assertSame([], $this->messagesFor('edit'));
    }

    public function testAWriteTheRecordManagerRefusesShowsTheFormWithItsReason(): void
    {
        $this->post(['commit' => '1', 'comment' => 'hello']);
        $this->recordManager->method('editZoneComment')->willReturn(RecordWriteResult::forbidden('You do not have the permission to edit this comment.'));
        $this->audit->expects($this->never())->method('logZoneCommentEdit');

        $controller = $this->makeController();
        $controller->run();

        $this->assertSame([[self::ZONE_ID, false]], $controller->formsShown);
        $this->assertSame([['error', 'You do not have the permission to edit this comment.']], $this->messagesFor('system'));
        $this->assertSame([], $this->messagesFor('edit'));
    }
}
