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

namespace Poweradmin\Tests\Unit\Application\Controller\Api\V2;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use Poweradmin\Application\Controller\Api\V2\ZoneTemplateRecordsController;
use Poweradmin\Application\Controller\Api\V2\ZoneTemplatesController;
use Poweradmin\Domain\Repository\ZoneTemplateRepositoryInterface;
use Poweradmin\Domain\Service\Auth\ApiPermissionService;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Filtering, sorting and paging of GET /api/v2/zone-templates and
 * GET /api/v2/zone-templates/{id}/records, which run on the formatted list
 * like the zone record list.
 */
#[CoversClass(ZoneTemplatesController::class)]
#[CoversClass(ZoneTemplateRecordsController::class)]
class ZoneTemplateListsTest extends V2ControllerTestCase
{
    private const USER_ID = 1;

    /** @var ZoneTemplateRepositoryInterface&MockObject */
    private ZoneTemplateRepositoryInterface $repository;

    /** @var ApiPermissionService&MockObject */
    private ApiPermissionService $permissions;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(ZoneTemplateRepositoryInterface::class);
        $this->permissions = $this->createMock(ApiPermissionService::class);
        $this->permissions->method('canViewZoneTemplates')->willReturn(true);
        $this->permissions->method('userHasPermission')->willReturn(true);

        $this->repository->method('listZoneTemplates')->willReturn([
            ['id' => 1, 'name' => 'Mail10', 'descr' => 'MX and SPF', 'owner' => 0, 'zones_linked' => 4],
            ['id' => 2, 'name' => 'web', 'descr' => 'Web hosting', 'owner' => 1, 'zones_linked' => 9],
            ['id' => 3, 'name' => 'mail2', 'descr' => null, 'owner' => 1, 'zones_linked' => 4],
        ]);
        $this->repository->method('zoneTemplateExists')->willReturn(true);
        $this->repository->method('getZoneTemplateRecords')->willReturn([
            ['id' => 11, 'name' => '[ZONE]', 'type' => 'SOA', 'content' => '[NS1] [HOSTMASTER] [SERIAL] 28800 7200 604800 86400', 'ttl' => 86400, 'prio' => 0],
            ['id' => 12, 'name' => '[ZONE]', 'type' => 'MX', 'content' => 'mx2.[ZONE]', 'ttl' => 3600, 'prio' => 20],
            ['id' => 13, 'name' => '[ZONE]', 'type' => 'MX', 'content' => 'mx1.[ZONE]', 'ttl' => 3600, 'prio' => 10],
            ['id' => 14, 'name' => 'www.[ZONE]', 'type' => 'A', 'content' => '192.0.2.10', 'ttl' => 300, 'prio' => 0],
        ]);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function listTemplates(array $query = []): JsonResponse
    {
        $controller = $this->bareController(ZoneTemplatesController::class);
        $this->injectBaseCollaborators($controller, 'GET', null, $query);
        $this->inject($controller, 'repository', $this->repository);
        $this->inject($controller, 'apiPermissionService', $this->permissions);
        $this->inject($controller, 'authenticatedUserId', self::USER_ID);

        return $this->callHandler($controller, 'listZoneTemplates');
    }

    /**
     * @param array<string, mixed> $query
     */
    private function listRecords(array $query = []): JsonResponse
    {
        $controller = $this->bareController(ZoneTemplateRecordsController::class);
        $this->injectBaseCollaborators($controller, 'GET', null, $query);
        $this->inject($controller, 'pathParameters', ['id' => '5']);
        $this->inject($controller, 'repository', $this->repository);
        $this->inject($controller, 'apiPermissionService', $this->permissions);
        $this->inject($controller, 'authenticatedUserId', self::USER_ID);

        return $this->callHandler($controller, 'listRecords');
    }

    public function testTheTemplateListIsUnchangedWithoutTheNewParameters(): void
    {
        $body = $this->decode($this->listTemplates());

        $this->assertSame(['Mail10', 'web', 'mail2'], array_column($body['data']['templates'], 'name'));
        $this->assertArrayNotHasKey('pagination', $body);
    }

    public function testTemplatesAreFilteredOnNameAndDescription(): void
    {
        $this->assertSame(['Mail10', 'mail2'], array_column($this->decode($this->listTemplates(['q' => 'MAIL']))['data']['templates'], 'name'));
        $this->assertSame(['web'], array_column($this->decode($this->listTemplates(['q' => 'hosting']))['data']['templates'], 'name'));
    }

    public function testTemplatesSortNaturallyAndPage(): void
    {
        $body = $this->decode($this->listTemplates(['sort' => 'zones_linked:desc,name', 'per_page' => 2, 'page' => 2]));

        $this->assertSame(['Mail10'], array_column($body['data']['templates'], 'name'));
        $this->assertSame(['current_page' => 2, 'per_page' => 2, 'total' => 3, 'last_page' => 2], $body['pagination']);

        $byName = $this->decode($this->listTemplates(['sort' => 'name']))['data']['templates'];
        $this->assertSame(['mail2', 'Mail10', 'web'], array_column($byName, 'name'));
    }

    public function testAnUnknownTemplateSortFieldIs400(): void
    {
        $response = $this->listTemplates(['sort' => 'owner']);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame("Invalid sort field 'owner'. Allowed fields: id, name, zones_linked", $this->messageOf($response));
    }

    public function testTemplateRecordsAreFilteredByTypeNameAndContent(): void
    {
        $this->assertSame([12, 13], array_column($this->decode($this->listRecords(['type' => 'mx']))['data']['records'], 'id'));
        $this->assertSame([14], array_column($this->decode($this->listRecords(['name' => 'WWW']))['data']['records'], 'id'));
        $this->assertSame([11], array_column($this->decode($this->listRecords(['content' => '[hostmaster]']))['data']['records'], 'id'));
    }

    public function testTemplateRecordsSortAndPageAfterFiltering(): void
    {
        $body = $this->decode($this->listRecords(['type' => 'MX', 'sort' => 'priority', 'per_page' => 1]));

        $this->assertSame(['mx1.[ZONE]'], array_column($body['data']['records'], 'content'));
        $this->assertSame(['current_page' => 1, 'per_page' => 1, 'total' => 2, 'last_page' => 2], $body['pagination']);
    }

    public function testTemplateRecordsKeepTheirOrderWithoutTheNewParameters(): void
    {
        $body = $this->decode($this->listRecords());

        $this->assertSame([11, 12, 13, 14], array_column($body['data']['records'], 'id'));
        $this->assertArrayNotHasKey('pagination', $body);
    }

    public function testAnUnknownTemplateRecordSortFieldIs400BeforeTheTemplateIsRead(): void
    {
        $this->repository->expects($this->never())->method('getZoneTemplateRecords');

        $response = $this->listRecords(['sort' => 'prio']);

        $this->assertSame(400, $response->getStatusCode());
    }
}
