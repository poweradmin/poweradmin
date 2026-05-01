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

namespace Unit\Domain\Model;

use PDO;
use PDOStatement;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Model\ZoneTemplate;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;

/**
 * Coverage for the default-template resolver and writers.
 *
 * @see ZoneTemplate::getDefaultTemplateId()
 * @see ZoneTemplate::setDefaultTemplate()
 * @see ZoneTemplate::unsetDefaultTemplate()
 */
class ZoneTemplateDefaultTest extends TestCase
{
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
        $template = new ZoneTemplate($db, $this->makeConfig('mysql', 'should-be-ignored'));

        $this->assertSame(7, $template->getDefaultTemplateId());
    }

    public function testGetDefaultTemplateIdResolvesConfigById(): void
    {
        $db = $this->makeDb([
            ['sql' => 'WHERE is_default', 'fetchColumn' => false],
            ['sql' => 'WHERE id = :id AND owner = 0', 'fetchColumn' => 1],
        ]);
        $template = new ZoneTemplate($db, $this->makeConfig('mysql', 12));

        $this->assertSame(12, $template->getDefaultTemplateId());
    }

    public function testGetDefaultTemplateIdResolvesConfigByName(): void
    {
        $db = $this->makeDb([
            ['sql' => 'WHERE is_default', 'fetchColumn' => false],
            ['sql' => 'WHERE name = :name AND owner = 0', 'fetchAllColumn' => [9]],
        ]);
        $template = new ZoneTemplate($db, $this->makeConfig('mysql', 'Standard'));

        $this->assertSame(9, $template->getDefaultTemplateId());
    }

    public function testGetDefaultTemplateIdReturnsNullForDuplicateName(): void
    {
        $db = $this->makeDb([
            ['sql' => 'WHERE is_default', 'fetchColumn' => false],
            ['sql' => 'WHERE name = :name AND owner = 0', 'fetchAllColumn' => [9, 10]],
        ]);
        $template = new ZoneTemplate($db, $this->makeConfig('mysql', 'Standard'));

        $this->assertNull($template->getDefaultTemplateId());
    }

    public function testGetDefaultTemplateIdReturnsNullForUnknownName(): void
    {
        $db = $this->makeDb([
            ['sql' => 'WHERE is_default', 'fetchColumn' => false],
            ['sql' => 'WHERE name = :name AND owner = 0', 'fetchAllColumn' => []],
        ]);
        $template = new ZoneTemplate($db, $this->makeConfig('mysql', 'Standard'));

        $this->assertNull($template->getDefaultTemplateId());
    }

    public function testGetDefaultTemplateIdReturnsNullForUnknownId(): void
    {
        $db = $this->makeDb([
            ['sql' => 'WHERE is_default', 'fetchColumn' => false],
            ['sql' => 'WHERE id = :id AND owner = 0', 'fetchColumn' => false],
        ]);
        $template = new ZoneTemplate($db, $this->makeConfig('mysql', 999));

        $this->assertNull($template->getDefaultTemplateId());
    }

    public function testGetDefaultTemplateIdReturnsNullWhenNothingConfigured(): void
    {
        $db = $this->makeDb([
            ['sql' => 'WHERE is_default', 'fetchColumn' => false],
        ]);
        $template = new ZoneTemplate($db, $this->makeConfig('mysql', null));

        $this->assertNull($template->getDefaultTemplateId());
    }

    public function testSetDefaultTemplateRejectsPrivateTemplate(): void
    {
        $db = $this->makeDb([
            ['sql' => 'SELECT owner FROM zone_templ WHERE id = :id', 'fetchColumn' => 5],
        ]);
        $template = new ZoneTemplate($db, $this->makeConfig());

        $this->assertFalse($template->setDefaultTemplate(42));
    }

    public function testSetDefaultTemplateRejectsNonexistent(): void
    {
        $db = $this->makeDb([
            ['sql' => 'SELECT owner FROM zone_templ WHERE id = :id', 'fetchColumn' => false],
        ]);
        $template = new ZoneTemplate($db, $this->makeConfig());

        $this->assertFalse($template->setDefaultTemplate(999));
    }

    public function testSetDefaultTemplateAcceptsGlobal(): void
    {
        $db = $this->makeDb([
            ['sql' => 'SELECT owner FROM zone_templ WHERE id = :id', 'fetchColumn' => 0],
            ['sql' => 'UPDATE zone_templ SET is_default = CASE WHEN id = :id', 'fetchColumn' => null],
        ]);
        $template = new ZoneTemplate($db, $this->makeConfig());

        $this->assertTrue($template->setDefaultTemplate(7));
    }

    public function testUnsetDefaultTemplateClearsFlag(): void
    {
        $db = $this->createMock(PDO::class);
        $db->expects($this->once())
            ->method('exec')
            ->with($this->stringContains('UPDATE zone_templ SET is_default'))
            ->willReturn(1);

        $template = new ZoneTemplate($db, $this->makeConfig());

        $this->assertTrue($template->unsetDefaultTemplate());
    }
}
