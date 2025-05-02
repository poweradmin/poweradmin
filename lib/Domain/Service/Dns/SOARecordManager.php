<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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

use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOLayer;

/**
 * Service class for managing SOA records
 */
class SOARecordManager implements SOARecordManagerInterface
{
    private PDOLayer $db;
    private ConfigurationManager $config;

    /**
     * Constructor
     *
     * @param PDOLayer $db Database connection
     * @param ConfigurationManager $config Configuration manager
     */
    public function __construct(PDOLayer $db, ConfigurationManager $config)
    {
        $this->db = $db;
        $this->config = $config;
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
        $pdns_db_name = $this->config->get('database', 'pdns_name');
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

        $sqlq = "SELECT content FROM $records_table WHERE type = " . $this->db->quote('SOA', 'text') . " AND domain_id = " . $this->db->quote($zone_id, 'integer');
        return $this->db->queryOne($sqlq) ?: '';
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

        $this->setTimezone();
        $today = date('Ymd');

        $revision = (int)substr($curr_serial, -2);
        $ser_date = substr($curr_serial, 0, 8);

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
        return $today . str_pad($revision, 2, "0", STR_PAD_LEFT);
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
        $pdns_db_name = $this->config->get('database', 'pdns_name');
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

        $sqlq = "UPDATE $records_table SET content = " . $this->db->quote($content, 'text') . " WHERE domain_id = " . $this->db->quote($domain_id, 'integer') . " AND type = " . $this->db->quote('SOA', 'text');
        $this->db->query($sqlq);

        return true;
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
            return self::setSOASerial($soa_rec, $new_serial);
        }

        return self::setSOASerial($soa_rec, $curr_serial);
    }

    /**
     * Update SOA serial
     *
     * Increments SOA serial to next possible number
     *
     * @param int $domain_id Domain ID
     *
     * @return boolean true if success
     */
    public function updateSOASerial(int $domain_id): bool
    {
        $soa_rec = $this->getSOARecord($domain_id);
        if ($soa_rec == null) {
            return false;
        }

        $curr_serial = self::getSOASerial($soa_rec);
        $new_serial = $this->getNextSerial($curr_serial);

        if ($curr_serial != $new_serial) {
            $soa_rec = self::setSOASerial($soa_rec, $new_serial);
            return $this->updateSOARecord($domain_id, $soa_rec);
        }

        return true;
    }

    /**
     * Set timezone
     *
     * Set timezone to configured tz or UTC it not set
     */
    public function setTimezone(): void
    {
        $timezone = $this->config->get('misc', 'timezone');

        if (isset($timezone)) {
            date_default_timezone_set($timezone);
        } elseif (!ini_get('date.timezone')) {
            date_default_timezone_set('UTC');
        }
    }
}
