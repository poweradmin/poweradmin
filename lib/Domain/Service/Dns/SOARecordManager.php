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

namespace Poweradmin\Domain\Service\Dns;

use Poweradmin\Domain\Service\DnsBackendProvider;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use PDO;
use Poweradmin\Infrastructure\Database\DbCompat;
use Poweradmin\Infrastructure\Database\TableNameService;
use Poweradmin\Infrastructure\Database\PdnsTable;

/**
 * Service class for managing SOA records
 */
class SOARecordManager implements SOARecordManagerInterface
{
    /** Zone serials are 32-bit unsigned values (RFC 1982). */
    public const MAX_SERIAL = 4294967295;

    private PDO $db;
    private ConfigurationManager $config;
    private ?DnsBackendProvider $backendProvider;

    /**
     * Constructor
     *
     * @param PDO $db Database connection
     * @param ConfigurationManager $config Configuration manager
     * @param DnsBackendProvider|null $backendProvider Optional DNS backend provider
     */
    public function __construct(PDO $db, ConfigurationManager $config, ?DnsBackendProvider $backendProvider = null)
    {
        $this->db = $db;
        $this->config = $config;
        $this->backendProvider = $backendProvider;
    }

    private function isApiBackend(): bool
    {
        return $this->backendProvider !== null && $this->backendProvider->isApiBackend();
    }

    /**
     * Get SOA record content for Zone ID
     *
     * @param int $zone_id Zone ID
     *
     * @return string SOA content
     */
    public function getSOARecord(int $zone_id): string
    {
        if ($this->isApiBackend()) {
            return $this->backendProvider->getSOARecord($zone_id);
        }

        $tableNameService = new TableNameService($this->config);
        $records_table = $tableNameService->getTable(PdnsTable::RECORDS);

        $stmt = $this->db->prepare("SELECT content FROM $records_table WHERE type = ? AND domain_id = ? ORDER BY id");
        $stmt->execute(['SOA', $zone_id]);
        return $stmt->fetchColumn() ?: '';
    }

    /**
     * Get SOA Serial Number
     *
     * @param string $soa_rec SOA record content
     *
     * @return string|null SOA serial
     */
    public static function getSOASerial(string $soa_rec): ?string
    {
        $soa = explode(" ", $soa_rec);
        return array_key_exists(2, $soa) ? $soa[2] : null;
    }

    /**
     * Replace a literal "[SERIAL]", "[UNIXTIME]" or "[COUNTER]" in the serial
     * field of a submitted SOA record with a numeric value. The subsequent
     * updateSOASerial() bump (see EditRecordController) then advances the
     * value, preserving RFC 1982 monotonicity even if today's serial has
     * already been incremented several times.
     *
     * The resolved value never drops below the existing serial - slaves only
     * transfer when the serial increases, so a format switch cannot rewind it.
     *
     * @param string $newContent SOA content as submitted by the user
     * @param string $oldContent Current SOA content from the zone (may be empty)
     * @return string SOA content with the placeholder resolved to a numeric value
     */
    public static function expandSerialPlaceholder(string $newContent, string $oldContent): string
    {
        $fields = preg_split('/\s+/', trim($newContent));
        if ($fields === false || !isset($fields[2])) {
            return $newContent;
        }

        $oldSerial = self::getSOASerial($oldContent);
        $hasOldSerial = $oldSerial !== null && is_numeric($oldSerial);

        switch ($fields[2]) {
            case '[SERIAL]':
                $fields[2] = $hasOldSerial ? $oldSerial : date('Ymd') . '00';
                break;
            case '[UNIXTIME]':
                $fields[2] = (string)($hasOldSerial ? max(time(), (int)$oldSerial) : time());
                break;
            case '[COUNTER]':
                $fields[2] = $hasOldSerial ? $oldSerial : '1';
                break;
            default:
                return $newContent;
        }

        return implode(' ', $fields);
    }

    /**
     * Get Next Date
     *
     * @param string $curr_date Current date in YYYYMMDD format
     *
     * @return string Date +1 day
     */
    public static function getNextDate(string $curr_date): string
    {
        return date('Ymd', strtotime('+1 day', strtotime($curr_date)));
    }

