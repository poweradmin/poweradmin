<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2023 Poweradmin Development Team
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

namespace Poweradmin;

use Poweradmin\Application\Dnssec\DnssecProviderFactory;
use Poweradmin\Infrastructure\Configuration\FakeConfiguration;
use Poweradmin\Infrastructure\Database\DbCompat;

/**
 * DNS record functions
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2023 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class DnsRecord
{
    /** Check if Zone ID exists
     *
     * @param int $zid Zone ID
     *
     * @return array|boolean Domain count or false on failure
     */
    public static function zone_id_exists($zid)
    {
        global $db;
        $query = "SELECT COUNT(id) FROM domains WHERE id = " . $db->quote($zid, 'integer');
        return $db->queryOne($query);
    }

    /** Get Zone ID from Record ID
     *
     * @param int $rid Record ID
     *
     * @return array|bool Zone ID
     */
    public static function get_zone_id_from_record_id($rid)
    {
        global $db;
        $query = "SELECT domain_id FROM records WHERE id = " . $db->quote($rid, 'integer');
        return $db->queryOne($query);
    }

    /** Count Zone Records for Zone ID
     *
     * @param int $zone_id Zone ID
     *
     * @return array|bool Record count
     */
    public static function count_zone_records($zone_id)
    {
        global $db;
        $sqlq = "SELECT COUNT(id) FROM records WHERE domain_id = " . $db->quote($zone_id, 'integer') . " AND type IS NOT NULL";
        return $db->queryOne($sqlq);
    }

    /** Get SOA record content for Zone ID
     *
     * @param int $zone_id Zone ID
     *
     * @return array|bool SOA content
     */
    public static function get_soa_record($zone_id)
    {
        global $db;

        $sqlq = "SELECT content FROM records WHERE type = " . $db->quote('SOA', 'text') . " AND domain_id = " . $db->quote($zone_id, 'integer');
        return $db->queryOne($sqlq);
    }

    /** Get SOA Serial Number
     *
     * @param string $soa_rec SOA record content
     *
     * @return string SOA serial
     */
    public static function get_soa_serial($soa_rec)
    {
        $soa = explode(" ", $soa_rec);
        return array_key_exists(2, $soa) ? $soa[2] : null;
    }

    /** Get Next Date
     *
     * @param string $curr_date Current date in YYYYMMDD format
     *
     * @return string Date +1 day
     */
    public static function get_next_date(string $curr_date): string
    {
        return date('Ymd', strtotime('+1 day', strtotime($curr_date)));
    }

    /** Get Next Serial
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
    public static function get_next_serial(int|string $curr_serial): int|string
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

        self::set_timezone();
        $today = date('Ymd');

        $revision = (int)substr($curr_serial, -2);
        $ser_date = substr($curr_serial, 0, 8);

        if ($curr_serial == $today . '99') {
            return self::get_next_date($today) . '00';
        }

        if (strcmp($today, $ser_date) === 0) {
            // Current serial starts with date of today, so we need to update the revision only.
            ++$revision;
        } elseif (strcmp($today, $ser_date) <= -1) {
            // Reuse existing serial date if it's in the future
            $today = $ser_date;

            // Get next date if revision reaches maximum per day (99) limit otherwise increment the counter
            if ($revision == 99) {
                $today = self::get_next_date($today);
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

    /** Update SOA record
     *
     * @param int $domain_id Domain ID
     * @param string $content SOA content to set
     *
     * @return boolean true if success
     */
    public static function update_soa_record($domain_id, $content)
    {
        global $db;

        $sqlq = "UPDATE records SET content = " . $db->quote($content, 'text') . " WHERE domain_id = " . $db->quote($domain_id, 'integer') . " AND type = " . $db->quote('SOA', 'text');
        $db->query($sqlq);
        return true;
    }

    /** Set SOA serial in SOA content
     *
     * @param string $soa_rec SOA record content
     * @param string $serial New serial number
     *
     * @return string Updated SOA record
     */
    public static function set_soa_serial($soa_rec, $serial)
    {
        // Split content of current SOA record into an array.
        $soa = explode(" ", $soa_rec);
        $soa[2] = $serial;

        // Build new SOA record content
        $soa_rec = join(" ", $soa);
        return chop($soa_rec);
    }

    /** Return SOA record
     *
     * Returns SOA record with incremented serial number
     *
     * @param string $soa_rec Current SOA record
     *
     * @return string true if success
     */
    public static function get_updated_soa_record(string $soa_rec): string
    {
        if (empty($soa_rec)) {
            return '';
        }

        $curr_serial = self::get_soa_serial($soa_rec);
        $new_serial = self::get_next_serial($curr_serial);

        if ($curr_serial != $new_serial) {
            return self::set_soa_serial($soa_rec, $new_serial);
        }

        return self::set_soa_serial($soa_rec, $curr_serial);
    }

    /** Update SOA serial
     *
     * Increments SOA serial to next possible number
     *
     * @param int $domain_id Domain ID
     *
     * @return boolean true if success
     */
    public static function update_soa_serial($domain_id)
    {
        $soa_rec = self::get_soa_record($domain_id);
        if ($soa_rec == NULL) {
            return false;
        }

        $curr_serial = self::get_soa_serial($soa_rec);
        $new_serial = self::get_next_serial($curr_serial);

        if ($curr_serial != $new_serial) {
            $soa_rec = self::set_soa_serial($soa_rec, $new_serial);
            return self::update_soa_record($domain_id, $soa_rec);
        }

        return true;
    }

    /** Get Zone comment
     *
     * @param int $zone_id Zone ID
     *
     * @return string Zone Comment
     */
    public static function get_zone_comment($zone_id)
    {
        global $db;
        $query = "SELECT comment FROM zones WHERE domain_id = " . $db->quote($zone_id, 'integer');
        $comment = $db->queryOne($query);

        if ($comment == "0") {
            $comment = '';
        }

        return $comment;
    }

    /** Edit the zone comment
     *
     * This function validates it if correct it inserts it into the database.
     *
     * @param int $zone_id Zone ID
     * @param string $comment Comment to set
     *
     * @return boolean true on success
     */
    public static function edit_zone_comment($zone_id, $comment)
    {
        $perm_edit = Permission::getEditPermission();

        $user_is_zone_owner = do_hook('verify_user_is_owner_zoneid', $zone_id);
        $zone_type = self::get_domain_type($zone_id);

        if ($zone_type == "SLAVE" || $perm_edit == "none" || (($perm_edit == "own" || $perm_edit == "own_as_client") && $user_is_zone_owner == "0")) {
            error(_("You do not have the permission to edit this comment."));
            return false;
        } else {
            global $db;

            $query = "SELECT COUNT(*) FROM zones WHERE domain_id=" . $db->quote($zone_id, 'integer');

            $count = $db->queryOne($query);

            if ($count > 0) {
                $query = "UPDATE zones
				SET comment=" . $db->quote($comment, 'text') . "
				WHERE domain_id=" . $db->quote($zone_id, 'integer');
                $db->query($query);
            } else {
                $query = "INSERT INTO zones (domain_id, owner, comment, zone_templ_id)
				VALUES(" . $db->quote($zone_id, 'integer') . ",1," . $db->quote($comment, 'text') . ",0)";
                $db->query($query);
            }
        }
        return true;
    }

    /** Edit a record
     *
     * This function validates it if correct it inserts it into the database.
     *
     * @param mixed[] $record Record structure to update
     *
     * @return boolean true if successful
     */
    public static function edit_record($record)
    {
        $perm_edit = Permission::getEditPermission();

        $user_is_zone_owner = do_hook('verify_user_is_owner_zoneid', $record['zid']);
        $zone_type = self::get_domain_type($record['zid']);

        if ($record['type'] == 'SOA' && $perm_edit == "own_as_client") {
            error(_("You do not have the permission to edit this SOA record."));
            return false;
        }
        if ($record['type'] == 'NS' && $perm_edit == "own_as_client") {
            error(_("You do not have the permission to edit this NS record."));
            return false;
        }

        if ($zone_type == "SLAVE" || $perm_edit == "none" || (($perm_edit == "own" || $perm_edit == "own_as_client") && $user_is_zone_owner == "0")) {
            error(_("You do not have the permission to edit this record."));
            return false;
        } else {
            global $db;
            if (Dns::validate_input($record['rid'], $record['zid'], $record['type'], $record['content'], $record['name'], $record['prio'], $record['ttl'])) {
                $name = strtolower($record['name']); // powerdns only searches for lower case records
                $query = "UPDATE records
				SET name=" . $db->quote($name, 'text') . ",
				type=" . $db->quote($record['type'], 'text') . ",
				content=" . $db->quote($record['content'], 'text') . ",
				ttl=" . $db->quote($record['ttl'], 'integer') . ",
				prio=" . $db->quote($record['prio'], 'integer') . "
				WHERE id=" . $db->quote($record['rid'], 'integer');
                $db->query($query);
                return true;
            }
            return false;
        }
    }

    /** Add a record
     *
     * This function validates it if correct it inserts it into the database.
     *
     * @param int $zone_id Zone ID
     * @param string $name Name part of record
     * @param string $type Type of record
     * @param string $content Content of record
     * @param int $ttl Time-To-Live of record
     * @param int $prio Priority of record
     *
     * @return boolean true if successful
     */
    public static function add_record($zone_id, $name, $type, $content, $ttl, $prio)
    {
        global $db;
        global $pdnssec_use;

        $perm_edit = Permission::getEditPermission();

        $user_is_zone_owner = do_hook('verify_user_is_owner_zoneid', $zone_id);
        $zone_type = self::get_domain_type($zone_id);

        if ($type == 'SOA' && $perm_edit == "own_as_client") {
            error(_("You do not have the permission to add SOA record."));
            return false;
        }
        if ($type == 'NS' && $perm_edit == "own_as_client") {
            error(_("You do not have the permission to add NS record."));
            return false;
        }

        if ($zone_type == "SLAVE" || $perm_edit == "none" || (($perm_edit == "own" || $perm_edit == "own_as_client") && $user_is_zone_owner == "0")) {
            error(_("You do not have the permission to add a record to this zone."));
            return false;
        }

        if (!Dns::validate_input(-1, $zone_id, $type, $content, $name, $prio, $ttl)) {
            return false;
        }

        $db->beginTransaction();
        $name = strtolower($name); // powerdns only searches for lower case records
        $query = "INSERT INTO records (domain_id, name, type, content, ttl, prio) VALUES ("
            . $db->quote($zone_id, 'integer') . ","
            . $db->quote($name, 'text') . ","
            . $db->quote($type, 'text') . ","
            . $db->quote($content, 'text') . ","
            . $db->quote($ttl, 'integer') . ","
            . $db->quote($prio, 'integer') . ")";
        $db->exec($query);
        $db->commit();

        if ($type != 'SOA') {
            self::update_soa_serial($zone_id);
        }

        if ($pdnssec_use) {
            global $pdns_api_url;
            global $pdns_api_key;

            $dnssecProvider = DnssecProviderFactory::create(
                new FakeConfiguration($pdns_api_url, $pdns_api_key)
            );
            $zone_name = DnsRecord::get_domain_name_by_id($zone_id);
            $dnssecProvider->rectifyZone($zone_name);
        }

        return true;
    }

    /** Add Supermaster
     *
     * Add a trusted supermaster to the global supermasters table
     *
     * @param string $master_ip Supermaster IP address
     * @param string $ns_name Hostname of supermasterfound in NS records for domain
     * @param string $account Account name used for tracking
     *
     * @return boolean true on success
     */
    public static function add_supermaster($master_ip, $ns_name, $account)
    {
        global $db;
        if (!Dns::is_valid_ipv4($master_ip) && !Dns::is_valid_ipv6($master_ip)) {
            error(_('This is not a valid IPv4 or IPv6 address.'));
            return false;
        }
        if (!Dns::is_valid_hostname_fqdn($ns_name, 0)) {
            error(_('Invalid hostname.'));
            return false;
        }
        if (!self::validate_account($account)) {
            error(sprintf(_('Invalid argument(s) given to function %s %s'), "add_supermaster", "given account name is invalid (alpha chars only)"));
            return false;
        }
        if (self::supermaster_ip_name_exists($master_ip, $ns_name)) {
            error(_('There is already a supermaster with this IP address and hostname.'));
            return false;
        } else {
            $db->query("INSERT INTO supermasters VALUES (" . $db->quote($master_ip, 'text') . ", " . $db->quote($ns_name, 'text') . ", " . $db->quote($account, 'text') . ")");
            return true;
        }
    }

    /** Delete Supermaster
     *
     * Delete a supermaster from the global supermasters table
     *
     * @param string $master_ip Supermaster IP address
     * @param string $ns_name Hostname of supermaster
     *
     * @return boolean true on success
     */
    public static function delete_supermaster($master_ip, $ns_name)
    {
        global $db;
        if (Dns::is_valid_ipv4($master_ip) || Dns::is_valid_ipv6($master_ip) || Dns::is_valid_hostname_fqdn($ns_name, 0)) {
            $db->query("DELETE FROM supermasters WHERE ip = " . $db->quote($master_ip, 'text') .
                " AND nameserver = " . $db->quote($ns_name, 'text'));
            return true;
        } else {
            error(sprintf(_('Invalid argument(s) given to function %s %s'), "delete_supermaster", "No or no valid ipv4 or ipv6 address given."));
        }
        return false;
    }

    /** Get Supermaster Info from IP
     *
     * Retrieve supermaster details from supermaster IP address
     *
     * @param string $master_ip Supermaster IP address
     *
     * @return mixed[] array of supermaster details
     */
    public static function get_supermaster_info_from_ip($master_ip)
    {
        global $db;
        if (Dns::is_valid_ipv4($master_ip) || Dns::is_valid_ipv6($master_ip)) {
            $result = $db->queryRow("SELECT ip,nameserver,account FROM supermasters WHERE ip = " . $db->quote($master_ip, 'text'));

            return array(
                "master_ip" => $result["ip"],
                "ns_name" => $result["nameserver"],
                "account" => $result["account"]
            );
        } else {
            error(sprintf(_('Invalid argument(s) given to function %s %s'), "get_supermaster_info_from_ip", "No or no valid ipv4 or ipv6 address given."));
        }
    }

    /** Get record details from Record ID
     *
     * @param $rid Record ID
     *
     * @return mixed[] array of record details [rid,zid,name,type,content,ttl,prio]
     */
    public static function get_record_details_from_record_id($rid)
    {
        global $db;

        $query = "SELECT id AS rid, domain_id AS zid, name, type, content, ttl, prio FROM records WHERE id = " . $db->quote($rid, 'integer');

        $response = $db->query($query);
        return $response->fetch();
    }

    /** Delete a record by a given record id
     *
     * @param int $rid Record ID
     *
     * @return boolean true on success
     */
    public static function delete_record($rid)
    {
        global $db;

        $perm_edit = Permission::getEditPermission();

        $record = self::get_record_details_from_record_id($rid);
        $user_is_zone_owner = do_hook('verify_user_is_owner_zoneid', $record['zid']);

        if ($perm_edit == "all" || (($perm_edit == "own" || $perm_edit == "own_as_client") && $user_is_zone_owner == "1")) {
            if ($record['type'] == "SOA") {
                error(_('You are trying to delete the SOA record. You are not allowed to remove it, unless you remove the entire zone.'));
            } else {
                $query = "DELETE FROM records WHERE id = " . $db->quote($rid, 'integer');
                $db->query($query);
                return true;
            }
        } else {
            error(_("You do not have the permission to delete this record."));
            return false;
        }
    }

    /** Delete record reference to zone template
     *
     * @param int $rid Record ID
     *
     * @return boolean true on success
     */
    public static function delete_record_zone_templ($rid)
    {
        global $db;

        $query = "DELETE FROM records_zone_templ WHERE record_id = " . $db->quote($rid, 'integer');
        $db->query($query);

        return true;
    }

    /**
     * Add a domain to the database
     *
     * A domain is name obligatory, so is an owner.
     * return values: true when succesful.
     *
     * Empty means templates don't have to be applied.
     *
     * This functions eats a template and by that it inserts various records.
     * first we start checking if something in an arpa record
     * remember to request nextID's from the database to be able to insert record.
     * if anything is invalid the function will error
     *
     * @param string $domain A domain name
     * @param int $owner Owner ID for domain
     * @param string $type Type of domain ['NATIVE','MASTER','SLAVE']
     * @param string $slave_master Master server hostname for domain
     * @param int|string $zone_template ID of zone template ['none' or int]
     *
     * @return boolean true on success
     */
    public static function add_domain($domain, $owner, $type, $slave_master, $zone_template)
    {
        $zone_master_add = do_hook('verify_permission', 'zone_master_add');
        $zone_slave_add = do_hook('verify_permission', 'zone_slave_add');

        // TODO: make sure only one is possible if only one is enabled
        if ($zone_master_add || $zone_slave_add) {

            global $db;
            global $dns_ns1;
            global $dns_hostmaster;
            global $dns_ttl;
            global $db_type;

            if (($domain && $owner && $zone_template) ||
                (preg_match('/in-addr.arpa/i', $domain) && $owner && $zone_template) ||
                $type == "SLAVE" && $domain && $owner && $slave_master) {

                $db->query("INSERT INTO domains (name, type) VALUES (" . $db->quote($domain, 'text') . ", " . $db->quote($type, 'text') . ")");

                if ($db_type == 'pgsql') {
                    $domain_id = $db->lastInsertId('domains_id_seq');
                } else {
                    $domain_id = $db->lastInsertId();
                }

                $db->query("INSERT INTO zones (domain_id, owner, zone_templ_id) VALUES (" . $db->quote($domain_id, 'integer') . ", " . $db->quote($owner, 'integer') . ", " . $db->quote(($zone_template == "none") ? 0 : $zone_template, 'integer') . ")");

                if ($type == "SLAVE") {
                    $db->query("UPDATE domains SET master = " . $db->quote($slave_master, 'text') . " WHERE id = " . $db->quote($domain_id, 'integer'));
                    return true;
                } else {
                    if ($zone_template == "none" && $domain_id) {
                        $ns1 = $dns_ns1;
                        $hm = $dns_hostmaster;
                        $ttl = $dns_ttl;

                        self::set_timezone();

                        $serial = date("Ymd");
                        $serial .= "00";

                        global $dns_soa;
                        $query = "INSERT INTO records (domain_id, name, content, type, ttl, prio) VALUES ("
                            . $db->quote($domain_id, 'integer') . ","
                            . $db->quote($domain, 'text') . ","
                            . $db->quote($ns1 . ' ' . $hm . ' ' . $serial . ' ' . $dns_soa, 'text') . ","
                            . $db->quote('SOA', 'text') . ","
                            . $db->quote($ttl, 'integer') . ","
                            . $db->quote(0, 'integer') . ")";
                        $db->query($query);
                        return true;
                    } elseif ($domain_id && is_numeric($zone_template)) {
                        global $dns_ttl;

                        $templ_records = ZoneTemplate::get_zone_templ_records($zone_template);
                        if ($templ_records != -1) {
                            foreach ($templ_records as $r) {
                                if ((preg_match('/in-addr.arpa/i', $domain) && ($r["type"] == "NS" || $r["type"] == "SOA")) || (!preg_match('/in-addr.arpa/i', $domain))) {
                                    $name = ZoneTemplate::parse_template_value($r["name"], $domain);
                                    $type = $r["type"];
                                    $content = ZoneTemplate::parse_template_value($r["content"], $domain);
                                    $ttl = $r["ttl"];
                                    $prio = intval($r["prio"]);

                                    if (!$ttl) {
                                        $ttl = $dns_ttl;
                                    }

                                    $query = "INSERT INTO records (domain_id, name, type, content, ttl, prio) VALUES ("
                                        . $db->quote($domain_id, 'integer') . ","
                                        . $db->quote($name, 'text') . ","
                                        . $db->quote($type, 'text') . ","
                                        . $db->quote($content, 'text') . ","
                                        . $db->quote($ttl, 'integer') . ","
                                        . $db->quote($prio, 'integer') . ")";
                                    $db->query($query);

                                    if ($db_type == 'pgsql') {
                                        $record_id = $db->lastInsertId('records_id_seq');
                                    } else {
                                        $record_id = $db->lastInsertId();
                                    }

                                    $query = "INSERT INTO records_zone_templ (domain_id, record_id, zone_templ_id) VALUES ("
                                        . $db->quote($domain_id, 'integer') . ","
                                        . $db->quote($record_id, 'integer') . ","
                                        . $db->quote($r['zone_templ_id'], 'integer') . ")";
                                    $db->query($query);
                                }
                            }
                        }
                        return true;
                    } else {
                        error(sprintf(_('Invalid argument(s) given to function %s %s'), "add_domain", "could not create zone"));
                    }
                }
            } else {
                error(sprintf(_('Invalid argument(s) given to function %s'), "add_domain"));
            }
        } else {
            error(_("You do not have the permission to add a master zone."));
            return false;
        }
    }

    /** Deletes a domain by a given id
     *
     * Function always succeeds. If the field is not found in the database, that's what we want anyway.
     *
     * @param int $id Zone ID
     *
     * @return boolean true on success
     */
    public static function delete_domain($id)
    {
        global $db;

        $perm_edit = Permission::getEditPermission();
        $user_is_zone_owner = do_hook('verify_user_is_owner_zoneid', $id);

        if ($perm_edit == "all" || ($perm_edit == "own" && $user_is_zone_owner == "1")) {
            if (is_numeric($id)) {
                $db->query("DELETE FROM zones WHERE domain_id=" . $db->quote($id, 'integer'));
                $db->query("DELETE FROM records WHERE domain_id=" . $db->quote($id, 'integer'));
                $db->query("DELETE FROM records_zone_templ WHERE domain_id=" . $db->quote($id, 'integer'));
                $db->query("DELETE FROM domains WHERE id=" . $db->quote($id, 'integer'));
                return true;
            } else {
                error(sprintf(_('Invalid argument(s) given to function %s %s'), "delete_domain", "id must be a number"));
                return false;
            }
        } else {
            error(_("You do not have the permission to delete a zone."));
        }
    }

    /** Record ID to Domain ID
     *
     * Gets the id of the domain by a given record id
     *
     * @param int $id Record ID
     * @return int Domain ID of record
     */
    public static function recid_to_domid($id)
    {
        global $db;
        if (is_numeric($id)) {
            $result = $db->query("SELECT domain_id FROM records WHERE id=" . $db->quote($id, 'integer'));
            $r = $result->fetch();
            return $r["domain_id"];
        } else {
            error(sprintf(_('Invalid argument(s) given to function %s %s'), "recid_to_domid", "id must be a number"));
        }
    }

    /** Change owner of a domain
     *
     * @param int $zone_id Zone ID
     * @param int $user_id User ID
     *
     * @return boolean true when succesful
     */
    public static function add_owner_to_zone($zone_id, $user_id)
    {
        global $db;
        if ((do_hook('verify_permission', 'zone_meta_edit_others')) || (do_hook('verify_permission', 'zone_meta_edit_own')) && do_hook('verify_user_is_owner_zoneid', $_GET["id"])) {
            if (is_numeric($zone_id) && is_numeric($user_id) && do_hook('is_valid_user', $user_id)) {
                if ($db->queryOne("SELECT COUNT(id) FROM zones WHERE owner=" . $db->quote($user_id, 'integer') . " AND domain_id=" . $db->quote($zone_id, 'integer')) == 0) {
                    $zone_templ_id = self::get_zone_template($zone_id);
                    if ($zone_templ_id == NULL) {
                        $zone_templ_id = 0;
                    }
                    $stmt = $db->prepare("INSERT INTO zones (domain_id, owner, zone_templ_id) VALUES(:zone_id, :user_id, :zone_templ_id)");
                    $stmt->execute([
                        "zone_id" => $zone_id,
                        "user_id" => $user_id,
                        "zone_templ_id" => $zone_templ_id,
                    ]);
                    return true;
                } else {
                    error(_('The selected user already owns the zone.'));
                    return false;
                }
            } else {
                error(sprintf(_('Invalid argument(s) given to function %s %s'), "add_owner_to_zone", "$zone_id / $user_id"));
            }
        } else {
            return false;
        }
    }

    /** Delete owner from zone
     *
     * @param int $zone_id Zone ID
     * @param int $user_id User ID
     *
     * @return boolean true on success
     */
    public static function delete_owner_from_zone($zone_id, $user_id)
    {
        global $db;
        if ((do_hook('verify_permission', 'zone_meta_edit_others')) || (do_hook('verify_permission', 'zone_meta_edit_own')) && do_hook('verify_user_is_owner_zoneid', $_GET["id"])) {
            if (is_numeric($zone_id) && is_numeric($user_id) && do_hook('is_valid_user', $user_id)) {
                if ($db->queryOne("SELECT COUNT(id) FROM zones WHERE domain_id=" . $db->quote($zone_id, 'integer')) > 1) {
                    $db->query("DELETE FROM zones WHERE owner=" . $db->quote($user_id, 'integer') . " AND domain_id=" . $db->quote($zone_id, 'integer'));
                } else {
                    error(_('There must be at least one owner for a zone.'));
                }
                return true;
            } else {
                error(sprintf(_('Invalid argument(s) given to function %s %s'), "delete_owner_from_zone", "$zone_id / $user_id"));
            }
        } else {
            return false;
        }
    }

    /** Get Domain Name by domain ID
     *
     * @param int $id Domain ID
     *
     * @return string Domain name
     */
    public static function get_domain_name_by_id($id)
    {
        global $db;

        if (is_numeric($id)) {
            $result = $db->queryRow("SELECT name FROM domains WHERE id=" . $db->quote($id, 'integer'));
            if ($result) {
                return $result["name"];
            } else {
                error("Domain does not exist.");
                return false;
            }
        }

        error(sprintf(_('Invalid argument(s) given to function %s %s'), "get_domain_name_by_id", "Not a valid domainid: $id"));
    }

    /** Get Zone Name from Zone ID
     *
     * @param int $zid Zone ID
     *
     * @return string Domain name
     */
    public static function get_domain_name_by_zone_id($zid)
    {
        global $db;

        if (is_numeric($zid)) {
            $result = $db->queryRow("SELECT domains.name as name from domains LEFT JOIN zones ON domains.id=zones.domain_id WHERE zones.id = " . $db->quote($zid, 'integer'));
            if ($result) {
                return $result["name"];
            } else {
                error("Zone does not exist.");
                return false;
            }
        }

        error(sprintf(_('Invalid argument(s) given to function %s %s'), "get_domain_name_by_zone_id", "Not a valid zoneid: $zid"));
    }

    /** Get zone id from name
     *
     * @param string $zname Zone name
     * @return int Zone ID
     */
    public static function get_zone_id_from_name($zname)
    {
        global $db;

        if (!empty($zname)) {
            $result = $db->queryRow("SELECT id FROM domains WHERE name=" . $db->quote($zname, 'text'));
            if ($result) {
                return $result["id"];
            } else {
                error("Zone does not exist.");
                return false;
            }
        } else {
            error(sprintf(_('Invalid argument(s) given to function %s %s'), "get_zone_id_from_name", "Not a valid domainname: $zname"));
        }
    }

    /** Get Zone details from Zone ID
     *
     * @param int $zid Zone ID
     * @return mixed[] array of zone details [type,name,master_ip,record_count]
     */
    public static function get_zone_info_from_id($zid)
    {
        $perm_view = Permission::getViewPermission();

        if ($perm_view == "none") {
            error(_("You do not have the permission to view this zone."));
        } else {
            global $db;

            $query = "SELECT domains.type AS type,
					domains.name AS name,
					domains.master AS master_ip,
					count(records.domain_id) AS record_count
					FROM domains LEFT OUTER JOIN records ON domains.id = records.domain_id
					WHERE domains.id = " . $db->quote($zid, 'integer') . "
					GROUP BY domains.id, domains.type, domains.name, domains.master";
            $result = $db->queryRow($query);
            return array(
                "id" => $zid,
                "name" => $result['name'],
                "type" => $result['type'],
                "master_ip" => $result['master_ip'],
                "record_count" => $result['record_count']
            );
        }
    }

    /** Get Zone(s) details from Zone IDs
     *
     * @param array $zones Zone IDs
     * @return array
     */
    public static function get_zone_info_from_ids(array $zones): array
    {
        $zone_infos = array();
        foreach ($zones as $zone) {
            $zone_info = DnsRecord::get_zone_info_from_id($zone);
            $zone_infos[] = $zone_info;
        }
        return $zone_infos;
    }

    /** Convert IPv6 Address to PTR
     *
     * @param string $ip IPv6 Address
     * @return string PTR form of address
     */
    public static function convert_ipv6addr_to_ptrrec($ip)
    {
// rev-patch
// taken from: http://stackoverflow.com/questions/6619682/convert-ipv6-to-nibble-format-for-ptr-records
// PHP (>= 5.1.0, or 5.3+ on Windows), use the inet_pton
//      $ip = '2001:db8::567:89ab';

        $addr = inet_pton($ip);
        $unpack = unpack('H*hex', $addr);
        $hex = $unpack['hex'];
        return implode('.', array_reverse(str_split($hex))) . '.ip6.arpa';
    }

    /** Get Best Matching in-addr.arpa Zone ID from Domain Name
     *
     * @param string $domain Domain name
     *
     * @return int Zone ID
     */
    public static function get_best_matching_zone_id_from_name($domain)
    {
// rev-patch
// tring to find the correct zone
// %ip6.arpa and %in-addr.arpa is looked for

        global $db;

        $match = 72; // the longest ip6.arpa has a length of 72
        $found_domain_id = -1;

        // get all reverse-zones
        $query = "SELECT name, id FROM domains
                   WHERE name like " . $db->quote('%.arpa', 'text') . "
                   ORDER BY length(name) DESC";

        $response = $db->query($query);
        if ($response) {
            while ($r = $response->fetch()) {
                $pos = stripos($domain, $r["name"]);
                if ($pos !== false) {
                    // one possible searched $domain is found
                    if ($pos < $match) {
                        $match = $pos;
                        $found_domain_id = $r["id"];
                    }
                }
            }
        } else {
            return -1;
        }
        return $found_domain_id;
    }

    /** Check if Domain Exists
     *
     * Check if a domain is already existing.
     *
     * @param string $domain Domain name
     * @return boolean true if existing, false if it doesn't exist.
     */
    public static function domain_exists($domain)
    {
        global $db;

        if (Dns::is_valid_hostname_fqdn($domain, 0)) {
            $result = $db->queryRow("SELECT id FROM domains WHERE name=" . $db->quote($domain, 'text'));
            return ($result ? true : false);
        } else {
            error(_('This is an invalid zone name.'));
        }
    }

    /** Get All Supermasters
     *
     * Gets an array of arrays of supermaster details
     *
     * @return array[] supermasters detail [master_ip,ns_name,account]s
     */
    public static function get_supermasters()
    {
        global $db;

        $result = $db->query("SELECT ip, nameserver, account FROM supermasters");

        $supermasters = array();

        while ($r = $result->fetch()) {
            $supermasters[] = array(
                "master_ip" => $r["ip"],
                "ns_name" => $r["nameserver"],
                "account" => $r["account"],
            );
        }
        return $supermasters;
    }

    /** Check if Supermaster IP address exists
     *
     * @param string $master_ip Supermaster IP
     *
     * @return boolean true if exists, otherwise false
     */
    public static function supermaster_exists($master_ip)
    {
        global $db;
        if (Dns::is_valid_ipv4($master_ip, false) || Dns::is_valid_ipv6($master_ip)) {
            $result = $db->queryOne("SELECT ip FROM supermasters WHERE ip = " . $db->quote($master_ip, 'text'));
            return ($result ? true : false);
        } else {
            error(sprintf(_('Invalid argument(s) given to function %s %s'), "supermaster_exists", "No or no valid IPv4 or IPv6 address given."));
        }
    }

    /** Check if Supermaster IP Address and NS Name combo exists
     *
     * @param string $master_ip Supermaster IP Address
     * @param string $ns_name Supermaster NS Name
     *
     * @return boolean true if exists, false otherwise
     */
    public static function supermaster_ip_name_exists($master_ip, $ns_name)
    {
        global $db;
        if ((Dns::is_valid_ipv4($master_ip) || Dns::is_valid_ipv6($master_ip)) && Dns::is_valid_hostname_fqdn($ns_name, 0)) {
            $result = $db->queryOne("SELECT ip FROM supermasters WHERE ip = " . $db->quote($master_ip, 'text') .
                " AND nameserver = " . $db->quote($ns_name, 'text'));
            return ($result ? true : false);
        } else {
            error(sprintf(_('Invalid argument(s) given to function %s %s'), "supermaster_exists", "No or no valid IPv4 or IPv6 address given."));
        }
    }

    /** Get Zones
     *
     * @param string $perm View Zone Permissions ['own','all','none']
     * @param int $userid Requesting User ID
     * @param string $letterstart Starting letters to match [default='all']
     * @param int $rowstart Start from row in set [default=0]
     * @param int $rowamount Max number of rows to fetch for this query when not 'all' [default=999999]
     * @param string $sortby Column to sort results by [default='name']
     *
     * @return boolean|mixed[] false or array of zone details [id,name,type,count_records]
     */
    public static function get_zones($perm, $userid = 0, $letterstart = 'all', $rowstart = 0, $rowamount = 999999, $sortby = 'name')
    {
        global $db;
        global $db_type;
        global $sql_regexp;
        global $pdnssec_use;
        global $iface_zone_comments;
        global $iface_zonelist_serial;
        global $iface_zonelist_template;

        if ($letterstart == '_') {
            $letterstart = '\_';
        }

        $sql_add = '';
        if ($perm != "own" && $perm != "all") {
            error(_("You do not have the permission to view this zone."));
            return false;
        } else {
            if ($perm == "own") {
                $sql_add = " AND zones.domain_id = domains.id AND zones.owner = " . $db->quote($userid, 'integer');
            }
            if ($letterstart != 'all' && $letterstart != 1) {
                $sql_add .= " AND " . DbCompat::substr($db_type) . "(domains.name,1,1) = " . $db->quote($letterstart, 'text') . " ";
            } elseif ($letterstart == 1) {
                $sql_add .= " AND " . DbCompat::substr($db_type) . "(domains.name,1,1) " . $sql_regexp . " '[0-9]'";
            }
        }

        if ($sortby == 'owner') {
            $sortby = 'users.username';
        } elseif ($sortby != 'count_records') {
            $sortby = 'domains.' . $sortby;
        }

        $natural_sort = 'domains.name';
        if ($db_type == 'mysql' || $db_type == 'mysqli' || $db_type == 'sqlite' || $db_type == 'sqlite3') {
            $natural_sort = 'domains.name+0<>0 DESC, domains.name+0, domains.name';
        } elseif ($db_type == 'pgsql') {
            $natural_sort = "SUBSTRING(domains.name FROM '\.arpa$'), LENGTH(SUBSTRING(domains.name FROM '^[0-9]+')), domains.name";
        }
        $sql_sortby = ($sortby == 'domains.name' ? $natural_sort : $sortby . ', ' . $natural_sort);

        $query = "SELECT domains.id,
                        domains.name,
                        domains.type,
                        COUNT(records.id) AS count_records,
                        users.username,
                        users.fullname
                        " . ($pdnssec_use ? ", COUNT(cryptokeys.id) > 0 OR COUNT(domainmetadata.id) > 0 AS secured" : "") . "
                        " . ($iface_zone_comments ? ", zones.comment" : "") . "
                        FROM domains
                        LEFT JOIN zones ON domains.id=zones.domain_id
                        LEFT JOIN records ON records.domain_id=domains.id AND records.type IS NOT NULL
                        LEFT JOIN users ON users.id=zones.owner";

        if ($pdnssec_use) {
            $query .= " LEFT JOIN cryptokeys ON domains.id = cryptokeys.domain_id AND cryptokeys.active
                        LEFT JOIN domainmetadata ON domains.id = domainmetadata.domain_id AND domainmetadata.kind = 'PRESIGNED'";
        }

        $query .= " WHERE 1=1" . $sql_add . "
                    GROUP BY domains.name, domains.id, domains.type, users.username, users.fullname
                    " . ($iface_zone_comments ? ", zones.comment" : "") . "
                    ORDER BY " . $sql_sortby;

        if ($letterstart != 'all') {
            $db->setLimit($rowamount, $rowstart);
        }
        $result = $db->query($query);

        $ret = array();
        while ($r = $result->fetch()) {
            //FIXME: name is not guaranteed to be unique with round-robin record sets
            $ret[$r["name"]]["id"] = $r["id"];
            $ret[$r["name"]]["name"] = $r["name"];
            $ret[$r["name"]]["utf8_name"] = idn_to_utf8(htmlspecialchars($r["name"]), IDNA_NONTRANSITIONAL_TO_ASCII);
            $ret[$r["name"]]["type"] = $r["type"];
            $ret[$r["name"]]["count_records"] = $r["count_records"];
            $ret[$r["name"]]["comment"] = $r["comment"] ?: '';
            $ret[$r["name"]]["owners"][] = $r["username"];
            $ret[$r["name"]]["full_names"][] = $r["fullname"] ?: '';
            $ret[$r["name"]]["users"][] = $r["username"];

            if ($pdnssec_use) {
                $ret[$r["name"]]["secured"] = $r["secured"];
            }

            if ($iface_zonelist_serial) {
                $ret[$r["name"]]["serial"] = self::get_serial_by_zid($r["id"]);
            }

            if ($iface_zonelist_template) {
                $ret[$r["name"]]["template"] = ZoneTemplate::get_zone_templ_name($r["id"]);
            }
        }

        return $ret;
    }

