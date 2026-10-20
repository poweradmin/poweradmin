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
use Poweradmin\Application\Controller\RequestHalted;
use Poweradmin\Domain\Model\RecordRow;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Repository\RecordRepositoryInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Module\ZoneImportExport\Controller\ZoneFileExportController;
use Poweradmin\Tests\Unit\Application\Controller\SeamControllerTestCase;
use ReflectionMethod;

/**
 * The zone file download: who may export, and when record comments are written into the file.
 */
#[CoversClass(ZoneFileExportController::class)]
class ZoneFileExportControllerTest extends SeamControllerTestCase
{
    private const ZONE_ID = 7;

    private string $viewLevel = 'all';
    private bool $allDisabled = false;
    private bool $ownsZone = true;

    /** @var list<bool> The fetchComments flag of every record listing */
    private array $fetchedComments = [];

    protected function setUp(): void
    {
        parent::setUp();

        $permissions = $this->createMock(PermissionService::class);
        $permissions->method('getViewPermissionLevel')->willReturnCallback(fn(): string => $this->viewLevel);
        $permissions->method('userOwnsZone')->willReturnCallback(fn(): bool => $this->ownsZone);

        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('getDomainNameById')->willReturn('example.com');

        $records = $this->createMock(RecordRepositoryInterface::class);
        $records->method('getRecordsFromDomainId')->willReturnCallback(function (int $id, int $start, int $rows, string $sort, string $dir, bool $fetchComments): array {
            $this->fetchedComments[] = $fetchComments;
            return [RecordRow::fromRow([
                'id' => 1, 'domain_id' => self::ZONE_ID, 'name' => 'www.example.com', 'type' => 'A', 'content' => '192.0.2.1',
                'ttl' => 3600, 'prio' => 0, 'disabled' => $this->allDisabled, 'comment' => $fetchComments ? 'web server' : null,
            ]), RecordRow::fromRow([
                'id' => 2, 'domain_id' => self::ZONE_ID, 'name' => 'old.example.com', 'type' => 'A', 'content' => '192.0.2.9',
                'ttl' => 3600, 'prio' => 0, 'disabled' => 1, 'comment' => $fetchComments ? 'retired' : null,
            ])];
        });

        $this->factory->method('permissionService')->willReturn($permissions);
        $this->factory->method('domainRepository')->willReturn($domains);
        $this->factory->method('recordRepository')->willReturn($records);
    }

    private function exportContent(bool $commentsEnabled): ?string
    {
        $controller = new ZoneFileExportController($this->requestData(), true, $this->environment($this->configure([
            'interface' => ['show_record_comments' => $commentsEnabled],
            'pdns_api' => ['url' => '', 'key' => ''],
        ])));

        return (new ReflectionMethod($controller, 'zoneFileContent'))->invoke($controller, self::ZONE_ID, 'example.com');
    }

    public function testCommentsAreWrittenWhenAskedAndEnabled(): void
    {
        $this->query(['id' => (string)self::ZONE_ID, 'comments' => '1']);

        $content = $this->exportContent(true);

        $this->assertStringContainsString("; poweradmin-comment: web server\nwww.example.com. 3600 IN A 192.0.2.1\n", $content);
        $this->assertStringNotContainsString('old.example.com', $content);
        $this->assertStringNotContainsString('retired', $content);
        $this->assertSame([true], $this->fetchedComments);
    }

    public function testWithoutTheCheckboxTheFileHasNoComments(): void
    {
        $this->query(['id' => (string)self::ZONE_ID]);

        $content = $this->exportContent(true);

        $this->assertStringNotContainsString('poweradmin-comment', $content);
        $this->assertStringNotContainsString('old.example.com', $content);
        $this->assertSame([false], $this->fetchedComments);
    }

    public function testDisabledRecordCommentsIgnoreTheRequest(): void
    {
        $this->query(['id' => (string)self::ZONE_ID, 'comments' => '1']);

        $content = $this->exportContent(false);

        $this->assertStringNotContainsString('poweradmin-comment', $content);
        $this->assertSame([false], $this->fetchedComments);
    }

    public function testZoneWithOnlyDisabledRecordsHasNothingToExport(): void
    {
        $this->allDisabled = true;
        $this->query(['id' => (string)self::ZONE_ID, 'comments' => '1']);

        $this->assertNull($this->exportContent(true));
    }

    public function testUserWhoCannotViewTheZoneGetsNoExport(): void
    {
        $this->viewLevel = 'own';
        $this->ownsZone = false;
        $this->query(['id' => (string)self::ZONE_ID, 'comments' => '1']);
        $controller = new ZoneFileExportController($this->requestData(), true, $this->environment($this->configure([
            'interface' => ['show_record_comments' => true],
        ])));

        try {
            $controller->run();
            $this->fail('Expected the export to be refused.');
        } catch (RequestHalted $halt) {
            $this->assertSame(_('You do not have permission to export this zone.'), $halt->target);
        }
        $this->assertSame([], $this->fetchedComments);
    }
}