    /**
     * Get Next Serial
     *
     * Zone transfer to zone slave(s) will occur only if the serial number
     * of the SOA RR is arithmetically greater that the previous one
     * (as defined by RFC-1982).
     *
     * The serial should be updated, unless:
     *
     * - the serial is set to "0", see http://doc.powerdns.com/types.html#id482176
     *
     * - set a fresh serial ONLY if the existing serial is lower than the current date
     *
     * - update date in serial if it reaches limit of revisions for today or do you
     * think that ritual suicide is better in such case?
     *
     * "This works unless you will require to make more than 99 changes until the new
     * date is reached - in which case perhaps ritual suicide is the best option."
     * http://www.zytrax.com/books/dns/ch9/serial.html
     *
     * @param int|string $curr_serial Current Serial No
     *
     * @return string|int Next serial number
     */
    public function getNextSerial(int|string $curr_serial): int|string
    {
        // Autoserial
        if ($curr_serial == 0) {
            return 0;
        }

        // Serial number could be a not date based
        if ($curr_serial < 1979999999) {
            return $curr_serial + 1;
        }

        // Reset the serial number, Bind was written in the early 1980s
        if ($curr_serial == 1979999999) {
            return 1;
        }

        $today = date('Ymd');

        $revision = (int)substr((string)$curr_serial, -2);
        $ser_date = substr((string)$curr_serial, 0, 8);

        if ($curr_serial == $today . '99') {
            return self::getNextDate($today) . '00';
        }

        if (strcmp($today, $ser_date) === 0) {
            // Current serial starts with date of today, so we need to update the revision only.
            ++$revision;
        } elseif (strcmp($today, $ser_date) <= -1) {
            // Reuse existing serial date if it's in the future
            $today = $ser_date;

            // Get next date if revision reaches maximum per day (99) limit otherwise increment the counter
            if ($revision == 99) {
                $today = self::getNextDate($today);
                $revision = "00";
            } else {
                ++$revision;
            }
        } else {
            // Current serial did not start of today, so it's either an older
            // serial, therefore set a fresh serial
            $revision = "00";
        }

        // Create new serial out of existing/updated date and revision
        $next = $today . str_pad((string)$revision, 2, "0", STR_PAD_LEFT);

        // A serial above 1979999999 that is not a real date still lands in the branch
        // above and keeps growing. DNS serials are 32-bit unsigned (RFC 1982), so wrap
        // rather than hand PowerDNS a value it cannot store.
        return (float)$next > self::MAX_SERIAL ? 1 : $next;
    }

    /**
     * Update SOA record
     *
     * @param int $domain_id Domain ID
     * @param string $content SOA content to set
     *
     * @return boolean true if success
     */
    public function updateSOARecord(int $domain_id, string $content): bool
    {
        if ($this->isApiBackend()) {
            $zoneName = $this->backendProvider->getZoneNameById($domain_id);
            if ($zoneName === null) {
                return false;
            }
            $apexName = DnsHelper::zoneApexName($zoneName);
            // A serial bump rewrites the SOA, so it keeps the TTL the zone already has
            $existing = $this->backendProvider->getRecordsByName($domain_id, $apexName, 'SOA');
            $soa_ttl = isset($existing[0]['ttl'])
                ? (int)$existing[0]['ttl']
                : (int)$this->config->get('dns', 'ttl', 86400);
            // The zone id stays the variant; the SOA record sits at its apex name
            return $this->backendProvider->addRecord($domain_id, $apexName, 'SOA', $content, $soa_ttl, 0);
        }

        $this->writeSoaContent($domain_id, $this->soaRecordIds($domain_id), $content);

        return true;
    }

