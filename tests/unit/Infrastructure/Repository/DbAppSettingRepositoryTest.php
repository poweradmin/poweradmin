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
use Poweradmin\Infrastructure\Repository\DbAppSettingRepository;

#[CoversClass(DbAppSettingRepository::class)]
class DbAppSettingRepositoryTest extends TestCase
{
    private PDO $db;
    private DbAppSettingRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec(<<<SQL
            CREATE TABLE app_settings (
                setting_key text NOT NULL PRIMARY KEY,
                setting_value text NOT NULL,
                value_type text NOT NULL DEFAULT 'string',
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        SQL);
        $this->repository = new DbAppSettingRepository($this->db);
    }

    public function testFindReturnsNullWhenNoRow(): void
    {
        $this->assertNull($this->repository->find('interface.theme'));
    }

    public function testSaveInsertsNewRow(): void
    {
        $this->repository->save('interface.theme', 'dark', 'string');
        $this->assertSame(
            ['value' => 'dark', 'type' => 'string'],
            $this->repository->find('interface.theme')
        );
    }

    public function testSaveUpdatesExistingRow(): void
    {
        $this->repository->save('dns.ttl', '3600', 'int');
        $this->repository->save('dns.ttl', '86400', 'int');
        $this->assertSame(
            ['value' => '86400', 'type' => 'int'],
            $this->repository->find('dns.ttl')
        );
    }

    public function testFindAllReturnsAllRowsOrderedByKey(): void
    {
        $this->repository->save('dns.ttl', '86400', 'int');
        $this->repository->save('app.debug', 'true', 'bool');
        $this->repository->save('interface.theme', 'dark', 'string');

        $this->assertSame(
            ['app.debug', 'dns.ttl', 'interface.theme'],
            array_keys($this->repository->findAll())
        );
    }

    public function testFindByPrefixReturnsOnlyMatchingRows(): void
    {
        $this->repository->save('dns.ttl', '86400', 'int');
        $this->repository->save('dns.ttl_reverse', '300', 'int');
        $this->repository->save('interface.theme', 'dark', 'string');

        $this->assertSame(
            ['dns.ttl', 'dns.ttl_reverse'],
            array_keys($this->repository->findByPrefix('dns.'))
        );
    }

    public function testFindByPrefixEscapesLikeWildcards(): void
    {
        // Two adjacent keys; the underscore in the prefix must not match the dot.
        $this->repository->save('dns.ttl', '86400', 'int');
        $this->repository->save('dnsxttl', '60', 'int');

        $this->assertSame(['dnsxttl'], array_keys($this->repository->findByPrefix('dnsxttl')));
    }

    public function testFindByPrefixTreatsUnderscoreLiterally(): void
    {
        // 'foo_bar' should match foo_bar.* but NOT fooXbar.* (where X is any char).
        $this->repository->save('foo_bar.alpha', '1', 'int');
        $this->repository->save('fooXbar.beta', '2', 'int');

        $this->assertSame(
            ['foo_bar.alpha'],
            array_keys($this->repository->findByPrefix('foo_bar'))
        );
    }

    public function testDeleteRemovesRow(): void
    {
        $this->repository->save('dns.ttl', '86400', 'int');
        $this->repository->delete('dns.ttl');
        $this->assertNull($this->repository->find('dns.ttl'));
    }

    public function testDeleteIsIdempotent(): void
    {
        $this->repository->delete('nonexistent.key');
        $this->assertNull($this->repository->find('nonexistent.key'));
    }

    public function testIsReadyReturnsTrueWhenTableExists(): void
    {
        $this->assertTrue($this->repository->isReady());
    }

    public function testIsReadyReturnsFalseWhenTableMissing(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $repository = new DbAppSettingRepository($db);
        $this->assertFalse($repository->isReady());
    }

    public function testFindReturnsNullWhenTableMissing(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $repository = new DbAppSettingRepository($db);
        $this->assertNull($repository->find('any.key'));
    }

    public function testFindAllReturnsEmptyArrayWhenTableMissing(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $repository = new DbAppSettingRepository($db);
        $this->assertSame([], $repository->findAll());
    }

    public function testFindByPrefixReturnsEmptyArrayWhenTableMissing(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $repository = new DbAppSettingRepository($db);
        $this->assertSame([], $repository->findByPrefix('dns.'));
    }

    public function testSaveIsNoopWhenTableMissing(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $repository = new DbAppSettingRepository($db);
        $repository->save('dns.ttl', '86400', 'int');
        $this->assertNull($repository->find('dns.ttl'));
    }

    public function testDeleteIsNoopWhenTableMissing(): void
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $repository = new DbAppSettingRepository($db);
        $repository->delete('dns.ttl');
        $this->assertNull($repository->find('dns.ttl'));
    }

    public function testKeyLookupIsCaseSensitive(): void
    {
        $this->repository->save('Interface.Theme', 'dark', 'string');
        $this->assertNull($this->repository->find('interface.theme'));
        $this->assertNotNull($this->repository->find('Interface.Theme'));
    }
}
