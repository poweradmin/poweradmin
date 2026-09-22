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

namespace Poweradmin\Tests\Unit\Module\ZoneImportExport\Controller;

use PHPUnit\Framework\Attributes\CoversClass;
use Poweradmin\Application\Service\Web\AuditService;
use Poweradmin\Application\Service\Record\RecordManagerService;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Repository\UserGroupRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Dns\RecordWriteResult;
use Poweradmin\Domain\Service\Zone\ZoneCreateOwnershipResolver;
use Poweradmin\Domain\Service\Zone\ZoneManagementService;
use Poweradmin\Domain\Service\Zone\ZoneOwnershipResolution;
use Poweradmin\Module\ZoneImportExport\Controller\ZoneFileImportController;
use Poweradmin\Application\Controller\RequestHalted;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;

/**
 * Characterizes the zone file import page: the target zone on GET, what an
 * upload previews and stashes in the session, and how the confirm step reads
 * that stash back before writing records.
 */
#[CoversClass(ZoneFileImportController::class)]
class ZoneFileImportControllerTest extends SeamControllerTestCase
{
    private const SESSION_KEY = 'zone_import_data';
    private const ZONE_ID = 7;

    private const ZONE_FILE = "\$ORIGIN example.com.\n\$TTL 3600\n@ IN SOA ns1.example.com. hostmaster.example.com. 1 7200 3600 1209600 3600\nwww IN A 192.0.2.1\nmail IN MX 10 mail.example.com.\n";

    private string $editLevel = 'own';
    private string $zoneName = 'example.com';
    private bool $domainExists = false;
    private string $zoneType = 'MASTER';

    /** @var list<array<int, mixed>> */
    private array $writes = [];

    /** @var list<array<int, mixed>> */
    private array $auditLogs = [];

    private array $filesBackup = [];
    private ?string $uploadPath = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filesBackup = $_FILES;
        $_FILES = [];

        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('hasPermission')->willReturn(true);
        $permissions->method('getEditPermissionLevel')->willReturnCallback(fn(): string => $this->editLevel);
        $permissions->method('getEditPermissionLevelForZone')->willReturnCallback(fn(): string => $this->editLevel);

        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('getDomainNameById')->willReturnCallback(fn(): string => $this->zoneName);
        $domains->method('domainExists')->willReturnCallback(fn(): bool => $this->domainExists);
        $domains->method('getDomainIdByName')->willReturn(self::ZONE_ID);
        $domains->method('getDomainType')->willReturnCallback(fn(): string => $this->zoneType);

        $records = $this->createMock(RecordManagerService::class);
        $records->method('createRecord')->willReturnCallback(function (...$args): RecordWriteResult {
            $this->writes[] = $args;
            return RecordWriteResult::ok(count($this->writes));
        });

        $recordRepository = $this->createMock(RecordRepositoryInterface::class);
        $recordRepository->method('recordExists')->willReturn(false);

        $audit = $this->createMock(AuditService::class);
        $audit->method('logZoneImport')->willReturnCallback(function (...$args): void {
            $this->auditLogs[] = $args;
        });

        $groups = $this->createMock(UserGroupRepositoryInterface::class);
        $groups->method('findAll')->willReturn([['id' => 1, 'name' => 'ops']]);
        $groups->method('findByUserId')->willReturn([['id' => 1, 'name' => 'ops']]);

