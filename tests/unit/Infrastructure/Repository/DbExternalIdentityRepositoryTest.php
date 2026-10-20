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

namespace Poweradmin\Tests\Unit\Infrastructure\Repository;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Infrastructure\Repository\DbExternalIdentityRepository;

#[CoversClass(DbExternalIdentityRepository::class)]
class DbExternalIdentityRepositoryTest extends TestCase
{
    private PDO $db;
    private DbExternalIdentityRepository $repository;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        foreach (
            [
                "CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT)",
                "CREATE TABLE oidc_user_links (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, provider_id TEXT NOT NULL,
                    oidc_subject TEXT NOT NULL, username TEXT NOT NULL, email TEXT, created_at TEXT, updated_at TEXT)",
                "CREATE TABLE saml_user_links (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, provider_id TEXT NOT NULL,
                    saml_subject TEXT NOT NULL, username TEXT NOT NULL, email TEXT, created_at TEXT, updated_at TEXT)",
                "INSERT INTO users VALUES (1, 'alice'), (2, 'bob')",
            ] as $sql
        ) {
            $this->db->exec($sql);
        }
        $this->repository = new DbExternalIdentityRepository($this->db);
    }

    public function testLinkOidcInsertsThenRefreshesTheSameRow(): void
    {
        $this->repository->linkOidc(1, 'okta', 'sub-1', 'alice', 'alice@example.org');
        $this->repository->linkOidc(1, 'okta', 'sub-1b', 'alice.new', 'new@example.org');

        $rows = $this->db->query('SELECT * FROM oidc_user_links')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows);
        $this->assertSame('sub-1b', $rows[0]['oidc_subject']);
        $this->assertSame('alice.new', $rows[0]['username']);
        $this->assertSame('new@example.org', $rows[0]['email']);
        $this->assertNotNull($rows[0]['created_at']);
    }

    public function testLinkSamlInsertsThenRefreshesTheSameRow(): void
    {
        $this->repository->linkSaml(1, 'adfs', 'name-1', 'alice', 'alice@example.org');
        $this->repository->linkSaml(1, 'adfs', 'name-1b', 'alice', 'alice@example.org');
        $this->repository->linkSaml(1, 'other', 'name-2', 'alice', 'alice@example.org');

        $this->assertSame(2, (int)$this->db->query('SELECT COUNT(*) FROM saml_user_links')->fetchColumn());
        $this->assertSame('name-1b', $this->db->query("SELECT saml_subject FROM saml_user_links WHERE provider_id = 'adfs'")->fetchColumn());
    }

    public function testSubjectLookupsAreScopedToTheProvider(): void
    {
        $this->repository->linkOidc(1, 'okta', 'shared', 'alice', 'a@x');
        $this->repository->linkSaml(2, 'adfs', 'shared', 'bob', 'b@x');

        $this->assertSame(1, $this->repository->findUserIdByOidcSubject('shared', 'okta'));
        $this->assertNull($this->repository->findUserIdByOidcSubject('shared', 'adfs'));
        $this->assertNull($this->repository->findUserIdByOidcSubject('other', 'okta'));
        $this->assertSame(2, $this->repository->findUserIdBySamlSubject('shared', 'adfs'));
        $this->assertNull($this->repository->findUserIdBySamlSubject('shared', 'okta'));
    }

    public function testOrphanedLinksAreFoundAndDeletedWithoutTouchingLiveOnes(): void
    {
        $this->repository->linkOidc(1, 'okta', 'sub-1', 'alice', 'a@x');
        $this->repository->linkOidc(2, 'okta', 'sub-2', 'bob', 'b@x');
        $this->repository->linkSaml(2, 'adfs', 'name-2', 'bob', 'b@x');
        $this->db->exec('DELETE FROM users WHERE id = 2');

        $orphans = $this->repository->findOrphanedOidcLinks();
        $this->assertCount(1, $orphans);
        $this->assertSame(['id', 'user_id', 'provider_id', 'username'], array_keys($orphans[0]));
        $this->assertSame('bob', $orphans[0]['username']);

        $this->assertSame([2], $this->repository->findOrphanedOidcLinkIds('sub-2', 'okta'));
        $this->assertSame([], $this->repository->findOrphanedOidcLinkIds('sub-1', 'okta'));
        $this->assertSame([1], $this->repository->findOrphanedSamlLinkIds('name-2', 'adfs'));

        $this->assertSame(1, $this->repository->deleteOrphanedOidcLinks());
        $this->assertSame(1, $this->repository->findUserIdByOidcSubject('sub-1', 'okta'));
        $this->assertSame(0, $this->repository->deleteOrphanedOidcLinks());

        $this->repository->deleteSamlLinks([1]);
        $this->assertNull($this->repository->findUserIdBySamlSubject('name-2', 'adfs'));
    }

    public function testDeleteByIdsIgnoresAnEmptyList(): void
    {
        $this->repository->linkOidc(1, 'okta', 'sub-1', 'alice', 'a@x');

        $this->repository->deleteOidcLinks([]);
        $this->repository->deleteSamlLinks([]);

        $this->assertSame(1, $this->repository->findUserIdByOidcSubject('sub-1', 'okta'));
    }
}