    /**
     * Bumps the serial of a SQL zone as one locked step, in the caller's transaction or its
     * own, so concurrent writers to the zone cannot both bump from the same serial.
     */
    private function bumpSqlSerial(int $domain_id): bool
    {
        $ids = $this->soaRecordIds($domain_id);
        if ($ids === []) {
            return false;
        }

        $ownsTransaction = !$this->db->inTransaction();
        // SQLite waits for another writer only when the write lock is taken up front; PDO does not track that BEGIN
        $sqlite = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        if ($ownsTransaction) {
            $sqlite ? $this->db->exec('BEGIN IMMEDIATE') : $this->db->beginTransaction();
        }
        try {
            $rows = $this->lockSoaRows($domain_id, $ids);
            if ($rows === []) {
                // Replaced between the lookup and the lock, as a template apply does
                $ids = $this->soaRecordIds($domain_id);
                $rows = $ids === [] ? [] : $this->lockSoaRows($domain_id, $ids);
            }
            $soa_rec = $rows[0]['content'] ?? null;
            $curr_serial = is_string($soa_rec) ? self::getSOASerial($soa_rec) : null;
            if ($curr_serial !== null) {
                $new_serial = $this->getNextSerial($curr_serial);
                if ($curr_serial != $new_serial) {
                    $this->writeSoaContent($domain_id, array_map('intval', array_column($rows, 'id')), self::setSOASerial($soa_rec, (string)$new_serial));
                }
            }
            if ($ownsTransaction) {
                $sqlite ? $this->db->exec('COMMIT') : $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction && $sqlite) {
                try {
                    $this->db->exec('ROLLBACK');
                } catch (\PDOException) {
                    // A failed COMMIT may already have ended it
                }
            } elseif ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return is_string($soa_rec);
    }

    /**
     * Locking and writing the SOA by primary key holds those rows only; a statement through
     * domain_id also locks the rows a concurrent write just added, and the two deadlock.
     *
     * @return list<int>
     */
    private function soaRecordIds(int $domain_id): array
    {
        $records_table = (new TableNameService($this->config))->getTable(PdnsTable::RECORDS);
        $stmt = $this->db->prepare("SELECT id FROM $records_table WHERE domain_id = ? AND type = 'SOA' ORDER BY id");
        $stmt->bindValue(1, $domain_id, PDO::PARAM_INT);
        $stmt->execute();

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * A locking read sees the latest committed serial, not this transaction's snapshot.
     *
     * The zone and type are checked too: SQLite reuses a deleted row's id.
     *
     * @param list<int> $ids
     * @return list<array{id: int|string, content: string}>
     */
    private function lockSoaRows(int $domain_id, array $ids): array
    {
        $records_table = (new TableNameService($this->config))->getTable(PdnsTable::RECORDS);
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("SELECT id, content FROM $records_table WHERE id IN ($placeholders) AND domain_id = ? AND type = 'SOA' ORDER BY id"
            . DbCompat::rowLock((string)$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)));
        foreach ($ids as $position => $id) {
            $stmt->bindValue($position + 1, $id, PDO::PARAM_INT);
        }
        $stmt->bindValue(count($ids) + 1, $domain_id, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Every SOA row gets the content, so a reader sees the same serial whichever it picks.
     *
     * @param list<int> $ids
     */
    private function writeSoaContent(int $domain_id, array $ids, string $content): void
    {
        if ($ids === []) {
            return;
        }
        $records_table = (new TableNameService($this->config))->getTable(PdnsTable::RECORDS);
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("UPDATE $records_table SET content = ? WHERE id IN ($placeholders) AND domain_id = ? AND type = 'SOA'");
        $stmt->bindValue(1, $content, PDO::PARAM_STR);
        foreach ($ids as $position => $id) {
            $stmt->bindValue($position + 2, $id, PDO::PARAM_INT);
        }
        $stmt->bindValue(count($ids) + 2, $domain_id, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Set SOA serial in SOA content
     *
     * @param string $soa_rec SOA record content
     * @param string $serial New serial number
     *
     * @return string Updated SOA record
     */
    public static function setSOASerial(string $soa_rec, string $serial): string
    {
        // Split content of current SOA record into an array.
        $soa = explode(" ", $soa_rec);
        $soa[2] = $serial;

        // Build new SOA record content
        $soa_rec = join(" ", $soa);
        return chop($soa_rec);
    }

    /**
     * Return SOA record with incremented serial number
     *
     * @param string $soa_rec Current SOA record
     *
     * @return string Updated SOA record or empty string if input is empty
     */
    public function getUpdatedSOARecord(string $soa_rec): string
    {
        if (empty($soa_rec)) {
            return '';
        }

        $curr_serial = self::getSOASerial($soa_rec);
        $new_serial = $this->getNextSerial($curr_serial);

        if ($curr_serial != $new_serial) {
            return self::setSOASerial($soa_rec, (string)$new_serial);
        }

        return self::setSOASerial($soa_rec, $curr_serial);
    }

    /**
     * Update SOA serial
     *
     * Increments SOA serial to next possible number. Under SOA-EDIT-API PowerDNS bumps
     * the serial for every RRset it is sent, so that is left to it when the caller has
     * just written one; otherwise (no-op save, zone comment) the SOA is sent unchanged
     * to make PowerDNS bump it, and the new serial is read back.
     *
     * @param int $domain_id Domain ID
     * @param bool $rrsetWritten Whether an RRset was written to the backend in this operation
     *
     * @return boolean true if the serial moved or the backend moved it with the write
     */
    public function updateSOASerial(int $domain_id, bool $rrsetWritten = true): bool
    {
        // The SQL backend never reports SOA-EDIT-API, nor does an API zone set to OFF
        $backendEdits = $this->isApiBackend() && $this->backendProvider->hasSoaEditApi($domain_id);
        if ($backendEdits && $rrsetWritten) {
            return true;
        }
        if (!$this->isApiBackend()) {
            return $this->bumpSqlSerial($domain_id);
        }

        $soa_rec = $this->getSOARecord($domain_id);
        if ($soa_rec == null) {
            return false;
        }

        $curr_serial = self::getSOASerial($soa_rec);

        if ($backendEdits) {
            if (!$this->updateSOARecord($domain_id, $soa_rec)) {
                return false;
            }

            return self::getSOASerial($this->getSOARecord($domain_id)) !== $curr_serial;
        }

        $new_serial = $this->getNextSerial($curr_serial);

        if ($curr_serial != $new_serial) {
            $soa_rec = self::setSOASerial($soa_rec, (string)$new_serial);
            return $this->updateSOARecord($domain_id, $soa_rec);
        }

        return true;
    }
}