// TODO: letterstart limitation and userid permission limitiation should be applied at the same time?
// fixme: letterstart 'all' forbids searching for domains that actually start with 'all'
    /** Get Count of Zones
     *
     * @param string $perm 'all', 'own' uses session 'userid'
     * @param string $letterstart Starting letters to match [default='all']
     *
     * @return array|bool|string Count of zones matched
     */
    public static function zone_count_ng($perm, $letterstart = 'all')
    {
        global $db;
        global $db_type;
        global $sql_regexp;

        $tables = 'domains';
        $query_addon = '';

        if ($perm != "own" && $perm != "all") {
            return "0";
        }

        if ($perm == "own") {
            $query_addon = " AND zones.domain_id = domains.id
                AND zones.owner = " . $db->quote($_SESSION['userid'], 'integer');
            $tables .= ', zones';
        }

        if ($letterstart != 'all' && $letterstart != 1) {
            $query_addon .= " AND domains.name LIKE " . $db->quote($letterstart . "%", 'text') . " ";
        } elseif ($letterstart == 1) {
            $query_addon .= " AND " . DbCompat::substr($db_type) . "(domains.name,1,1) " . $sql_regexp . " '[0-9]'";
        }

        $query = "SELECT COUNT(domains.id) AS count_zones FROM {$tables} WHERE 1=1 {$query_addon}";

        return $db->queryOne($query);
    }

    /** Get a Record from a Record ID
     *
     * Retrieve all fields of the record and send it back to the function caller.
     *
     * @param int $id Record ID
     * @return int|mixed[] array of record detail, or -1 if nothing found
     */
    public static function get_record_from_id($id)
    {
        global $db;
        if (is_numeric($id)) {
            $result = $db->queryRow("SELECT id, domain_id, name, type, content, ttl, prio FROM records WHERE id=" . $db->quote($id, 'integer') . " AND type IS NOT NULL");
            if ($result) {
                if ($result["type"] == "" || $result["content"] == "") {
                    return -1;
                }

                return array(
                    "id" => $result["id"],
                    "domain_id" => $result["domain_id"],
                    "name" => $result["name"],
                    "type" => $result["type"],
                    "content" => $result["content"],
                    "ttl" => $result["ttl"],
                    "prio" => $result["prio"]
                );
            } else {
                return -1;
            }
        } else {
            error(sprintf(_('Invalid argument(s) given to function %s'), "get_record_from_id"));
        }
    }

    /** Get all records from a domain id.
     *
     * Retrieve all fields of the records and send it back to the function caller.
     *
     * @param int $id Domain ID
     * @param int $rowstart Starting row [default=0]
     * @param int $rowamount Number of rows to return in this query [default=999999]
     * @param string $sortby Column to sort by [default='name']
     *
     * @return int|mixed[] array of record detail, or -1 if nothing found
     */
    public static function get_records_from_domain_id($id, $rowstart = 0, $rowamount = 999999, $sortby = 'name')
    {
        global $db;
        global $db_type;

        if (!is_numeric($id)) {
            error(sprintf(_('Invalid argument(s) given to function %s'), "get_records_from_domain_id"));
            return -1;
        }

        $db->setLimit($rowamount, $rowstart);
        $natural_sort = 'records.name';
        if ($db_type == 'mysql' || $db_type == 'mysqli' || $db_type == 'sqlite' || $db_type == 'sqlite3') {
            $natural_sort = 'records.name+0<>0 DESC, records.name+0, records.name';
        }
        $sql_sortby = ($sortby == 'name' ? $natural_sort : $sortby . ', ' . $natural_sort);

        $records = $db->query("SELECT id, domain_id, name, type, content, ttl, prio
                            FROM records
                            WHERE domain_id=" . $db->quote($id, 'integer') . " AND type IS NOT NULL
                            ORDER BY type = 'SOA' DESC, type = 'NS' DESC," . $sql_sortby);

        if ($records) {
            $result = $records->fetchAll();
        } else {
            return -1;
        }

        return self::order_domain_results($result, $sortby);
    }

    /** Sort Domain Records intelligently
     *
     * @param string[] $domains Array of domains
     * @param string $sortby Column to sort by [default='name','type','content','prio','ttl']
     *
     * @return mixed[] array of records detail
     */
    public static function order_domain_results($domains, $sortby)
    {
        $results = array();
        $soa = array();
        $ns = array();

        foreach ($domains as $key => $domain) {
            switch ($domain['type']) {
                case 'SOA':
                    $soa[] = $domain;
                    unset($domains[$key]);
                    break;
                case 'NS':
                    $ns[] = $domain;
                    unset($domains[$key]);
                    break;
            }
        }

        switch ($sortby) {
            case 'id':
                usort($domains, 'self::sort_domain_results_by_id');
                break;
            case 'name':
                usort($domains, 'self::sort_domain_results_by_name');
                break;
            case 'type':
                usort($domains, 'self::sort_domain_results_by_type');
                break;
            case 'content':
                usort($domains, 'self::sort_domain_results_by_content');
                break;
            case 'prio':
                usort($domains, 'self::sort_domain_results_by_prio');
                break;
            case 'ttl':
                usort($domains, 'self::sort_domain_results_by_ttl');
                break;
            default:
                usort($domains, 'self::sort_domain_results_by_name');
                break;
        }

        $results = array_merge($soa, $ns);
        return array_merge($results, $domains);
    }

    /** Sort records by id
     *
     * @param mixed[] $a A
     * @param mixed[] $b B
     *
     * @return int result of strnatcmp
     */
    public static function sort_domain_results_by_id($a, $b)
    {
        return strnatcmp($a['id'], $b['id']);
    }

    /** Sort records by name
     *
     * @param mixed[] $a A
     * @param mixed[] $b B
     *
     * @return int result of strnatcmp
     */
    public static function sort_domain_results_by_name($a, $b)
    {
        return strnatcmp($a['name'], $b['name']);
    }

    /** Sort records by type
     *
     * @param mixed[] $a A
     * @param mixed[] $b B
     *
     * @return int result of strnatcmp
     */
    public static function sort_domain_results_by_type($a, $b)
    {
        if ($a['type'] != $b['type']) {
            return strnatcmp($a['type'], $b['type']);
        } else {
            return strnatcmp($a['name'], $b['name']);
        }
    }

    /** Sort records by content
     *
     * @param mixed[] $a A
     * @param mixed[] $b B
     *
     * @return int result of strnatcmp
     */
    public static function sort_domain_results_by_content($a, $b)
    {
        if ($a['content'] != $b['content']) {
            return strnatcmp($a['content'], $b['content']);
        } else {
            return strnatcmp($a['name'], $b['name']);
        }
    }

    /** Sort records by prio
     *
     * @param mixed[] $a A
     * @param mixed[] $b B
     *
     * @return int result of strnatcmp
     */
    public static function sort_domain_results_by_prio($a, $b)
    {
        if ($a['prio'] != $b['prio']) {
            return strnatcmp($a['prio'], $b['prio']);
        } else {
            return strnatcmp($a['name'], $b['name']);
        }
    }

    /** Sort records by TTL
     *
     * @param mixed[] $a A
     * @param mixed[] $b B
     *
     * @return int result of strnatcmp
     */
    public static function sort_domain_results_by_ttl($a, $b)
    {
        if ($a['ttl'] != $b['ttl']) {
            return strnatcmp($a['ttl'], $b['ttl']);
        } else {
            return strnatcmp($a['name'], $b['name']);
        }
    }

    /** Get list of owners for Domain ID
     *
     * @param int $id Domain ID
     *
     * @return mixed[] array of owners [id,fullname]
     */
    public static function get_users_from_domain_id($id)
    {
        global $db;
        $owners = array();

        $sqlq = "SELECT owner FROM zones WHERE domain_id =" . $db->quote($id, 'integer');
        $id_owners = $db->query($sqlq);
        if ($id_owners) {
            while ($r = $id_owners->fetch()) {
                $result = $db->queryRow("SELECT username, fullname FROM users WHERE id=" . $r['owner']);
                $owners[] = array(
                    "id" => $r['owner'],
                    "fullname" => $result["fullname"],
                    "username" => $result["username"],
                );
            }
        } else {
            return -1;
        }
        return $owners;
    }

    /** Get Domain Type for Domain ID
     *
     * @param int $id Domain ID
     *
     * @return string Domain Type [NATIVE,MASTER,SLAVE]
     */
    public static function get_domain_type($id)
    {
        global $db;
        if (is_numeric($id)) {
            $type = $db->queryOne("SELECT type FROM domains WHERE id = " . $db->quote($id, 'integer'));
            if ($type == "") {
                $type = "NATIVE";
            }
            return $type;
        } else {
            error(sprintf(_('Invalid argument(s) given to function %s'), "get_record_from_id", "no or no valid zoneid given"));
        }
    }

    /** Get Slave Domain's Master
     *
     * @param int $id Domain ID
     *
     * @return array|bool|void Master server
     */
    public static function get_domain_slave_master($id)
    {
        global $db;
        if (is_numeric($id)) {
            return $db->queryOne("SELECT master FROM domains WHERE type = 'SLAVE' and id = " . $db->quote($id, 'integer'));
        } else {
            error(sprintf(_('Invalid argument(s) given to function %s'), "get_domain_slave_master", "no or no valid zoneid given"));
        }
    }

    /** Change Zone Type
     *
     * @param string $type New Zone Type [NATIVE,MASTER,SLAVE]
     * @param int $id Zone ID
     *
     * @return null
     */
    public static function change_zone_type($type, $id)
    {
        global $db;
        $add = '';
        if (is_numeric($id)) {
            // It is not really necessary to clear the field that contains the IP address
            // of the master if the type changes from slave to something else. PowerDNS will
            // ignore the field if the type isn't something else then slave. But then again,
            // it's much clearer this way.
            if ($type != "SLAVE") {
                $add = ", master=" . $db->quote('', 'text');
            }
            $result = $db->query("UPDATE domains SET type = " . $db->quote($type, 'text') . $add . " WHERE id = " . $db->quote($id, 'integer'));
        } else {
            error(sprintf(_('Invalid argument(s) given to function %s'), "change_domain_type", "no or no valid zoneid given"));
        }
    }

    /** Change Slave Zone's Master IP Address
     *
     * @param int $zone_id Zone ID
     * @param string $ip_slave_master Master IP Address
     *
     * @return null
     */
    public static function change_zone_slave_master($zone_id, $ip_slave_master)
    {
        global $db;
        if (is_numeric($zone_id)) {
            if (Dns::are_multiple_valid_ips($ip_slave_master)) {
                $stmt = $db->prepare("UPDATE domains SET master = ? WHERE id = ?");
                $stmt->execute(array($ip_slave_master, $zone_id));
            } else {
                error(sprintf(_('Invalid argument(s) given to function %s %s'), "change_zone_slave_master", "This is not a valid IPv4 or IPv6 address: $ip_slave_master"));
            }
        } else {
            error(sprintf(_('Invalid argument(s) given to function %s'), "change_zone_slave_master", "no or no valid zoneid given"));
        }
    }

    /** Get Serial for Zone ID
     *
     * @param int $zid Zone ID
     *
     * @return boolean|string Serial Number or false if not found
     */
    public static function get_serial_by_zid($zid)
    {
        global $db;
        if (is_numeric($zid)) {
            $query = "SELECT content FROM records where TYPE = " . $db->quote('SOA', 'text') . " and domain_id = " . $db->quote($zid, 'integer');
            $rr_soa = $db->queryOne($query);
            $rr_soa_fields = explode(" ", $rr_soa);
        } else {
            error(sprintf(_('Invalid argument(s) given to function %s %s'), "get_serial_by_zid", "id must be a number"));
            return false;
        }
        return $rr_soa_fields[2] ?? '';
    }

    /** Validate Account is valid string
     *
     * @param string $account Account name alphanumeric and ._-
     *
     * @return boolean true is valid, false otherwise
     */
    public static function validate_account($account)
    {
        if (preg_match("/^[A-Z0-9._-]+$/i", $account)) {
            return true;
        } else {
            return false;
        }
    }

    /** Get Zone Template ID for Zone ID
     *
     * @param int $zone_id Zone ID
     *
     * @return array|bool Zone Template ID
     */
    public static function get_zone_template($zone_id)
    {
        global $db;
        $query = "SELECT zone_templ_id FROM zones WHERE domain_id = " . $db->quote($zone_id, 'integer');
        return $db->queryOne($query);
    }

    /** Update All Zone Records for Zone ID with Zone Template
     *
     * @param int $zone_id Zone ID to update
     * @param int $zone_template_id Zone Template ID to use for update
     *
     * @return null
     */
    public static function update_zone_records($zone_id, $zone_template_id)
    {
        global $db;
        global $dns_ttl;
        global $db_type;

        $perm_edit = Permission::getEditPermission();
        $user_is_zone_owner = do_hook('verify_user_is_owner_zoneid', $zone_id);

        if (do_hook('verify_permission', 'zone_master_add')) {
            $zone_master_add = "1";
        }

        if (do_hook('verify_permission', 'zone_slave_add')) {
            $zone_slave_add = "1";
        }

        $soa_rec = self::get_soa_record($zone_id);
        $db->beginTransaction();

        if ($zone_template_id != 0) {
            if ($perm_edit == "all" || ($perm_edit == "own" && $user_is_zone_owner == "1")) {
                if (is_numeric($zone_id)) {
                    if ($db_type == 'pgsql') {
                        $query = "DELETE FROM records r USING records_zone_templ rzt WHERE rzt.domain_id = :zone_id AND rzt.zone_templ_id = :zone_template_id AND r.id = rzt.record_id";
                    } else {
                        $query = "DELETE r, rzt FROM records r LEFT JOIN records_zone_templ rzt ON r.id = rzt.record_id WHERE rzt.domain_id = :zone_id AND rzt.zone_templ_id = :zone_template_id";
                    }
                    $stmt = $db->prepare($query);
                    $stmt->execute(array(':zone_id' => $zone_id, ':zone_template_id' => $zone_template_id));
                } else {
                    error(sprintf(_('Invalid argument(s) given to function %s %s'), "delete_domain", "id must be a number"));
                }
            } else {
                error(_("You do not have the permission to delete a zone."));
            }
            if ($zone_master_add == "1" || $zone_slave_add == "1") {
                $domain = self::get_domain_name_by_id($zone_id);
                $templ_records = ZoneTemplate::get_zone_templ_records($zone_template_id);

                foreach ($templ_records as $r) {
                    //fixme: appears to be a bug and regex match should occur against $domain
                    if ((preg_match('/in-addr.arpa/i', $zone_id) && ($r["type"] == "NS" || $r["type"] == "SOA")) || (!preg_match('/in-addr.arpa/i', $zone_id))) {
                        $name = ZoneTemplate::parse_template_value($r["name"], $domain);
                        $type = $r["type"];
                        if ($type == "SOA") {
                            $db->exec("DELETE FROM records WHERE domain_id = " . $db->quote($zone_id, 'integer') . " AND type = 'SOA'");
                            $content = self::get_updated_soa_record($soa_rec);
                            if ($content == "") {
                                $content = ZoneTemplate::parse_template_value($r["content"], $domain);
                            }
                        } else {
                            $content = ZoneTemplate::parse_template_value($r["content"], $domain);
                        }

                        $ttl = $r["ttl"];
                        $prio = intval($r["prio"]);

                        if (!$ttl) {
                            $ttl = $dns_ttl;
                        }

                        $query = "INSERT INTO records (domain_id, name, type, content, ttl, prio) VALUES ("
                            . $db->quote($zone_id, 'integer') . ","
                            . $db->quote($name, 'text') . ","
                            . $db->quote($type, 'text') . ","
                            . $db->quote($content, 'text') . ","
                            . $db->quote($ttl, 'integer') . ","
                            . $db->quote($prio, 'integer') . ")";
                        $db->exec($query);

                        if ($db_type == 'pgsql') {
                            $record_id = $db->lastInsertId('records_id_seq');
                        } else {
                            $record_id = $db->lastInsertId();
                        }

                        $query = "INSERT INTO records_zone_templ (domain_id, record_id, zone_templ_id) VALUES ("
                            . $db->quote($zone_id, 'integer') . ","
                            . $db->quote($record_id, 'integer') . ","
                            . $db->quote($zone_template_id, 'integer') . ")";
                        $db->query($query);
                    }
                }
            }
        }

        $query = "UPDATE zones
                    SET zone_templ_id = " . $db->quote($zone_template_id, 'integer') . "
                    WHERE domain_id = " . $db->quote($zone_id, 'integer');
        $db->exec($query);
        $db->commit();
    }

    /** Delete array of domains
     *
     * Deletes a domain by a given id.
     * Function always succeeds. If the field is not found in the database, that's what we want anyway.
     *
     * @param int[] $domains Array of Domain IDs to delete
     *
     * @return boolean true on success, false otherwise
     */
    public static function delete_domains($domains)
    {
        global $db;
        global $pdnssec_use;

        $db->beginTransaction();

        foreach ($domains as $id) {
            $perm_edit = Permission::getEditPermission();
            $user_is_zone_owner = do_hook('verify_user_is_owner_zoneid', $id);

            if ($perm_edit == "all" || ($perm_edit == "own" && $user_is_zone_owner == "1")) {
                if (is_numeric($id)) {
                    $zone_type = self::get_domain_type($id);
                    if ($pdnssec_use && $zone_type == 'MASTER') {
                        global $pdns_api_url;
                        global $pdns_api_key;

                        $dnssecProvider = DnssecProviderFactory::create(
                            new FakeConfiguration($pdns_api_url, $pdns_api_key)
                        );

                        $zone_name = DnsRecord::get_domain_name_by_id($id);
                        if ($dnssecProvider->isZoneSecured($zone_name)) {
                            $dnssecProvider->unsecureZone($zone_name);
                        }
                    }

                    $db->exec("DELETE FROM zones WHERE domain_id=" . $db->quote($id, 'integer'));
                    $db->exec("DELETE FROM records WHERE domain_id=" . $db->quote($id, 'integer'));
                    $db->query("DELETE FROM records_zone_templ WHERE domain_id=" . $db->quote($id, 'integer'));
                    $db->exec("DELETE FROM domains WHERE id=" . $db->quote($id, 'integer'));
                } else {
                    error(sprintf(_('Invalid argument(s) given to function %s %s'), "delete_domains", "id must be a number"));
                }
            } else {
                error(_("You do not have the permission to delete a zone."));
            }
        }

        $db->commit();

        return true;
    }

    /** Check if record exists
     *
     * @param string $name Record name
     *
     * @return boolean true on success, false on failure
     */
    public static function record_name_exists($name)
    {
        global $db;
        $query = "SELECT COUNT(id) FROM records WHERE name = " . $db->quote($name, 'text');
        $count = $db->queryOne($query);
        return $count > 0;
    }

    /** Return domain level for given name
     *
     * @param string $name Zone name
     *
     * @return int domain level
     */
    public static function get_domain_level($name)
    {
        return substr_count($name, '.') + 1;
    }

    /** Return domain second level domain for given name
     *
     * @param string $name Zone name
     *
     * @return string 2nd level domain name
     */
    public static function get_second_level_domain($name)
    {
        $domain_parts = explode('.', $name);
        $domain_parts = array_reverse($domain_parts);
        return $domain_parts[1] . '.' . $domain_parts[0];
    }

    /** Set timezone
     *
     * Set timezone to configured tz or UTC it not set
     *
     * @return null
     */
    public static function set_timezone()
    {
        global $timezone;

        if (isset($timezone)) {
            date_default_timezone_set($timezone);
        } else if (!ini_get('date.timezone')) {
            date_default_timezone_set('UTC');
        }
    }
}