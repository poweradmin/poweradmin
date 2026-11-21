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

use PHPUnit\Framework\TestCase;
use Poweradmin\Application\Controller\DeleteDomainsController;
use Poweradmin\Application\Http\Request;
use Poweradmin\Application\Service\RecordCommentService;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Dns\DomainManager;
use Poweradmin\Domain\Service\Dns\DomainManagerInterface;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Poweradmin\Infrastructure\Utility\IpAddressRetriever;
use ReflectionProperty;

/**
 * A bulk zone delete audits every zone that went, also when another zone in the batch failed.
 */
class DeleteDomainsControllerAuditTest extends TestCase
{
    public function testEachDeletedZoneIsAuditedWhenAnotherOneFails(): void
    {
        $logged = $this->deleteZones(['deleted' => [1, 3], 'failed' => [2]]);

        $this->assertCount(2, $logged);
        $this->assertStringContainsString('operation:delete_zone zone:one.example', $logged[0]);
        $this->assertStringContainsString('operation:delete_zone zone:three.example', $logged[1]);
    }

    public function testNothingIsAuditedWhenNoZoneWasDeleted(): void
    {
        $this->assertSame([], $this->deleteZones(['deleted' => [], 'failed' => [1, 2, 3]]));
    }

    public function testAFullSuccessAuditsEveryZone(): void
    {
        $this->assertCount(3, $this->deleteZones(['deleted' => [1, 2, 3], 'failed' => []]));
    }

    public function testAManagerWithoutTheReportAuditsAllOrNothing(): void
    {
        $manager = $this->createMock(DomainManagerInterface::class);
        $manager->method('deleteDomains')->willReturn(true);

        $this->assertCount(3, $this->deleteZones(null, $manager));
    }

    /**
     * @param array{deleted: int[], failed: int[]}|null $report
     * @return string[] audit lines
     */
    private function deleteZones(?array $report, ?DomainManagerInterface $manager = null): array
    {
        if ($manager === null) {
            $manager = $this->createMock(DomainManager::class);
            $manager->method('deleteDomainsReport')->willReturn($report);
        }

        $controller = new class ([]) extends DeleteDomainsController {
            use ControllerTestStubs;

            public ?DomainManagerInterface $manager = null;

            public function redirect(string $url, array $args = []): void
            {
            }

            public function setMessage(string $script, string $type, string $content): void
            {
            }

            protected function createDomainManager(): DomainManagerInterface
            {
                return $this->manager;
            }
        };
        $controller->manager = $manager;

        $domains = $this->createMock(DomainRepositoryInterface::class);
        $domains->method('getZoneInfoFromIds')->willReturn([
            ['id' => 1, 'name' => 'one.example', 'type' => 'MASTER'],
            ['id' => 2, 'name' => 'two.example', 'type' => 'MASTER'],
            ['id' => 3, 'name' => 'three.example', 'type' => 'MASTER'],
        ]);
        $controller->domains = $domains;

        $config = $this->createMock(ConfigurationManager::class);
        $config->method('get')->willReturnCallback(fn(string $group, string $key, mixed $default = null): mixed => $default);
        $controller->setConfig($config);

        $logged = [];
        $logger = $this->createMock(LegacyLogger::class);
        $logger->method('logInfo')->willReturnCallback(function (string $line) use (&$logged): void {
            $logged[] = $line;
        });
        $userContext = $this->createMock(UserContextService::class);
        $userContext->method('getLoggedInUsername')->willReturn('admin');
        $values = [
            'request' => new Request(),
            'auditLogger' => $logger,
            'recordCommentService' => $this->createMock(RecordCommentService::class),
            'userContextService' => $userContext,
            'ipAddressRetriever' => new IpAddressRetriever([]),
        ];
        foreach ($values as $name => $value) {
            (new ReflectionProperty(DeleteDomainsController::class, $name))->setValue($controller, $value);
        }

        $controller->deleteDomains([1, 2, 3]);

        return $logged;
    }
}
