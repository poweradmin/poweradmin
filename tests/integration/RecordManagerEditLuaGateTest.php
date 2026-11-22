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

namespace Poweradmin\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Poweradmin\Application\Service\Backend\RepositoryFactory;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Port\DnsBackendProviderInterface;
use Poweradmin\Domain\Port\DnssecProviderInterface;
use Poweradmin\Domain\Repository\DomainRepositoryInterface;
use Poweradmin\Domain\Service\Dns\DnsRecordValidationServiceInterface;
use Poweradmin\Domain\Service\Dns\RecordManager;
use Poweradmin\Domain\Service\Dns\SOARecordManagerInterface;
use Poweradmin\Domain\Service\Validation\Refusal;
use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Infrastructure\Database\PdoTransaction;
use Poweradmin\Infrastructure\Logger\RecordChangeLogger;
use Poweradmin\Infrastructure\Repository\DbTemplateRecordLinkRepository;
use Poweradmin\Infrastructure\Session\ArraySession;
use Poweradmin\Infrastructure\Session\SessionActor;
use TestHelpers\SqliteIntegrationTestCase;

/**
 * A zone owner limited to zone_content_edit_own_as_client may neither change a
 * stored LUA record (content or name) nor turn another record into one, since
 * LUA content runs on the DNS server. Both the stored and the posted state are gated.
 */
class RecordManagerEditLuaGateTest extends SqliteIntegrationTestCase
{
    private const CLIENT_USER_ID = 100;
    private const CLIENT_PERM_TEMPL_ID = 100;
    private const ZONE_ID = 2;
    private const LUA_RECORD_ID = 5;
    private const A_RECORD_ID = 6;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createZoneTables();
        $this->db->exec("CREATE TABLE records (id INTEGER PRIMARY KEY, domain_id INTEGER, name TEXT, type TEXT, content TEXT, ttl INTEGER, prio INTEGER, disabled INTEGER DEFAULT 0)");
        $this->db->exec("INSERT INTO zones (id, domain_id, owner) VALUES (1, " . self::ZONE_ID . ", " . self::CLIENT_USER_ID . ")");
        $this->db->exec("INSERT INTO records (id, domain_id, name, type, content, ttl, prio, disabled) VALUES (" . self::LUA_RECORD_ID . ", " . self::ZONE_ID . ", 'lua.client.example', 'LUA', 'A \"return ifportup(80)\"', 3600, 0, 0)");
        $this->db->exec("INSERT INTO records (id, domain_id, name, type, content, ttl, prio, disabled) VALUES (" . self::A_RECORD_ID . ", " . self::ZONE_ID . ", 'www.client.example', 'A', '192.0.2.1', 3600, 0, 0)");

        $this->db->exec("INSERT INTO perm_items (id, name) VALUES (60, '" . Permission::PERM_ZONE_CONTENT_EDIT_OWN_AS_CLIENT . "')");
        $this->db->exec("INSERT INTO perm_templ (id, name) VALUES (" . self::CLIENT_PERM_TEMPL_ID . ", 'Client')");
        $this->db->exec("INSERT INTO perm_templ_items (templ_id, perm_id) VALUES (" . self::CLIENT_PERM_TEMPL_ID . ", 60)");
        $this->db->exec("INSERT INTO users (id, username, perm_templ) VALUES (" . self::CLIENT_USER_ID . ", 'client', " . self::CLIENT_PERM_TEMPL_ID . ")");
    }

    /**
     * @return array<string, array{int, array<string, mixed>, string}> record id, posted fields, refused type
     */
    public static function luaEditProvider(): array
    {
        return [
            'content of a stored LUA record' => [self::LUA_RECORD_ID, ['name' => 'lua.client.example', 'type' => 'LUA', 'content' => 'A "return \'192.0.2.9\'"'], 'LUA'],
            'rename of a stored LUA record' => [self::LUA_RECORD_ID, ['name' => 'moved.client.example', 'type' => 'LUA', 'content' => 'A "return ifportup(80)"'], 'LUA'],
            'another type turned into LUA' => [self::A_RECORD_ID, ['name' => 'www.client.example', 'type' => 'LUA', 'content' => 'A "return \'192.0.2.9\'"'], 'LUA'],
            'LUA turned into another type' => [self::LUA_RECORD_ID, ['name' => 'lua.client.example', 'type' => 'A', 'content' => '192.0.2.9'], 'LUA'],
        ];
    }

    /**
     * @param array<string, mixed> $posted
     */
    #[RunInSeparateProcess]
    #[DataProvider('luaEditProvider')]
    public function testAnOwnAsClientEditorCannotTouchOrCreateALuaRecord(int $recordId, array $posted, string $refusedType): void
    {
        $this->session->set('userid', self::CLIENT_USER_ID);
        $backend = $this->dnsBackendStub(false);
        $backend->expects($this->never())->method('editRecord');

        $result = $this->makeRecordManager($backend)->editRecord($posted + [
            'rid' => $recordId,
            'zid' => self::ZONE_ID,
            'ttl' => 3600,
            'prio' => 0,
            'disabled' => 0,
        ]);

        $this->assertFalse($result->success);
        $this->assertSame(Refusal::FORBIDDEN, $result->refusal);
        $this->assertSame(Permission::restrictedRecordTypeMessage($refusedType, 'edit'), $result->message);
    }

    private function makeRecordManager(DnsBackendProviderInterface $backend): RecordManager
    {
        $config = $this->sqliteConfiguration(['dns' => ['hostmaster' => 'hostmaster.example', 'ttl' => 3600]]);

        // Validation passes, so only the record-type gate stands between the request and the backend
        $validation = $this->createMock(DnsRecordValidationServiceInterface::class);
        $validation->method('validateRecord')->willReturn(ValidationResult::success([
            'content' => '192.0.2.9',
            'name' => 'www.client.example',
            'ttl' => 3600,
            'prio' => 0,
        ]));
        $domainRepository = $this->createMock(DomainRepositoryInterface::class);
        $domainRepository->method('getDomainType')->willReturn('MASTER');
        $domainRepository->method('getDomainNameById')->willReturn('client.example');

        return new RecordManager(
            new PdoTransaction($this->db),
            $config,
            $validation,
            $this->createMock(SOARecordManagerInterface::class),
            $domainRepository,
            new RepositoryFactory($this->db, $config, $backend, new ArraySession()),
            fn() => $this->createMock(DnssecProviderInterface::class),
            $backend,
            $this->permissionService($config),
            $this->createMock(RecordChangeLogger::class),
            new DbTemplateRecordLinkRepository($this->db, $config, $backend),
            new SessionActor($this->session)
        );
    }
}
