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
use Poweradmin\Application\Controller\BulkRecordAddController;
use Poweradmin\Application\Service\RecordManagerService;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Domain\Service\PdnsCapabilities;
use Poweradmin\Domain\Service\RecordTypeService;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Domain\Service\UserPreferenceService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Poweradmin\Infrastructure\Utility\IpAddressRetriever;
use ReflectionMethod;
use ReflectionProperty;

/**
 * The bulk add form: how many lines one submission may carry, and what the user
 * gets back when it carries too many.
 */
class BulkRecordAddControllerLineCapTest extends TestCase
{
    private array $savedPost = [];
    private ?LegacyLogger $auditLogger = null;

    protected function setUp(): void
    {
        $this->savedPost = $_POST;
    }

    protected function tearDown(): void
    {
        $_POST = $this->savedPost;
        // The controller raises the time limit; the test runner has none
        set_time_limit(0);
    }

    public function testAListAboveTheCapIsRefusedWholeAndHandedBackForSplitting(): void
    {
        $text = self::lines(BulkRecordAddController::MAX_LINES + 1);
        $records = $this->createMock(RecordManagerService::class);
        $records->expects($this->never())->method('createRecord');

        $controller = $this->submit($text, $records);

        $this->assertSame([['bulk_record_add', 'error', sprintf('At most %d records per bulk add; split the list.', BulkRecordAddController::MAX_LINES)]], $controller->flashes);
        $this->assertSame('bulk_record_add.html', $controller->rendered[0] ?? null);
        $this->assertSame($text, $controller->rendered[1]['records_text'] ?? null);
        $this->assertSame([], $controller->rendered[1]['failed_records'] ?? null);
    }

    public function testBlankLinesDoNotCountTowardsTheCap(): void
    {
        $records = $this->createMock(RecordManagerService::class);
        $records->expects($this->exactly(BulkRecordAddController::MAX_LINES))->method('createRecord')->willReturn(true);

        $controller = $this->submit(self::lines(BulkRecordAddController::MAX_LINES) . "\n\n   \n\n", $records);

        $this->assertSame([['edit', 'success', sprintf('%d record(s) have been added successfully.', BulkRecordAddController::MAX_LINES)]], $controller->flashes);
        $this->assertSame('/zones/12/edit', $controller->redirectedTo);
    }

    public function testEachAddedRecordIsLoggedOnlyByTheRecordService(): void
    {
        $records = $this->createMock(RecordManagerService::class);
        $records->expects($this->exactly(2))->method('createRecord')->willReturn(true);
        $this->auditLogger = $this->createMock(LegacyLogger::class);
        $this->auditLogger->expects($this->never())->method('logInfo');

        $this->submit(self::lines(2), $records);
    }

    private static function lines(int $count): string
    {
        return implode("\n", array_map(static fn(int $i): string => "h$i,A,192.0.2." . ($i % 250 + 1) . ",0,3600", range(1, $count)));
    }

    private function submit(string $text, RecordManagerService $records): object
    {
        $_POST = ['records' => $text];

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(fn(string $group, string $key, mixed $default = null): mixed => $default);

        $controller = new class (['id' => '12'], new PDO('sqlite::memory:'), $config) extends BulkRecordAddController {
            use ControllerTestStubs;

            /** @var array<int, array{string, string, string}> */
            public array $flashes = [];
            /** @var array{0?: string, 1?: array<string, mixed>} */
            public array $rendered = [];
            public ?string $redirectedTo = null;
            public ?UserPreferenceService $preferences = null;

            public function setMessage(string $script, string $type, string $content): void
            {
                $this->flashes[] = [$script, $type, $content];
            }

            public function render(string $template, array $params): void
            {
                $this->rendered = [$template, $params];
            }

            public function redirect(string $url, array $args = []): void
            {
                $this->redirectedTo = $url;
            }

            protected function createUserPreferenceService(): UserPreferenceService
            {
                return $this->preferences;
            }

            protected function getRecordTypeCapabilities(): ?PdnsCapabilities
            {
                return null;
            }
        };
        $controller->preferences = $this->createMock(UserPreferenceService::class);

        $dnsRecord = $this->createMock(DnsRecord::class);
        $dnsRecord->method('getDomainNameById')->willReturn('example.com');
        $userContext = $this->createMock(UserContextService::class);
        $userContext->method('getLoggedInUserId')->willReturn(7);
        $userContext->method('getLoggedInUsername')->willReturn('tester');

        $values = [
            'auditLogger' => $this->auditLogger ?? $this->createMock(LegacyLogger::class),
            'dnsRecord' => $dnsRecord,
            'recordManager' => $records,
            'recordTypeService' => new RecordTypeService($config),
            'userContextService' => $userContext,
            'ipAddressRetriever' => new IpAddressRetriever([]),
        ];
        foreach ($values as $name => $value) {
            (new ReflectionProperty(BulkRecordAddController::class, $name))->setValue($controller, $value);
        }

        (new ReflectionMethod(BulkRecordAddController::class, 'doBulkRecordAddition'))->invoke($controller);

        return $controller;
    }
}
