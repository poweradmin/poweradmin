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

namespace Poweradmin\Tests\Unit\Domain\Service\Template;

use PDO;
use PDOStatement;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Domain\Port\DnsBackendProviderInterface;
use Poweradmin\Domain\Service\Auth\PermissionService;
use Poweradmin\Domain\Service\Template\ZoneTemplateService;
use Poweradmin\Infrastructure\Repository\DbZoneTemplateRepository;
use Poweradmin\Infrastructure\Session\SessionActor;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TestHelpers\ZoneTemplateServiceBuilder;
use Poweradmin\Infrastructure\Session\PhpSession;

/**
 * Coverage for the default-template resolver and writers.
 *
 * @see ZoneTemplateService::getDefaultTemplateId()
 * @see ZoneTemplateService::setDefaultTemplate()
 * @see ZoneTemplateService::unsetDefaultTemplate()
 */
class ZoneTemplateServiceDefaultTest extends TestCase
{
    private function service(PDO $db, ConfigurationInterface $config, LoggerInterface $logger): ZoneTemplateService
    {
        $backend = $this->createMock(DnsBackendProviderInterface::class);
        return ZoneTemplateServiceBuilder::build(
            new DbZoneTemplateRepository($db, $config, $backend),
            $config,
            $backend,
            $this->createMock(PermissionService::class),
            new SessionActor(new PhpSession()),
            $logger
        );
    }

    private function makeConfig(?string $dbType = 'mysql', mixed $configured = null): ConfigurationInterface
    {
        $config = $this->createMock(ConfigurationInterface::class);
        $config->method('get')->willReturnCallback(function (string $section, string $key, $default = null) use ($dbType, $configured) {
            if ($section === 'database' && $key === 'type') {
                return $dbType;
            }
            if ($section === 'dns' && $key === 'default_zone_template') {
                return $configured;
            }
            return $default;
        });
        return $config;
    }

    /**
     * @param array<int, array{sql: string, params?: array, fetchColumn?: mixed, fetchAllColumn?: array, execReturn?: bool}> $expectations
     */
    private function makeDb(array $expectations): MockObject
    {
        $db = $this->createMock(PDO::class);
        $statements = [];

        $matchers = [];
        foreach ($expectations as $i => $exp) {
            $stmt = $this->createMock(PDOStatement::class);
            $stmt->method('execute')->willReturn(true);
            if (array_key_exists('fetchColumn', $exp)) {
                $stmt->method('fetchColumn')->willReturn($exp['fetchColumn']);
            }
            if (array_key_exists('fetchAllColumn', $exp)) {
                $stmt->method('fetchAll')->willReturn($exp['fetchAllColumn']);
            }
            $statements[$i] = ['sql_fragment' => $exp['sql'], 'stmt' => $stmt];
        }

        $callIndex = 0;
        $db->method('prepare')->willReturnCallback(function (string $query) use (&$callIndex, $statements) {
            $matched = null;
            foreach ($statements as $s) {
                if (str_contains($query, $s['sql_fragment'])) {
                    $matched = $s['stmt'];
                    break;
                }
            }
            if (!$matched) {
                throw new \RuntimeException('Unexpected SQL: ' . $query);
            }
            $callIndex++;
            return $matched;
        });

        return $db;
    }

    public function testGetDefaultTemplateIdReturnsDbFlagWhenSet(): void
    {
        $db = $this->makeDb([
            ['sql' => 'WHERE is_default = TRUE AND owner = 0', 'fetchColumn' => 7],
        ]);
        $template = $this->service($db, $this->makeConfig('mysql', 'should-be-ignored'), new NullLogger());

        $this->assertSame(7, $template->getDefaultTemplateId());
    }

    public function testGetDefaultTemplateIdResolvesConfigById(): void
    {
        $db = $this->makeDb([
            ['sql' => 'WHERE is_default', 'fetchColumn' => false],
            ['sql' => 'WHERE id = :id AND owner = 0', 'fetchColumn' => 1],
        ]);
        $template = $this->service($db, $this->makeConfig('mysql', 12), new NullLogger());

        $this->assertSame(12, $template->getDefaultTemplateId());
    }

    public function testGetDefaultTemplateIdResolvesConfigByName(): void
    {
        $db = $this->makeDb([
            ['sql' => 'WHERE is_default', 'fetchColumn' => false],
            ['sql' => 'WHERE name = :name AND owner = 0', 'fetchAllColumn' => [9]],
        ]);
        $template = $this->service($db, $this->makeConfig('mysql', 'Standard'), new NullLogger());

        $this->assertSame(9, $template->getDefaultTemplateId());
    }

