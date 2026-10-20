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


namespace TestHelpers;

use PDO;
use Poweradmin\Domain\Config\ConfigurationInterface;

/**
 * A scratch domains/records/comments schema on one engine, with the production table
 * names, so the real repositories run against it. MySQL and PostgreSQL get their own
 * database or schema and never touch the devcontainer's PowerDNS tables.
 */
final class RecordCommentFixture
{
    private const SCRATCH_PREFIX = 'poweradmin_it_record_comments_';

    /** Per-process name, so concurrent runs cannot drop each other's schema. */
    private static function scratch(): string
    {
        return self::SCRATCH_PREFIX . getmypid();
    }

    /** @return array<string, array{string}> */
    public static function engines(): array
    {
        return ['sqlite' => ['sqlite'], 'mysql' => ['mysql'], 'pgsql' => ['pgsql']];
    }

    /** Throws PDOException when the engine cannot be reached; nothing else is caught. */
    public static function connect(string $engine): PDO
    {
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];

        return match ($engine) {
            'mysql' => new PDO('mysql:host=127.0.0.1;port=3306', 'root', 'uberuser', $options),
            'pgsql' => new PDO('pgsql:host=127.0.0.1;port=5432;dbname=pdns', 'pdns', 'poweradmin', $options),
            default => new PDO('sqlite::memory:', null, null, $options),
        };
    }

    public static function config(string $engine): ConfigurationInterface
    {
        return new FakeConfiguration(['database' => ['type' => $engine, 'pdns_db_name' => '']]);
    }

    public static function createSchema(PDO $db, string $engine): void
    {
        self::drop($db, $engine);
        if ($engine === 'mysql') {
            $db->exec('CREATE DATABASE ' . self::scratch());
            $db->exec('USE ' . self::scratch());
        } elseif ($engine === 'pgsql') {
            $db->exec('CREATE SCHEMA ' . self::scratch());
            $db->exec('SET search_path TO ' . self::scratch());
        }

        [$id, $int, $text, $flag] = match ($engine) {
            'mysql' => ['INT AUTO_INCREMENT PRIMARY KEY', 'INT', 'VARCHAR(255)', 'TINYINT(1) DEFAULT 0'],
            'pgsql' => ['SERIAL PRIMARY KEY', 'INT', 'VARCHAR(255)', "BOOL DEFAULT 'f'"],
            default => ['INTEGER PRIMARY KEY AUTOINCREMENT', 'INTEGER', 'TEXT', 'BOOLEAN DEFAULT 0'],
        };
        $linkRecordId = $engine === 'mysql' ? 'VARCHAR(2048) CHARACTER SET ascii NOT NULL' : 'VARCHAR(2048) NOT NULL';

        $db->exec("CREATE TABLE domains (id $id, name $text NOT NULL, type VARCHAR(8) DEFAULT 'NATIVE')");
        $db->exec("CREATE TABLE records (id $id, domain_id $int NOT NULL, name $text, type VARCHAR(10), content TEXT,
            ttl $int DEFAULT 3600, prio $int DEFAULT 0, disabled $flag, auth $flag, ordername $text, change_date $int)");
        $db->exec("CREATE TABLE comments (id $id, domain_id $int NOT NULL, name $text NOT NULL, type VARCHAR(10) NOT NULL,
            modified_at $int NOT NULL DEFAULT 0, account VARCHAR(40) DEFAULT '', comment TEXT NOT NULL)");
        $db->exec("CREATE TABLE record_comment_links (id $id, record_id $linkRecordId, comment_id $int NOT NULL)");
        $db->exec("CREATE TABLE users (id $id, fullname $text)");
        $db->exec("CREATE TABLE zones (id $id, domain_id $int, owner $int)");

        $db->exec("INSERT INTO domains (id, name) VALUES (1, 'example.com')");
    }

    public static function drop(PDO $db, string $engine): void
    {
        if ($engine === 'mysql') {
            $db->exec('DROP DATABASE IF EXISTS ' . self::scratch());
        } elseif ($engine === 'pgsql') {
            $db->exec('DROP SCHEMA IF EXISTS ' . self::scratch() . ' CASCADE');
        }
    }

    public static function addRecord(PDO $db, int $id, string $name, string $type, string $content, int $ttl = 3600, int $prio = 0): void
    {
        $db->prepare('INSERT INTO records (id, domain_id, name, type, content, ttl, prio) VALUES (?, 1, ?, ?, ?, ?, ?)')
            ->execute([$id, $name, $type, $content, $ttl, $prio]);
    }

    public static function addComment(PDO $db, int $id, string $name, string $type, string $comment): void
    {
        $db->prepare('INSERT INTO comments (id, domain_id, name, type, comment) VALUES (?, 1, ?, ?, ?)')
            ->execute([$id, $name, $type, $comment]);
    }

    public static function link(PDO $db, int $recordId, int $commentId): void
    {
        $db->prepare('INSERT INTO record_comment_links (record_id, comment_id) VALUES (?, ?)')
            ->execute([(string)$recordId, $commentId]);
    }
}
