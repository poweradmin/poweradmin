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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Poweradmin\Application\Controller\UnlinkZonesTemplController;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Module\Rdap\Controller\RdapController;
use Poweradmin\Module\Whois\Controller\WhoisController;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use TestHelpers\SqliteIntegrationTestCase;

/**
 * Pages that take a zone id only name the zone to a user who may view it.
 *
 * UserManager::verifyPermission() caches the permission set in a static, so every
 * test runs in its own process.
 */
class ZoneNameVisibilityTest extends SqliteIntegrationTestCase
{
    private const USER_ID = 100;
    private const PERM_TEMPL_ID = 100;
    private const ZONE_ID = 12;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, zone_templ_id INTEGER NOT NULL DEFAULT 0)");
        $this->db->exec("CREATE TABLE zones_groups (id INTEGER PRIMARY KEY, domain_id INTEGER NOT NULL, group_id INTEGER NOT NULL, created_at TEXT)");
        $this->db->exec("CREATE TABLE zone_templ (id INTEGER PRIMARY KEY, name TEXT)");
        $this->db->exec("INSERT INTO perm_templ (id, name) VALUES (" . self::PERM_TEMPL_ID . ", 'Tester')");
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (" . self::USER_ID . ", 'tester', " . self::PERM_TEMPL_ID . ")");

        $_SESSION['userid'] = self::USER_ID;
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    #[RunInSeparateProcess]
    public function testTheUnlinkConfirmationDropsAZoneTheUserDoesNotOwn(): void
    {
        $this->grant(['zone_content_edit_own']);

        $this->assertSame('No valid zones selected.', $this->unlinkConfirmationError());
    }

    #[RunInSeparateProcess]
    public function testTheUnlinkConfirmationKeepsAnOwnedZone(): void
    {
        $this->grant(['zone_content_edit_own']);
        $this->own(self::ZONE_ID);

        // Past the zone filter, the next step looks the template up
        $this->assertSame('Template not found.', $this->unlinkConfirmationError());
    }

    #[RunInSeparateProcess]
    public function testTheUnlinkConfirmationKeepsAnyZoneForAnAdmin(): void
    {
        $_SESSION['userid'] = self::ADMIN_USER_ID;

        $this->assertSame('Template not found.', $this->unlinkConfirmationError());
    }

    /** @return array<string, array{class-string, string}> */
    public static function lookupPages(): array
    {
        return [
            'whois, id' => [WhoisController::class, 'id'],
            'whois, zone_id' => [WhoisController::class, 'zone_id'],
            'rdap, id' => [RdapController::class, 'id'],
            'rdap, zone_id' => [RdapController::class, 'zone_id'],
        ];
    }

    /**
     * @param class-string $controllerClass
     */
    #[DataProvider('lookupPages')]
    #[RunInSeparateProcess]
    public function testALookupPageDoesNotNameAZoneAnOwnViewerDoesNotOwn(string $controllerClass, string $param): void
    {
        $this->grant(['zone_content_view_own']);

        $this->assertSame('', $this->lookupDomain($controllerClass, $param));
    }

    /**
     * @param class-string $controllerClass
     */
    #[DataProvider('lookupPages')]
    #[RunInSeparateProcess]
    public function testALookupPageDoesNotNameAZoneToAnOwnerWithoutViewPermission(string $controllerClass, string $param): void
    {
        $this->own(self::ZONE_ID);

        $this->assertSame('', $this->lookupDomain($controllerClass, $param));
    }

    /**
     * @param class-string $controllerClass
     */
    #[DataProvider('lookupPages')]
    #[RunInSeparateProcess]
    public function testALookupPageNamesAnOwnedZone(string $controllerClass, string $param): void
    {
        $this->grant(['zone_content_view_own']);
        $this->own(self::ZONE_ID);

        $this->assertSame('example.com', $this->lookupDomain($controllerClass, $param));
    }

    /**
     * @param class-string $controllerClass
     */
    #[DataProvider('lookupPages')]
    #[RunInSeparateProcess]
    public function testALookupPageNamesAnyZoneToAnAllViewer(string $controllerClass, string $param): void
    {
        $this->grant(['zone_content_view_others']);

        $this->assertSame('example.com', $this->lookupDomain($controllerClass, $param));
    }

    /**
     * @param string[] $permissions
     */
    private function grant(array $permissions): void
    {
        foreach ($permissions as $permission) {
            $this->db->prepare("INSERT INTO perm_items (name) VALUES (?)")->execute([$permission]);
            $this->db->prepare("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (?, ?)")
                ->execute([self::PERM_TEMPL_ID, $this->db->lastInsertId()]);
        }
    }

    private function own(int $zoneId): void
    {
        $this->db->prepare("INSERT INTO zones (domain_id, owner) VALUES (?, ?)")->execute([$zoneId, self::USER_ID]);
    }

    private function unlinkConfirmationError(): string
    {
        $controller = new class ([], $this->db, $this->primeConfig()) extends UnlinkZonesTemplController {
            use ControllerTestStubs;
        };

        try {
            (new ReflectionMethod(UnlinkZonesTemplController::class, 'showConfirmation'))->invoke($controller, [(string)self::ZONE_ID], 3);
        } catch (RuntimeException $halt) {
            return $halt->getMessage();
        }

        $this->fail('Expected the request to end in an error page.');
    }

    /**
     * @param class-string $controllerClass
     */
    private function lookupDomain(string $controllerClass, string $param): string
    {
        $request = [$param => (string)self::ZONE_ID];
        $config = $this->primeConfig();
        $controller = $controllerClass === WhoisController::class
            ? new class ($request, $this->db, $config) extends WhoisController {
                use ControllerTestStubs;
            }
            : new class ($request, $this->db, $config) extends RdapController {
                use ControllerTestStubs;
            };

        $dnsRecord = $this->createMock(DnsRecord::class);
        $dnsRecord->method('getDomainNameById')->willReturn('example.com');
        (new ReflectionProperty($controllerClass, 'dnsRecord'))->setValue($controller, $dnsRecord);

        return (new ReflectionMethod($controllerClass, 'handleDomainInput'))->invoke($controller);
    }

    private function primeConfig(): ConfigurationManager
    {
        $config = ConfigurationManager::getInstance();
        $reflection = new ReflectionClass(ConfigurationManager::class);
        $reflection->getProperty('settings')->setValue($config, [
            'database' => ['type' => 'sqlite', 'pdns_db_name' => ''],
        ]);
        $reflection->getProperty('initialized')->setValue($config, true);

        return $config;
    }
}
