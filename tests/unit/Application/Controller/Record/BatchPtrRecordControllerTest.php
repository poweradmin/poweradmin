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
use PHPUnit\Framework\Attributes\DataProvider;
use Poweradmin\Application\Controller\Record\BatchPtrRecordController;
use Poweradmin\Application\Controller\RequestHalted;
use Poweradmin\Application\Service\Zone\ChangeApprovalContext;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneChangeRequestRepositoryInterface;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Dns\ReverseTtlResolver;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;

/**
 * Characterizes who may open the batch PTR page: any zone editor, including a
 * client-level editor, on a forward zone they can edit.
 */
#[CoversClass(BatchPtrRecordController::class)]
class BatchPtrRecordControllerTest extends SeamControllerTestCase
{
    private const ZONE_ID = 12;

    /** @var list<string> Permissions hasPermission() answers yes to */
    private array $granted = [];

    private string $zoneEditLevel = 'own_as_client';
    private string $zoneType = 'MASTER';
    private string $zoneName = 'example.com';

    protected function setUp(): void
    {
        parent::setUp();

        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('hasPermission')
            ->willReturnCallback(fn(int $userId, string $permission): bool => in_array($permission, $this->granted, true));
        $permissions->method('getEditPermissionLevelForZone')->willReturnCallback(fn(): string => $this->zoneEditLevel);
        $permissions->method('getViewPermissionLevel')->willReturn('own');
        $permissions->method('getChangeRequestPermissionLevelForZone')->willReturn('none');

        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('getDomainType')->willReturnCallback(fn(): string => $this->zoneType);
        $domains->method('getDomainNameById')->willReturnCallback(fn(): string => $this->zoneName);

        $zones = $this->createMock(ZoneRepositoryInterface::class);
        $zones->method('getReverseZones')->willReturn([]);

        $ttl = $this->createMock(ReverseTtlResolver::class);
        $ttl->method('getDefaultTtl')->willReturn(86400);

        $approval = new ChangeApprovalContext(
            $this->config,
            fn(): PermissionService => $permissions,
            fn(): ZoneRepositoryInterface => $zones,
            fn(): ZoneChangeRequestRepositoryInterface => $this->createMock(ZoneChangeRequestRepositoryInterface::class)
        );

        $this->factory->method('permissionService')->willReturn($permissions);
        $this->factory->method('domainRepository')->willReturn($domains);
        $this->factory->method('zoneRepository')->willReturn($zones);
        $this->factory->method('reverseTtlResolver')->willReturn($ttl);
        $this->factory->method('changeApprovalContext')->willReturn($approval);
    }

    /** @param array<string, array<string, mixed>> $config */
    private function makeController(bool $withZone = true, array $config = []): BatchPtrRecordController
    {
        $this->query($withZone ? ['id' => (string)self::ZONE_ID] : []);

        return new BatchPtrRecordController($this->requestData(), true, $this->environment($this->configure($config)));
    }

    private function haltOf(BatchPtrRecordController $controller): RequestHalted
    {
        try {
            $controller->run();
        } catch (RequestHalted $halt) {
            return $halt;
        }

        $this->fail('Expected the request to end early.');
    }

    /** @return array<string, array{0: list<string>}> */
    public static function editorProvider(): array
    {
        return [
            'client editor' => [[Permission::PERM_ZONE_CONTENT_EDIT_OWN_AS_CLIENT]],
            'owner editor' => [[Permission::PERM_ZONE_CONTENT_EDIT_OWN]],
            'editor of all zones' => [[Permission::PERM_ZONE_CONTENT_EDIT_OTHERS]],
        ];
    }

    /** @param list<string> $granted */
    #[DataProvider('editorProvider')]
    public function testEveryKindOfZoneEditorGetsTheForm(array $granted): void
    {
        $this->granted = $granted;

        $this->makeController()->run();

        $this->assertSame('batch_ptr_record.html', $this->renderedTemplate());
        $this->assertSame(self::ZONE_ID, $this->renderedParams()['zone_id']);
    }

    public function testTheFormWithoutAZoneIsOfferedToAClientEditorToo(): void
    {
        $this->granted = [Permission::PERM_ZONE_CONTENT_EDIT_OWN_AS_CLIENT];

        $this->makeController(false)->run();

        $this->assertSame('batch_ptr_record.html', $this->renderedTemplate());
    }

    public function testAViewOnlyUserIsRefused(): void
    {
        $this->granted = [Permission::PERM_ZONE_CONTENT_VIEW_OWN];

        $halt = $this->haltOf($this->makeController());

        $this->assertSame(RequestHalted::KIND_CONDITION, $halt->kind);
        $this->assertSame('You do not have permission to edit DNS records.', $halt->target);
    }

    public function testAZoneTheEditorCannotEditIsRefused(): void
    {
        $this->granted = [Permission::PERM_ZONE_CONTENT_EDIT_OWN_AS_CLIENT];
        $this->zoneEditLevel = 'none';

        $halt = $this->haltOf($this->makeController());

        $this->assertSame('You do not have permission to add records to this zone.', $halt->target);
    }

    public function testTheSettingSwitchesThePageOffForEveryone(): void
    {
        $this->granted = [Permission::PERM_ZONE_CONTENT_EDIT_OTHERS];

        $halt = $this->haltOf($this->makeController(true, ['interface' => ['add_reverse_record' => false]]));

        $this->assertSame('Batch PTR record creation is not enabled.', $halt->target);
    }
}
