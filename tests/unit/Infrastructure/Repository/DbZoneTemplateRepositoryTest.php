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

use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Infrastructure\Repository\DbZoneTemplateRepository;

/**
 * The SQL behind zone templates, run against a real (in-memory) database so the
 * statements are exercised rather than asserted on as strings.
 */
#[CoversClass(DbZoneTemplateRepository::class)]
class DbZoneTemplateRepositoryTest extends TestCase
{
    private PDO $db;
    private DbZoneTemplateRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->db->exec("CREATE TABLE zone_templ (id INTEGER PRIMARY KEY, name TEXT, descr TEXT, owner INTEGER, created_by INTEGER, is_default INTEGER DEFAULT 0)");
        $this->db->exec("CREATE TABLE zone_templ_records (id INTEGER PRIMARY KEY, zone_templ_id INTEGER, name TEXT, type TEXT, content TEXT, ttl INTEGER, prio INTEGER)");
        $this->db->exec("CREATE TABLE zones (id INTEGER PRIMARY KEY, domain_id INTEGER, owner INTEGER, comment TEXT, zone_templ_id INTEGER DEFAULT 0)");
        $this->db->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, fullname TEXT)");
        $this->db->exec("CREATE TABLE records_zone_templ (domain_id INTEGER, record_id INTEGER, zone_templ_id INTEGER)");
        $this->db->exec("CREATE TABLE records_zone_templ_api (domain_id INTEGER, record_id INTEGER, zone_templ_id INTEGER)");

        $this->repository = new DbZoneTemplateRepository($this->db, $this->makeConfig());
    }

    private function makeConfig(int $ttl = 3600): ConfigurationInterface
    {
        $config = $this->createMock(ConfigurationInterface::class);
        $config->method('get')->willReturnCallback(
            function (string $section, string $key, $default = null) use ($ttl) {
                if ($section === 'database' && $key === 'type') {
                    return 'sqlite';
                }
                if ($section === 'dns' && $key === 'ttl') {
                    return $ttl;
                }
                return $default;
            }
        );

        return $config;
    }

    public function testCreateZoneTemplateStampsADefaultSoaRecord(): void
    {
        $id = $this->repository->createZoneTemplate('base', 'a base template', 0, 7);

        $this->assertGreaterThan(0, $id);
        $details = $this->repository->getZoneTemplateDetails($id);
        $this->assertIsArray($details);
        $this->assertSame('base', $details['name']);
        $this->assertSame(0, (int)$details['owner']);
        $this->assertSame(7, (int)$details['created_by']);

        $records = $this->repository->getZoneTemplateRecords($id);
        $this->assertCount(1, $records);
        $this->assertSame('SOA', $records[0]['type']);
        $this->assertSame('[ZONE]', $records[0]['name']);
        $this->assertSame(3600, (int)$records[0]['ttl']);
    }

    public function testCreateZoneTemplateWithRecordsKeepsTheSuppliedSoa(): void
    {
        $id = $this->repository->createZoneTemplateWithRecords('saved', '', 5, 5, [
            ['name' => '[ZONE]', 'type' => 'SOA', 'content' => 'ns [HOSTMASTER] [SERIAL] 1 2 3 4', 'ttl' => 60],
            ['name' => 'www.[ZONE]', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 120, 'prio' => 0],
        ]);

        $records = $this->repository->getZoneTemplateRecords($id);
        $this->assertCount(2, $records, 'No extra SOA should be appended.');
        $this->assertSame(2, $this->repository->countZoneTemplateRecords($id));
    }

    public function testCreateZoneTemplateWithRecordsAppendsAnSoaWhenMissing(): void
    {
        $id = $this->repository->createZoneTemplateWithRecords('saved', '', 5, 5, [
            ['name' => 'www.[ZONE]', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 120],
        ]);

        $types = array_column($this->repository->getZoneTemplateRecords($id), 'type');
        sort($types);
        $this->assertSame(['A', 'SOA'], $types);
    }

    public function testCreateZoneTemplateWithRecordsRollsBackOnFailure(): void
    {
        // With the records table gone the record insert fails after the template row
        // was written, which is exactly the window the transaction has to cover.
        $this->db->exec("DROP TABLE zone_templ_records");

        $this->expectException(\PDOException::class);

        try {
            $this->repository->createZoneTemplateWithRecords('broken', '', 1, 1, [
                ['name' => 'www', 'type' => 'A', 'content' => '192.0.2.1', 'ttl' => 60],
            ]);
        } finally {
            $this->assertSame(
                0,
                (int)$this->db->query("SELECT COUNT(*) FROM zone_templ")->fetchColumn(),
                'The template row must not survive a failed record insert.'
            );
        }
    }

    public function testRecordCrudRoundTrip(): void
    {
        $templateId = $this->repository->createZoneTemplate('crud', '', 0, 1);

        $recordId = $this->repository->addRecord($templateId, 'mail.[ZONE]', 'MX', 'mx.[ZONE]', 300, 10);
        $this->assertGreaterThan(0, $recordId);

        $record = $this->repository->getZoneTemplateRecordById($recordId);
        $this->assertSame('MX', $record['type']);
        $this->assertSame(10, (int)$record['prio']);
        $this->assertSame($templateId, (int)$record['zone_templ_id']);

        $this->assertTrue($this->repository->updateRecord($recordId, 'mail.[ZONE]', 'MX', 'mx2.[ZONE]', 600, 20));
        $record = $this->repository->getZoneTemplateRecordById($recordId);
        $this->assertSame('mx2.[ZONE]', $record['content']);
        $this->assertSame(600, (int)$record['ttl']);

        $this->assertTrue($this->repository->deleteRecord($recordId));
        $this->assertSame([], $this->repository->getZoneTemplateRecordById($recordId));
        $this->assertSame(1, $this->repository->countZoneTemplateRecords($templateId));
    }

    public function testGetZoneTemplateRecordByIdScopesToTheTemplate(): void
    {
        $mine = $this->repository->createZoneTemplate('mine', '', 1, 1);
        $theirs = $this->repository->createZoneTemplate('theirs', '', 2, 2);
        $recordId = $this->repository->addRecord($theirs, 'www', 'A', '192.0.2.1', 60, 0);

        $this->assertNotSame([], $this->repository->getZoneTemplateRecordById($recordId, $theirs));
        $this->assertSame(
            [],
            $this->repository->getZoneTemplateRecordById($recordId, $mine),
            'A record id from another template must not be readable.'
        );
    }

    public function testGetZoneTemplateRecordsSortsAndPaginates(): void
    {
        $templateId = $this->repository->createZoneTemplate('sorted', '', 0, 1);
        foreach (['ccc', 'aaa', 'bbb'] as $name) {
            $this->repository->addRecord($templateId, $name, 'A', '192.0.2.1', 60, 0);
        }

        $all = array_column($this->repository->getZoneTemplateRecords($templateId), 'name');
        $this->assertSame(['[ZONE]', 'aaa', 'bbb', 'ccc'], $all);

        $page = array_column($this->repository->getZoneTemplateRecords($templateId, 1, 2), 'name');
        $this->assertSame(['aaa', 'bbb'], $page);
    }

    public function testGetZoneTemplateRecordsIgnoresAnUnknownSortColumn(): void
    {
        $templateId = $this->repository->createZoneTemplate('sorted', '', 0, 1);
        $this->repository->addRecord($templateId, 'aaa', 'A', '192.0.2.1', 60, 0);

        $names = array_column($this->repository->getZoneTemplateRecords($templateId, 0, 9999, 'id; DROP TABLE zone_templ'), 'name');
        $this->assertSame(['[ZONE]', 'aaa'], $names);
    }

    public function testDeleteZoneTemplateRemovesRecordsAndUnlinksZones(): void
    {
        $templateId = $this->repository->createZoneTemplate('gone', '', 0, 1);
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_templ_id) VALUES (1, 11, 3, $templateId)");
        $this->db->exec("INSERT INTO records_zone_templ (domain_id, record_id, zone_templ_id) VALUES (11, 1, $templateId)");
        $this->db->exec("INSERT INTO records_zone_templ_api (domain_id, record_id, zone_templ_id) VALUES (11, 1, $templateId)");

        $this->assertTrue($this->repository->deleteZoneTemplate($templateId));

        $this->assertFalse($this->repository->getZoneTemplateDetails($templateId));
        $this->assertSame(0, $this->repository->countZoneTemplateRecords($templateId));
        $this->assertSame(0, (int)$this->db->query("SELECT zone_templ_id FROM zones WHERE id = 1")->fetchColumn());
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM records_zone_templ")->fetchColumn());
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM records_zone_templ_api")->fetchColumn());
    }

    public function testDeleteZoneTemplatesOwnedByLeavesOtherOwnersAlone(): void
    {
        $mine = $this->repository->createZoneTemplate('mine', '', 4, 4);
        $theirs = $this->repository->createZoneTemplate('theirs', '', 9, 9);
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_templ_id) VALUES (1, 11, 4, $mine)");

        $this->repository->deleteZoneTemplatesOwnedBy(4);

        $this->assertFalse($this->repository->getZoneTemplateDetails($mine));
        $this->assertIsArray($this->repository->getZoneTemplateDetails($theirs));
        $this->assertSame(0, $this->repository->countZoneTemplateRecords($mine));
        $this->assertSame(1, $this->repository->countZoneTemplateRecords($theirs));
        $this->assertSame(0, (int)$this->db->query("SELECT zone_templ_id FROM zones WHERE id = 1")->fetchColumn());
    }

    public function testUpdateZoneTemplateClearsTheDefaultFlagForAPrivateOwner(): void
    {
        $templateId = $this->repository->createZoneTemplate('global', '', 0, 1);
        $this->repository->flagDefaultTemplate($templateId);
        $this->assertSame($templateId, $this->repository->findFlaggedDefaultTemplateId());

        $this->repository->updateZoneTemplate($templateId, 'private', 'now mine', 5);

        $details = $this->repository->getZoneTemplateDetails($templateId);
        $this->assertSame('private', $details['name']);
        $this->assertSame('now mine', $details['descr']);
        $this->assertSame(5, (int)$details['owner']);
        $this->assertSame(0, (int)$details['is_default']);
    }

    public function testUpdateZoneTemplateKeepsTheDefaultFlagForAGlobalOwner(): void
    {
        $templateId = $this->repository->createZoneTemplate('global', '', 0, 1);
        $this->repository->flagDefaultTemplate($templateId);

        $this->repository->updateZoneTemplate($templateId, 'renamed', '', 0);

        $this->assertSame($templateId, $this->repository->findFlaggedDefaultTemplateId());
    }

    public function testUpdateZoneTemplateWithoutAnOwnerLeavesItUntouched(): void
    {
        $templateId = $this->repository->createZoneTemplate('global', '', 3, 3);

        $this->repository->updateZoneTemplate($templateId, 'renamed', '');

        $this->assertSame(3, $this->repository->getOwner($templateId));
    }

    public function testFlagDefaultTemplateClearsEveryOtherGlobalTemplate(): void
    {
        $first = $this->repository->createZoneTemplate('first', '', 0, 1);
        $second = $this->repository->createZoneTemplate('second', '', 0, 1);

        $this->repository->flagDefaultTemplate($first);
        $this->repository->flagDefaultTemplate($second);

        $this->assertSame($second, $this->repository->findFlaggedDefaultTemplateId());
        $this->assertSame(0, (int)$this->repository->getZoneTemplateDetails($first)['is_default']);
    }

    public function testClearDefaultTemplateRemovesTheFlag(): void
    {
        $templateId = $this->repository->createZoneTemplate('first', '', 0, 1);
        $this->repository->flagDefaultTemplate($templateId);

        $this->repository->clearDefaultTemplate();

        $this->assertNull($this->repository->findFlaggedDefaultTemplateId());
    }

    public function testFlaggedDefaultIgnoresPrivateTemplates(): void
    {
        $private = $this->repository->createZoneTemplate('private', '', 5, 5);
        $this->db->exec("UPDATE zone_templ SET is_default = 1 WHERE id = $private");

        $this->assertNull($this->repository->findFlaggedDefaultTemplateId());
    }

    public function testGlobalTemplateLookups(): void
    {
        $global = $this->repository->createZoneTemplate('shared', '', 0, 1);
        $private = $this->repository->createZoneTemplate('shared', '', 5, 5);

        $this->assertTrue($this->repository->globalTemplateExists($global));
        $this->assertFalse($this->repository->globalTemplateExists($private));
        $this->assertFalse($this->repository->globalTemplateExists(999));

        $this->assertSame([$global], $this->repository->findGlobalTemplateIdsByName('shared'));
        $this->assertSame([], $this->repository->findGlobalTemplateIdsByName('missing'));

        $ids = $this->repository->findTemplateIdsByName('shared');
        sort($ids);
        $this->assertSame([$global, $private], $ids);
    }

    public function testNameExistenceChecks(): void
    {
        $templateId = $this->repository->createZoneTemplate('taken', '', 0, 1);

        $this->assertTrue($this->repository->zoneTemplateNameExists('taken'));
        $this->assertFalse($this->repository->zoneTemplateNameExists('free'));
        // Renaming a template to its own name is not a clash.
        $this->assertFalse($this->repository->zoneTemplateNameExists('taken', $templateId));
        $this->assertTrue($this->repository->zoneTemplateNameExists('taken', $templateId + 1));
    }

    public function testExistenceAndOwnership(): void
    {
        $templateId = $this->repository->createZoneTemplate('owned', '', 8, 8);

        $this->assertTrue($this->repository->zoneTemplateExists($templateId));
        $this->assertFalse($this->repository->zoneTemplateExists(999));

        $this->assertSame(8, $this->repository->getOwner($templateId));
        $this->assertNull($this->repository->getOwner(999));

        $this->assertTrue($this->repository->isOwner($templateId, 8));
        $this->assertFalse($this->repository->isOwner($templateId, 9));
    }

    public function testListZoneTemplatesScopesToTheUserUnlessUeberuser(): void
    {
        $this->db->exec("INSERT INTO users (id, username, fullname) VALUES (1, 'admin', 'Admin'), (5, 'bob', 'Bob')");
        $global = $this->repository->createZoneTemplate('global', '', 0, 1);
        $mine = $this->repository->createZoneTemplate('mine', '', 5, 5);
        $this->repository->createZoneTemplate('theirs', '', 6, 6);
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_templ_id) VALUES (1, 11, 5, $mine)");

        $scoped = $this->repository->listZoneTemplates(5, false);
        $this->assertSame(['global', 'mine'], array_column($scoped, 'name'));

        $byName = array_column($scoped, null, 'name');
        $this->assertSame(1, (int)$byName['mine']['zones_linked']);
        $this->assertSame(0, (int)$byName['global']['zones_linked']);
        $this->assertSame('bob', $byName['mine']['owner_username']);
        $this->assertSame($global, (int)$byName['global']['id']);

        $all = $this->repository->listZoneTemplates(5, true);
        $this->assertSame(['global', 'mine', 'theirs'], array_column($all, 'name'));
    }

    public function testUnlinkZoneFromTemplateMatchesOnDomainId(): void
    {
        $templateId = $this->repository->createZoneTemplate('linked', '', 0, 1);
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_templ_id) VALUES (1, 11, 3, $templateId), (2, 12, 3, $templateId)");

        $this->assertTrue($this->repository->unlinkZoneFromTemplate(11));

        $this->assertSame(0, (int)$this->db->query("SELECT zone_templ_id FROM zones WHERE id = 1")->fetchColumn());
        $this->assertSame($templateId, (int)$this->db->query("SELECT zone_templ_id FROM zones WHERE id = 2")->fetchColumn());
    }

    public function testGetTemplateNameForZone(): void
    {
        $templateId = $this->repository->createZoneTemplate('named', '', 0, 1);
        $this->db->exec("INSERT INTO zones (id, domain_id, owner, zone_templ_id) VALUES (1, 11, 3, $templateId), (2, 12, 3, 0)");

        $this->assertSame('named', $this->repository->getTemplateNameForZone(11));
        $this->assertSame('', $this->repository->getTemplateNameForZone(12));
        $this->assertSame('', $this->repository->getTemplateNameForZone(999));
    }

    public function testReadOnlyLookupsWorkWithoutConfiguration(): void
    {
        $templateId = $this->repository->createZoneTemplate('readable', '', 0, 1);
        $configless = new DbZoneTemplateRepository($this->db);

        $this->assertTrue($configless->zoneTemplateExists($templateId));
        $this->assertSame('readable', $configless->getZoneTemplateDetails($templateId)['name']);
        $this->assertCount(1, $configless->getZoneTemplateRecords($templateId));
        $this->assertSame(1, $configless->countZoneTemplateRecords($templateId));
        $this->assertSame(0, $configless->getOwner($templateId));
    }

    public function testWritesWithoutConfigurationFailLoudly(): void
    {
        $configless = new DbZoneTemplateRepository($this->db);

        $this->expectException(LogicException::class);
        $configless->createZoneTemplate('nope', '', 0, 1);
    }
}