        $this->factory->method('permissionService')->willReturn($permissions);
        $this->factory->method('domainRepository')->willReturn($domains);
        $this->factory->method('recordManagerService')->willReturn($records);
        $this->factory->method('recordRepository')->willReturn($recordRepository);
        $this->factory->method('auditService')->willReturn($audit);
        $this->factory->method('userGroupRepository')->willReturn($groups);
    }

    protected function tearDown(): void
    {
        $_FILES = $this->filesBackup;
        if ($this->uploadPath !== null && file_exists($this->uploadPath)) {
            unlink($this->uploadPath);
        }

        parent::tearDown();
    }

    private function makeController(): ZoneFileImportController
    {
        $request = $this->requestData();

        return new ZoneFileImportController($request, true, $this->environment($this->configure([
            'modules' => ['zone_import_export.max_file_size' => 1048576, 'zone_import_export.auto_ttl_value' => 300],
        ])));
    }

    private function upload(string $content): void
    {
        $this->uploadPath = tempnam(sys_get_temp_dir(), 'zone');
        file_put_contents($this->uploadPath, $content);
        $_FILES['zone_file'] = [
            'name' => 'example.com.zone',
            'tmp_name' => $this->uploadPath,
            'error' => UPLOAD_ERR_OK,
            'size' => strlen($content),
        ];
    }

    private function haltOf(callable $action): RequestHalted
    {
        try {
            $action();
        } catch (RequestHalted $halt) {
            return $halt;
        }

        $this->fail('Expected the request to end early.');
    }

    public function testGetWithZoneIdPreselectsAnEditableTargetZone(): void
    {
        $this->query(['zone_id' => (string)self::ZONE_ID]);

        $controller = $this->makeController();
        $controller->run();

        $params = $this->renderedParams();
        $this->assertSame('@zone_import_export/import.html', $this->output->rendered[0][0]);
        $this->assertSame(self::ZONE_ID, $params['target_zone_id']);
        $this->assertSame('example.com', $params['target_zone_name']);
        $this->assertSame(1048576, $params['max_file_size']);
        $this->assertSame('1 MB', $params['max_file_size_human']);
    }

    public function testGetWithZoneIdTheUserCannotEditFallsBackToNoTarget(): void
    {
        $this->editLevel = 'none';
        $this->query(['zone_id' => (string)self::ZONE_ID]);

        $controller = $this->makeController();
        $controller->run();

        $params = $this->renderedParams();
        $this->assertSame(0, $params['target_zone_id']);
        $this->assertSame('', $params['target_zone_name']);
    }

    public function testGetWithoutZoneIdRendersNoTarget(): void
    {
        $controller = $this->makeController();
        $controller->run();

        $params = $this->renderedParams();
        $this->assertSame(0, $params['target_zone_id']);
        $this->assertSame('', $params['target_zone_name']);
        $this->assertArrayNotHasKey('preview', $params);
    }

    public function testUploadAsNewZonePreviewsRecordsAndStashesThemInTheSession(): void
    {
        $this->post(['import_mode' => 'new']);
        $this->upload(self::ZONE_FILE);

        $controller = $this->makeController();
        $controller->run();

        $params = $this->renderedParams();
        $this->assertTrue($params['preview']);
        $this->assertSame('new', $params['import_mode']);
        $this->assertSame(0, $params['existing_zone_id']);
        $this->assertSame('example.com', $params['origin']);
        $this->assertSame('example.com.zone', $params['filename']);
        $this->assertSame(2, $params['record_count']);
        $this->assertSame(['www.example.com', 'mail.example.com'], array_column($params['records'], 'name'));
        $this->assertSame(['A', 'MX'], array_column($params['records'], 'type'));
        $this->assertTrue($params['user_owner_allowed']);
        $this->assertTrue($params['group_owner_allowed']);
        $this->assertSame([['id' => 1, 'name' => 'ops']], $params['available_groups']);

        $stash = $this->session->get(self::SESSION_KEY);
        $this->assertSame(['origin', 'records', 'warnings', 'filename'], array_keys($stash));
        $this->assertSame('example.com', $stash['origin']);
        $this->assertSame('example.com.zone', $stash['filename']);
        $this->assertSame([], $stash['warnings']);
        $this->assertIsString($stash['records']);
        $this->assertSame($params['records'], json_decode($stash['records'], true));
    }

    public function testUploadAsNewZoneSwitchesToExistingWhenTheOriginAlreadyExists(): void
    {
        $this->domainExists = true;
        $this->post(['import_mode' => 'new']);
        $this->upload(self::ZONE_FILE);

        $controller = $this->makeController();
        $controller->run();

        $params = $this->renderedParams();
        $this->assertSame('existing', $params['import_mode']);
        $this->assertSame(self::ZONE_ID, $params['existing_zone_id']);
        $this->assertSame('example.com', $params['existing_zone_name']);
        $this->assertArrayNotHasKey('available_groups', $params);
    }

    public function testUploadIntoExistingZonePreviewsAgainstThatZone(): void
    {
        $this->post(['import_mode' => 'existing', 'existing_zone_id' => (string)self::ZONE_ID]);
        $this->upload(self::ZONE_FILE);

        $controller = $this->makeController();
        $controller->run();

        $params = $this->renderedParams();
        $this->assertSame('existing', $params['import_mode']);
        $this->assertSame(self::ZONE_ID, $params['existing_zone_id']);
        $this->assertSame('example.com', $params['existing_zone_name']);
        $this->assertSame('example.com', $this->session->get(self::SESSION_KEY)['origin']);
    }

    public function testUploadIntoExistingZoneWithoutEditRightsIsRefused(): void
    {
        $this->editLevel = 'none';
        $this->post(['import_mode' => 'existing', 'existing_zone_id' => (string)self::ZONE_ID]);
        $this->upload(self::ZONE_FILE);

        $controller = $this->makeController();
        $halt = $this->haltOf(fn() => $controller->run());

        $this->assertSame(_('You do not have permission to modify this zone.'), $halt->target);
        $this->assertFalse($this->session->has(self::SESSION_KEY));
    }

    public function testUploadWithoutAFileIsRefused(): void
    {
        $this->post(['import_mode' => 'new']);

        $controller = $this->makeController();
        $halt = $this->haltOf(fn() => $controller->run());

        $this->assertSame(RequestHalted::KIND_ERROR, $halt->kind);
        $this->assertSame(_('Please select a valid zone file to upload.'), $halt->target);
    }

    public function testConfirmWithoutAStashReportsAnExpiredSession(): void
    {
        $this->post(['import_mode' => 'existing', 'existing_zone_id' => (string)self::ZONE_ID]);

        $controller = $this->makeController();
        $halt = $this->haltOf(fn() => $controller->execute());

        $this->assertSame(_('Import session expired. Please upload the file again.'), $halt->target);
    }

    public function testConfirmWithACorruptStashIsRefused(): void
    {
        $this->session->set(self::SESSION_KEY, ['origin' => 'example.com', 'records' => 'not json', 'warnings' => [], 'filename' => 'x']);
        $this->post(['import_mode' => 'existing', 'existing_zone_id' => (string)self::ZONE_ID]);

        $controller = $this->makeController();
        $halt = $this->haltOf(fn() => $controller->execute());

        $this->assertSame(_('Invalid import data. Please upload the file again.'), $halt->target);
    }

    public function testPreviewThenConfirmWritesTheStashedRecordsIntoTheExistingZone(): void
    {
        $this->post(['import_mode' => 'existing', 'existing_zone_id' => (string)self::ZONE_ID]);
        $this->upload(self::ZONE_FILE);
        $this->makeController()->run();
        $this->assertTrue($this->session->has(self::SESSION_KEY));

        $this->post(['import_mode' => 'existing', 'existing_zone_id' => (string)self::ZONE_ID, 'conflict_strategy' => 'add_all']);
        $_FILES = [];
        $controller = $this->makeController();
        $controller->execute();

        $this->assertCount(2, $this->writes);
        $this->assertSame([self::ZONE_ID, 'www.example.com', 'A', '192.0.2.1', 3600, 0, '', self::USERNAME, 0, 'web'], $this->writes[0]);
        $this->assertSame([self::ZONE_ID, 'mail.example.com', 'MX', 'mail.example.com', 3600, 10, '', self::USERNAME, 0, 'web'], $this->writes[1]);
        $this->assertSame([[self::ZONE_ID, 'example.com', true]], $this->auditLogs);

        $params = $this->renderedParams();
        $this->assertTrue($params['result']);
        $this->assertSame(2, $params['success_count']);
        $this->assertSame(0, $params['fail_count']);
        $this->assertSame(0, $params['skip_count']);
        $this->assertSame(self::ZONE_ID, $params['zone_id']);
        $this->assertSame('example.com', $params['zone_name']);
        $this->assertFalse($this->session->has(self::SESSION_KEY));
    }

    public function testConfirmAsNewZoneCreatesTheZoneFromTheSubmittedNameTypeAndGroups(): void
    {
        $resolver = $this->createMock(ZoneCreateOwnershipResolver::class);
        $resolver->method('resolveOwnership')->willReturnCallback(
            fn(?int $owner, array $groupIds): ZoneOwnershipResolution => ZoneOwnershipResolution::success($owner, $groupIds)
        );
        $this->factory->method('zoneCreateOwnershipResolver')->willReturn($resolver);

        $creates = [];
        $zones = $this->createMock(ZoneManagementService::class);
        $zones->method('createZone')->willReturnCallback(function (...$args) use (&$creates): array {
            $creates[] = $args;
            return ['success' => true, 'zone_id' => 31];
        });
        $this->factory->method('zoneManagementService')->willReturn($zones);

        $this->session->set(self::SESSION_KEY, [
            'origin' => 'example.com',
            'records' => json_encode([['name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 3600, 'priority' => 0]]),
            'warnings' => [],
            'filename' => 'example.com.zone',
        ]);
        $this->post(['import_mode' => 'new', 'zone_name' => 'Example.com', 'zone_type' => 'native', 'groups' => ['1', '2'], 'no_user_owner' => '1']);

        $controller = $this->makeController();
        $controller->execute();

        // toPunycode() lowercases the submitted name before it reaches the create
        $this->assertSame([['example.com', 'NATIVE', null, '', 'none', false, [1, 2], self::USER_ID, null]], $creates);
        $this->assertSame([[31, 'example.com', false]], $this->auditLogs);
        $this->assertCount(1, $this->writes);
        $this->assertSame(31, $this->writes[0][0]);

        $params = $this->renderedParams();
        $this->assertSame(31, $params['zone_id']);
        $this->assertSame('example.com', $params['zone_name']);
        $this->assertSame(1, $params['success_count']);
        $this->assertFalse($this->session->has(self::SESSION_KEY));
    }

    public function testConfirmAsNewZoneWithAnUnknownTypeIsRefused(): void
    {
        $this->session->set(self::SESSION_KEY, ['origin' => 'example.com', 'records' => '[]', 'warnings' => [], 'filename' => 'x']);
        $this->post(['import_mode' => 'new', 'zone_type' => 'bogus']);

        $controller = $this->makeController();
        $halt = $this->haltOf(fn() => $controller->execute());

        $this->assertSame(_('Invalid zone type.'), $halt->target);
    }

    public function testConfirmIntoAReadOnlyZoneIsRefusedAndKeepsTheStash(): void
    {
        $this->zoneType = 'SLAVE';
        $this->session->set(self::SESSION_KEY, [
            'origin' => 'example.com',
            'records' => json_encode([['name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 3600, 'priority' => 0]]),
            'warnings' => [],
            'filename' => 'example.com.zone',
        ]);
        $this->post(['import_mode' => 'existing', 'existing_zone_id' => (string)self::ZONE_ID]);

        $controller = $this->makeController();
        $halt = $this->haltOf(fn() => $controller->execute());

        $this->assertSame(_('You cannot import records into a read-only zone.'), $halt->target);
        $this->assertSame([], $this->writes);
        $this->assertTrue($this->session->has(self::SESSION_KEY));
    }
}
