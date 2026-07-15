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

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Poweradmin\Domain\Model\ZoneTemplate;
use TestHelpers\SqliteIntegrationTestCase;

/**
 * IDOR guard for zone-template-record edit/delete. Ownership is checked against
 * the template id the caller owns, but the write targets a caller-supplied
 * record id; the two must be tied together server-side. Otherwise a user who
 * owns one template can edit or delete records in a template they do not own.
 */
class ZoneTemplateRecordOwnershipTest extends SqliteIntegrationTestCase
{
    private const OWNED_TEMPLATE_ID = 10;
    private const FOREIGN_TEMPLATE_ID = 20;
    private const FOREIGN_RECORD_ID = 5;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db->exec("CREATE TABLE zone_templ (id INTEGER PRIMARY KEY, name TEXT NOT NULL, descr TEXT NOT NULL DEFAULT '', owner INTEGER NOT NULL, created_by INTEGER, is_default INTEGER NOT NULL DEFAULT 0)");
        $this->db->exec("CREATE TABLE zone_templ_records (id INTEGER PRIMARY KEY, zone_templ_id INTEGER NOT NULL, name TEXT NOT NULL, type TEXT NOT NULL, content TEXT NOT NULL, ttl INTEGER NOT NULL, prio INTEGER NOT NULL)");

        $this->db->exec("INSERT INTO zone_templ (id, name, owner, created_by) VALUES (" . self::OWNED_TEMPLATE_ID . ", 'Owned', 1, 1)");
        $this->db->exec("INSERT INTO zone_templ (id, name, owner, created_by) VALUES (" . self::FOREIGN_TEMPLATE_ID . ", 'Foreign', 1, 1)");

        // The victim record lives in the foreign template.
        $this->db->exec("INSERT INTO zone_templ_records (id, zone_templ_id, name, type, content, ttl, prio) VALUES (" . self::FOREIGN_RECORD_ID . ", " . self::FOREIGN_TEMPLATE_ID . ", 'www.[ZONE]', 'A', '1.1.1.1', 3600, 0)");
    }

    #[RunInSeparateProcess]
    public function testEditRejectsRecordFromAnotherTemplate(): void
    {
        $result = $this->zoneTemplate()->editZoneTemplRecord($this->forgedEditPayload(), self::OWNED_TEMPLATE_ID);

        $this->assertFalse($result, 'Editing a record that belongs to another template must be rejected.');
        $this->assertSame('1.1.1.1', $this->recordContent(self::FOREIGN_RECORD_ID), 'The foreign record must be untouched.');
    }

    #[RunInSeparateProcess]
    public function testEditAllowsRecordFromTheAuthorizedTemplate(): void
    {
        $result = $this->zoneTemplate()->editZoneTemplRecord($this->forgedEditPayload(), self::FOREIGN_TEMPLATE_ID);

        $this->assertTrue($result, 'Editing a record in the authorized template must succeed.');
        $this->assertSame('6.6.6.6', $this->recordContent(self::FOREIGN_RECORD_ID));
    }

    #[RunInSeparateProcess]
    public function testDeleteRejectsRecordFromAnotherTemplate(): void
    {
        $result = $this->zoneTemplate()->deleteZoneTemplRecord(self::FOREIGN_RECORD_ID, self::OWNED_TEMPLATE_ID);

        $this->assertFalse($result, 'Deleting a record that belongs to another template must be rejected.');
        $this->assertTrue($this->recordExists(self::FOREIGN_RECORD_ID), 'The foreign record must still exist.');
    }

    #[RunInSeparateProcess]
    public function testDeleteAllowsRecordFromTheAuthorizedTemplate(): void
    {
        $result = $this->zoneTemplate()->deleteZoneTemplRecord(self::FOREIGN_RECORD_ID, self::FOREIGN_TEMPLATE_ID);

        $this->assertTrue($result, 'Deleting a record in the authorized template must succeed.');
        $this->assertFalse($this->recordExists(self::FOREIGN_RECORD_ID));
    }

    #[RunInSeparateProcess]
    public function testDeleteRejectsUnknownRecordId(): void
    {
        $this->assertFalse($this->zoneTemplate()->deleteZoneTemplRecord(9999, self::FOREIGN_TEMPLATE_ID));
    }

    private function zoneTemplate(): ZoneTemplate
    {
        return new ZoneTemplate($this->db, $this->config);
    }

    private function forgedEditPayload(): array
    {
        return [
            'rid' => self::FOREIGN_RECORD_ID,
            'name' => 'pwned.[ZONE]',
            'type' => 'A',
            'content' => '6.6.6.6',
            'ttl' => 3600,
            'prio' => 0,
        ];
    }

    private function recordContent(int $id): ?string
    {
        $stmt = $this->db->prepare('SELECT content FROM zone_templ_records WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $content = $stmt->fetchColumn();

        return $content === false ? null : (string)$content;
    }

    private function recordExists(int $id): bool
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM zone_templ_records WHERE id = :id');
        $stmt->execute([':id' => $id]);

        return (bool)$stmt->fetchColumn();
    }
}