    public function testGetDefaultTemplateIdReturnsNullForDuplicateName(): void
    {
        $db = $this->makeDb([
            ['sql' => 'WHERE is_default', 'fetchColumn' => false],
            ['sql' => 'WHERE name = :name AND owner = 0', 'fetchAllColumn' => [9, 10]],
        ]);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('matches {count} global zone templates'));
        $template = $this->service($db, $this->makeConfig('mysql', 'Standard'), $logger);

        $this->assertNull($template->getDefaultTemplateId());
    }

    public function testGetDefaultTemplateIdReturnsNullForUnknownName(): void
    {
        $db = $this->makeDb([
            ['sql' => 'WHERE is_default', 'fetchColumn' => false],
            ['sql' => 'WHERE name = :name AND owner = 0', 'fetchAllColumn' => []],
        ]);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('does not match any global zone template'));
        $template = $this->service($db, $this->makeConfig('mysql', 'Standard'), $logger);

        $this->assertNull($template->getDefaultTemplateId());
    }

    public function testGetDefaultTemplateIdReturnsNullForUnknownId(): void
    {
        $db = $this->makeDb([
            ['sql' => 'WHERE is_default', 'fetchColumn' => false],
            ['sql' => 'WHERE id = :id AND owner = 0', 'fetchColumn' => false],
        ]);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('does not match any global zone template'));
        $template = $this->service($db, $this->makeConfig('mysql', 999), $logger);

        $this->assertNull($template->getDefaultTemplateId());
    }

    public function testGetDefaultTemplateIdReturnsNullWhenNothingConfigured(): void
    {
        $db = $this->makeDb([
            ['sql' => 'WHERE is_default', 'fetchColumn' => false],
        ]);
        $template = $this->service($db, $this->makeConfig('mysql', null), new NullLogger());

        $this->assertNull($template->getDefaultTemplateId());
    }

    public function testSetDefaultTemplateRejectsPrivateTemplate(): void
    {
        $db = $this->makeDb([
            ['sql' => 'SELECT owner FROM zone_templ WHERE id = :id', 'fetchColumn' => 5],
        ]);
        $template = $this->service($db, $this->makeConfig(), new NullLogger());

        $this->assertFalse($template->setDefaultTemplate(42)->success);
    }

    public function testSetDefaultTemplateRejectsNonexistent(): void
    {
        $db = $this->makeDb([
            ['sql' => 'SELECT owner FROM zone_templ WHERE id = :id', 'fetchColumn' => false],
        ]);
        $template = $this->service($db, $this->makeConfig(), new NullLogger());

        $this->assertFalse($template->setDefaultTemplate(999)->success);
    }

    public function testSetDefaultTemplateAcceptsGlobal(): void
    {
        $db = $this->makeDb([
            ['sql' => 'SELECT owner FROM zone_templ WHERE id = :id', 'fetchColumn' => 0],
            ['sql' => 'UPDATE zone_templ SET is_default = CASE WHEN id = :id', 'fetchColumn' => null],
        ]);
        $template = $this->service($db, $this->makeConfig(), new NullLogger());

        $this->assertTrue($template->setDefaultTemplate(7)->success);
    }

    public function testUnsetDefaultTemplateClearsFlag(): void
    {
        $db = $this->createMock(PDO::class);
        $db->expects($this->once())
            ->method('exec')
            ->with($this->stringContains('UPDATE zone_templ SET is_default'))
            ->willReturn(1);

        $template = $this->service($db, $this->makeConfig(), new NullLogger());

        $this->assertTrue($template->unsetDefaultTemplate()->success);
    }

    public function testIsUserOwnerOfTemplateTrueWhenOwnerMatches(): void
    {
        $db = $this->makeDb([
            ['sql' => 'SELECT owner FROM zone_templ WHERE id = :id', 'fetchColumn' => 5],
        ]);
        $template = $this->service($db, $this->makeConfig(), new NullLogger());

        $this->assertTrue($template->isUserOwnerOfTemplate(42, 5));
    }

    public function testIsUserOwnerOfTemplateFalseWhenOwnerDiffers(): void
    {
        $db = $this->makeDb([
            ['sql' => 'SELECT owner FROM zone_templ WHERE id = :id', 'fetchColumn' => 5],
        ]);
        $template = $this->service($db, $this->makeConfig(), new NullLogger());

        $this->assertFalse($template->isUserOwnerOfTemplate(42, 9));
    }

    public function testIsUserOwnerOfTemplateFalseWhenTemplateMissing(): void
    {
        $db = $this->makeDb([
            ['sql' => 'SELECT owner FROM zone_templ WHERE id = :id', 'fetchColumn' => false],
        ]);
        $template = $this->service($db, $this->makeConfig(), new NullLogger());

        $this->assertFalse($template->isUserOwnerOfTemplate(999, 5));
    }
}
