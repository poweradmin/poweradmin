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

namespace Poweradmin\Domain\Service;

use PDO;
use Poweradmin\AppConfiguration;
use Poweradmin\Application\Presenter\ErrorPresenter;
use Poweradmin\Application\Service\DnssecProviderFactory;
use Poweradmin\Domain\Error\ErrorMessage;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Model\ZoneTemplate;
use Poweradmin\Infrastructure\Configuration\FakeConfiguration;
use Poweradmin\Infrastructure\Database\DbCompat;
use Poweradmin\Infrastructure\Database\PDOLayer;
use Poweradmin\Infrastructure\Utility\SortHelper;

/**
 * DNS record functions
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class DnsRecord
{
    private AppConfiguration $config;
    private PDOLayer $db;

    public function __construct(PDOLayer $db, AppConfiguration $config) {
        $this->db = $db;
        $this->config = $config;
    }

    /** Check if Zone ID exists
     *
     * @param int $zid Zone ID
     *
     * @return int Domain count or false on failure
     */
    public function zone_id_exists(int $zid): int
    {
        $pdns_db_name = $this->config->get('pdns_db_name');
        $domains_table = $pdns_db_name ? $pdns_db_name . '.domains' : 'domains';

        $query = "SELECT COUNT(id) FROM $domains_table WHERE id = " . $this->db->quote($zid, 'integer');
        return $this->db->queryOne($query);
    }

    /** Get Zone ID from Record ID
     *
     * @param int $rid Record ID
     *
     * @return int Zone ID
     */
    public function get_zone_id_from_record_id(int $rid): int
    {
        $pdns_db_name = $this->config->get('pdns_db_name');
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

        $query = "SELECT domain_id FROM $records_table WHERE id = " . $this->db->quote($rid, 'integer');
        return $this->db->queryOne($query);
    }

    /** Count Zone Records for Zone ID
     *
     * @param int $zone_id Zone ID
     *
     * @return int Record count
     */
    public function count_zone_records(int $zone_id): int
    {
        $pdns_db_name = $this->config->get('pdns_db_name');
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

        $sqlq = "SELECT COUNT(id) FROM $records_table WHERE domain_id = " . $this->db->quote($zone_id, 'integer') . " AND type IS NOT NULL";
        return $this->db->queryOne($sqlq);
    }

    /** Get SOA record content for Zone ID
     *
     * @param int $zone_id Zone ID
     *
     * @return string SOA content
     */
    public function get_soa_record(int $zone_id): string
    {
        $pdns_db_name = $this->config->get('pdns_db_name');
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

        $sqlq = "SELECT content FROM $records_table WHERE type = " . $this->db->quote('SOA', 'text') . " AND domain_id = " . $this->db->quote($zone_id, 'integer');
        return $this->db->queryOne($sqlq);
    }

    /** Get SOA Serial Number
     *
     * @param string $soa_rec SOA record content
     *
     * @return string|null SOA serial
     */
    public static function get_soa_serial(string $soa_rec): ?string
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
    public function get_next_serial(int|string $curr_serial): int|string
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

        $this->set_timezone();
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
    public function update_soa_record(int $domain_id, string $content): bool
    {
        $pdns_db_name = $this->config->get('pdns_db_name');
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

        $sqlq = "UPDATE $records_table SET content = " . $this->db->quote($content, 'text') . " WHERE domain_id = " . $this->db->quote($domain_id, 'integer') . " AND type = " . $this->db->quote('SOA', 'text');
        $this->db->query($sqlq);

        return true;
    }

    /** Set SOA serial in SOA content
     *
     * @param string $soa_rec SOA record content
     * @param string $serial New serial number
     *
     * @return string Updated SOA record
     */
    public static function set_soa_serial(string $soa_rec, string $serial): string
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
    public function get_updated_soa_record(string $soa_rec): string
    {
        if (empty($soa_rec)) {
            return '';
        }

        $curr_serial = self::get_soa_serial($soa_rec);
        $new_serial = $this->get_next_serial($curr_serial);

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
    public function update_soa_serial(int $domain_id): bool
    {
        $soa_rec = $this->get_soa_record($domain_id);
        if ($soa_rec == NULL) {
            return false;
        }

        $curr_serial = self::get_soa_serial($soa_rec);
        $new_serial = $this->get_next_serial($curr_serial);

        if ($curr_serial != $new_serial) {
            $soa_rec = self::set_soa_serial($soa_rec, $new_serial);
            return $this->update_soa_record($domain_id, $soa_rec);
        }

        return true;
    }

    /** Get Zone comment
     *
     * @param int $zone_id Zone ID
     *
     * @return string Zone Comment
     */
    public static function get_zone_comment($db, int $zone_id): string
    {
        $query = "SELECT comment FROM zones WHERE domain_id = " . $db->quote($zone_id, 'integer');
        $comment = $db->queryOne($query);

        return $comment ?: '';
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
    public function edit_zone_comment(int $zone_id, string $comment): bool
    {
        $perm_edit = Permission::getEditPermission($this->db);

        $user_is_zone_owner = UserManager::verify_user_is_owner_zoneid($this->db, $zone_id);
        $zone_type = $this->get_domain_type($zone_id);

        if ($zone_type == "SLAVE" || $perm_edit == "none" || (($perm_edit == "own" || $perm_edit == "own_as_client") && $user_is_zone_owner == "0")) {
            $error = new ErrorMessage(_("You do not have the permission to edit this comment."));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            return false;
        } else {
            $query = "SELECT COUNT(*) FROM zones WHERE domain_id = :zone_id";
            $stmt = $this->db->prepare($query);
            $stmt->bindValue(':zone_id', $zone_id, PDO::PARAM_INT);
            $stmt->execute();

            $count = $stmt->fetchColumn();

            if ($count > 0) {
                $query = "UPDATE zones SET comment = :comment WHERE domain_id = :zone_id";
            } else {
                $query = "INSERT INTO zones (domain_id, owner, comment, zone_templ_id) VALUES (:zone_id, 1, :comment, 0)";
            }
            $stmt = $this->db->prepare($query);
            $stmt->bindValue(':zone_id', $zone_id, PDO::PARAM_INT);
            $stmt->bindValue(':comment', $comment, PDO::PARAM_STR);
            $stmt->execute();
        }
        return true;
    }

    /** Edit a record
     *
     * This function validates it if correct it inserts it into the database.
     *
     * @param array $record Record structure to update
     *
     * @return boolean true if successful
     */
    public function edit_record(array $record): bool
    {
        $dns_hostmaster = $this->config->get('dns_hostmaster');
        $perm_edit = Permission::getEditPermission($this->db);

        $user_is_zone_owner = UserManager::verify_user_is_owner_zoneid($this->db, $record['zid']);
        $zone_type = $this->get_domain_type($record['zid']);

        if ($record['type'] == 'SOA' && $perm_edit == "own_as_client") {
            $error = new ErrorMessage(_("You do not have the permission to edit this SOA record."));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            return false;
        }
        if ($record['type'] == 'NS' && $perm_edit == "own_as_client") {
            $error = new ErrorMessage(_("You do not have the permission to edit this NS record."));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            return false;
        }

        $dns = new Dns($this->db, $this->config);
        $dns_ttl = $this->config->get('dns_ttl');

        if ($zone_type == "SLAVE" || $perm_edit == "none" || (($perm_edit == "own" || $perm_edit == "own_as_client") && $user_is_zone_owner == "0")) {
            $error = new ErrorMessage(_("You do not have the permission to edit this record."));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);
        } elseif ($dns->validate_input($record['rid'], $record['zid'], $record['type'], $record['content'], $record['name'], $record['prio'], $record['ttl'], $dns_hostmaster, $dns_ttl)) {
            $name = strtolower($record['name']); // powerdns only searches for lower case records

            $pdns_db_name = $this->config->get('pdns_db_name');
            $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

            $query = "UPDATE $records_table
				SET name=" . $this->db->quote($name, 'text') . ",
				type=" . $this->db->quote($record['type'], 'text') . ",
				content=" . $this->db->quote($record['content'], 'text') . ",
				ttl=" . $this->db->quote($record['ttl'], 'integer') . ",
				prio=" . $this->db->quote($record['prio'], 'integer') . ",
				disabled=" . $this->db->quote($record['disabled'], 'integer') . "
				WHERE id=" . $this->db->quote($record['rid'], 'integer');
            $this->db->query($query);
            return true;
        }
        return false;
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
    public function add_record(int $zone_id, string $name, string $type, string $content, int $ttl, int $prio): bool
    {
        $perm_edit = Permission::getEditPermission($this->db);

        $user_is_zone_owner = UserManager::verify_user_is_owner_zoneid($this->db, $zone_id);
        $zone_type = $this->get_domain_type($zone_id);

        if ($type == 'SOA' && $perm_edit == "own_as_client") {
            $error = new ErrorMessage(_("You do not have the permission to add SOA record."));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            return false;
        }
        if ($type == 'NS' && $perm_edit == "own_as_client") {
            $error = new ErrorMessage(_("You do not have the permission to add NS record."));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            return false;
        }

        if ($zone_type == "SLAVE" || $perm_edit == "none" || (($perm_edit == "own" || $perm_edit == "own_as_client") && $user_is_zone_owner == "0")) {
            $error = new ErrorMessage(_("You do not have the permission to add a record to this zone."));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            return false;
        }

        $dns_hostmaster = $this->config->get('dns_hostmaster');
        $dns_ttl = $this->config->get('dns_ttl');

        $dns = new Dns($this->db, $this->config);
        if (!$dns->validate_input(-1, $zone_id, $type, $content, $name, $prio, $ttl, $dns_hostmaster, $dns_ttl)) {
            return false;
        }

        $this->db->beginTransaction();
        $name = strtolower($name); // powerdns only searches for lower case records

        $pdns_db_name = $this->config->get('pdns_db_name');
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

        $query = "INSERT INTO $records_table (domain_id, name, type, content, ttl, prio) VALUES (:zone_id, :name, :type, :content, :ttl, :prio)";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':zone_id', $zone_id, PDO::PARAM_INT);
        $stmt->bindValue(':name', $name, PDO::PARAM_STR);
        $stmt->bindValue(':type', $type, PDO::PARAM_STR);
        $stmt->bindValue(':content', $content, PDO::PARAM_STR);
        $stmt->bindValue(':ttl', $ttl, PDO::PARAM_INT);
        $stmt->bindValue(':prio', $prio, PDO::PARAM_INT);
        $stmt->execute();
        $this->db->commit();

        if ($type != 'SOA') {
            $this->update_soa_serial($zone_id);
        }

        $pdnssec_use = $this->config->get('pdnssec_use');
        if ($pdnssec_use) {
            $pdns_api_url = $this->config->get('pdns_api_url');
            $pdns_api_key = $this->config->get('pdns_api_key');

            $dnssecProvider = DnssecProviderFactory::create(
                $this->db,
                new FakeConfiguration($pdns_api_url, $pdns_api_key)
            );
            $dnsRecord = new DnsRecord($this->db, $this->config);
            $zone_name = $dnsRecord->get_domain_name_by_id($zone_id);
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
    public function add_supermaster(string $master_ip, string $ns_name, string $account): bool
    {
        if (!Dns::is_valid_ipv4($master_ip) && !Dns::is_valid_ipv6($master_ip)) {
            $error = new ErrorMessage(_('This is not a valid IPv4 or IPv6 address.'));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            return false;
        }

        $dns = new Dns($this->db, $this->config);
        if (!$dns->is_valid_hostname_fqdn($ns_name, 0)) {
            $error = new ErrorMessage(_('Invalid hostname.'));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            return false;
        }
        if (!self::validate_account($account)) {
            $error = new ErrorMessage(sprintf(_('Invalid argument(s) given to function %s %s'), "add_supermaster", "given account name is invalid (alpha chars only)"));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            return false;
        }

        if ($this->supermaster_ip_name_exists($master_ip, $ns_name)) {
            $error = new ErrorMessage(_('There is already a supermaster with this IP address and hostname.'));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            return false;
        } else {
            $pdns_db_name = $this->config->get('pdns_db_name');
            $supermasters_table = $pdns_db_name ? $pdns_db_name . ".supermasters" : "supermasters";

            $stmt = $this->db->prepare("INSERT INTO $supermasters_table (ip, nameserver, account) VALUES (:master_ip, :ns_name, :account)");
            $stmt->execute([
                ':master_ip' => $master_ip,
                ':ns_name' => $ns_name,
                ':account' => $account
            ]);
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
    public function delete_supermaster(string $master_ip, string $ns_name): bool
    {
        $dns = new Dns($this->db, $this->config);
        if (Dns::is_valid_ipv4($master_ip) || Dns::is_valid_ipv6($master_ip) || $dns->is_valid_hostname_fqdn($ns_name, 0)) {
            $pdns_db_name = $this->config->get('pdns_db_name');
            $supermasters_table = $pdns_db_name ? $pdns_db_name . ".supermasters" : "supermasters";

            $this->db->query("DELETE FROM $supermasters_table WHERE ip = " . $this->db->quote($master_ip, 'text') .
                " AND nameserver = " . $this->db->quote($ns_name, 'text'));
            return true;
        } else {
            $error = new ErrorMessage(sprintf(_('Invalid argument(s) given to function %s %s'), "delete_supermaster", "No or no valid ipv4 or ipv6 address given."));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);
        }
        return false;
    }

    /** Get Supermaster Info from IP
     *
     * Retrieve supermaster details from supermaster IP address
     *
     * @param string $master_ip Supermaster IP address
     *
     * @return array array of supermaster details
     */
    public function get_supermaster_info_from_ip(string $master_ip): array
    {
        if (Dns::is_valid_ipv4($master_ip) || Dns::is_valid_ipv6($master_ip)) {
            $pdns_db_name = $this->config->get('pdns_db_name');
            $supermasters_table = $pdns_db_name ? $pdns_db_name . ".supermasters" : "supermasters";

            $result = $this->db->queryRow("SELECT ip,nameserver,account FROM $supermasters_table WHERE ip = " . $this->db->quote($master_ip, 'text'));

            return array(
                "master_ip" => $result["ip"],
                "ns_name" => $result["nameserver"],
                "account" => $result["account"]
            );
        } else {
            $error = new ErrorMessage(sprintf(_('Invalid argument(s) given to function %s %s'), "get_supermaster_info_from_ip", "No or no valid ipv4 or ipv6 address given."));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);
        }
    }

    /** Get record details from Record ID
     *
     * @param int $rid Record ID
     *
     * @return array array of record details [rid,zid,name,type,content,ttl,prio]
     */
    public function get_record_details_from_record_id(int $rid): array
    {
        $pdns_db_name = $this->config->get('pdns_db_name');
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

        $query = "SELECT id AS rid, domain_id AS zid, name, type, content, ttl, prio FROM $records_table WHERE id = " . $this->db->quote($rid, 'integer');

        $response = $this->db->query($query);
        return $response->fetch();
    }

    /** Delete a record by a given record id
     *
     * @param int $rid Record ID
     *
     * @return boolean true on success
     */
    public function delete_record(int $rid): bool
    {
        $perm_edit = Permission::getEditPermission($this->db);

        $record = $this->get_record_details_from_record_id($rid);
        $user_is_zone_owner = UserManager::verify_user_is_owner_zoneid($this->db, $record['zid']);

        if ($perm_edit == "all" || (($perm_edit == "own" || $perm_edit == "own_as_client") && $user_is_zone_owner == "1")) {
            if ($record['type'] == "SOA") {
                $error = new ErrorMessage(_('You are trying to delete the SOA record. You are not allowed to remove it, unless you remove the entire zone.'));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
                return false;
            } else {
                $pdns_db_name = $this->config->get('pdns_db_name');
                $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

                $query = "DELETE FROM $records_table WHERE id = " . $this->db->quote($rid, 'integer');
                $this->db->query($query);
                return true;
            }
        } else {
            $error = new ErrorMessage(_("You do not have the permission to delete this record."));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            return false;
        }
    }

    /** Delete record reference to zone template
     *
     * @param int $rid Record ID
     *
     * @return boolean true on success
     */
    public static function delete_record_zone_templ($db, int $rid): bool
    {
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
    public function add_domain($db, string $domain, int $owner, string $type, string $slave_master, int|string $zone_template): bool
    {
        $zone_master_add = UserManager::verify_permission($db, 'zone_master_add');
        $zone_slave_add = UserManager::verify_permission($db, 'zone_slave_add');

        // TODO: make sure only one is possible if only one is enabled
        if ($zone_master_add || $zone_slave_add) {

            $dns_ns1 = $this->config->get('dns_ns1');
            $dns_hostmaster = $this->config->get('dns_hostmaster');
            $dns_ttl = $this->config->get('dns_ttl');
            $db_type = $this->config->get('db_type');

            $pdns_db_name = $this->config->get('pdns_db_name');
            $domains_table = $pdns_db_name ? $pdns_db_name . '.domains' : 'domains';
            $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

            if (($domain && $owner && $zone_template) ||
                (preg_match('/in-addr.arpa/i', $domain) && $owner && $zone_template) ||
                $type == "SLAVE" && $domain && $owner && $slave_master) {

                $stmt = $db->prepare("INSERT INTO $domains_table (name, type) VALUES (:domain, :type)");
                $stmt->bindValue(':domain', $domain, PDO::PARAM_STR);
                $stmt->bindValue(':type', $type, PDO::PARAM_STR);
                $stmt->execute();

                $domain_id = $db->lastInsertId();

                $stmt = $db->prepare("INSERT INTO zones (domain_id, owner, zone_templ_id) VALUES (:domain_id, :owner, :zone_template)");
                $stmt->bindValue(':domain_id', $domain_id, PDO::PARAM_INT);
                $stmt->bindValue(':owner', $owner, PDO::PARAM_INT);
                $stmt->bindValue(':zone_template', ($zone_template == "none") ? 0 : $zone_template, PDO::PARAM_INT);
                $stmt->execute();

                if ($type == "SLAVE") {
                    $stmt = $db->prepare("UPDATE $domains_table SET master = :slave_master WHERE id = :domain_id");
                    $stmt->bindValue(':slave_master', $slave_master, PDO::PARAM_STR);
                    $stmt->bindValue(':domain_id', $domain_id, PDO::PARAM_INT);
                    $stmt->execute();
                    return true;
                } else {
                    if ($zone_template == "none" && $domain_id) {
                        $ns1 = $dns_ns1;
                        $hm = $dns_hostmaster;
                        $ttl = $dns_ttl;

                        $this->set_timezone();
                        $serial = date("Ymd") . "00";
                        $dns_soa = $this->config->get('dns_soa');

                        $query = "INSERT INTO $records_table (domain_id, name, content, type, ttl, prio) VALUES ("
                            . $db->quote($domain_id, 'integer') . ","
                            . $db->quote($domain, 'text') . ","
                            . $db->quote($ns1 . ' ' . $hm . ' ' . $serial . ' ' . $dns_soa, 'text') . ","
                            . $db->quote('SOA', 'text') . ","
                            . $db->quote($ttl, 'integer') . ","
                            . $db->quote(0, 'integer') . ")";
                        $db->query($query);
                        return true;
                    } elseif ($domain_id && is_numeric($zone_template)) {
                        $dns_ttl = $this->config->get('dns_ttl');

                        $templ_records = ZoneTemplate::get_zone_templ_records($db, $zone_template);
                        if ($templ_records != -1) {
                            foreach ($templ_records as $r) {
                                if ((preg_match('/in-addr.arpa/i', $domain) && ($r["type"] == "NS" || $r["type"] == "SOA")) || (!preg_match('/in-addr.arpa/i', $domain))) {
                                    $zoneTemplate = new ZoneTemplate($this->db, $this->config);
                                    $name = $zoneTemplate->parse_template_value($r["name"], $domain);
                                    $type = $r["type"];
                                    $content = $zoneTemplate->parse_template_value($r["content"], $domain);
                                    $ttl = $r["ttl"];
                                    $prio = intval($r["prio"]);

                                    if (!$ttl) {
                                        $ttl = $dns_ttl;
                                    }

                                    $query = "INSERT INTO $records_table (domain_id, name, type, content, ttl, prio) VALUES ("
                                        . $db->quote($domain_id, 'integer') . ","
                                        . $db->quote($name, 'text') . ","
                                        . $db->quote($type, 'text') . ","
                                        . $db->quote($content, 'text') . ","
                                        . $db->quote($ttl, 'integer') . ","
                                        . $db->quote($prio, 'integer') . ")";
                                    $db->query($query);

                                    $record_id = $db->lastInsertId();

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
                        $error = new ErrorMessage(sprintf(_('Invalid argument(s) given to function %s %s'), "add_domain", "could not create zone"));
                        $errorPresenter = new ErrorPresenter();
                        $errorPresenter->present($error);
                    }
                }
            } else {
                $error = new ErrorMessage(sprintf(_('Invalid argument(s) given to function %s'), "add_domain"));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
        } else {
            $error = new ErrorMessage(_("You do not have the permission to add a master zone."));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

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
    public function delete_domain(int $id): bool
    {
        $perm_edit = Permission::getEditPermission($this->db);
        $user_is_zone_owner = UserManager::verify_user_is_owner_zoneid($this->db, $id);

        $pdns_db_name = $this->config->get('pdns_db_name');
        $domains_table = $pdns_db_name ? $pdns_db_name . '.domains' : 'domains';
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

        if ($perm_edit == "all" || ($perm_edit == "own" && $user_is_zone_owner == "1")) {
            $this->db->query("DELETE FROM zones WHERE domain_id=" . $this->db->quote($id, 'integer'));
            $this->db->query("DELETE FROM $records_table WHERE domain_id=" . $this->db->quote($id, 'integer'));
            $this->db->query("DELETE FROM records_zone_templ WHERE domain_id=" . $this->db->quote($id, 'integer'));
            $this->db->query("DELETE FROM $domains_table WHERE id=" . $this->db->quote($id, 'integer'));
            return true;
        } else {
            $error = new ErrorMessage(_("You do not have the permission to delete a zone."));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);
        }
    }

    /** Record ID to Domain ID
     *
     * Gets the id of the domain by a given record id
     *
     * @param int $id Record ID
     * @return int Domain ID of record
     */
    public function recid_to_domid(int $id): int
    {
        $pdns_db_name = $this->config->get('pdns_db_name');
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

        $result = $this->db->query("SELECT domain_id FROM $records_table WHERE id=" . $this->db->quote($id, 'integer'));
        $r = $result->fetch();
        return $r["domain_id"];
    }

    /** Change owner of a domain
     *
     * @param int $zone_id Zone ID
     * @param int $user_id User ID
     *
     * @return boolean true when succesful
     */
    public static function add_owner_to_zone($db, int $zone_id, int $user_id): bool
    {
        if ((UserManager::verify_permission($db, 'zone_meta_edit_others')) || (UserManager::verify_permission($db, 'zone_meta_edit_own')) && UserManager::verify_user_is_owner_zoneid($db, $_GET["id"])) {
            if (UserManager::is_valid_user($db, $user_id)) {
                if ($db->queryOne("SELECT COUNT(id) FROM zones WHERE owner=" . $db->quote($user_id, 'integer') . " AND domain_id=" . $db->quote($zone_id, 'integer')) == 0) {
                    $zone_templ_id = self::get_zone_template($db, $zone_id);
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
                    $error = new ErrorMessage(_('The selected user already owns the zone.'));
                    $errorPresenter = new ErrorPresenter();
                    $errorPresenter->present($error);
                    return false;
                }
            } else {
                $error = new ErrorMessage(sprintf(_('Invalid argument(s) given to function %s %s'), "add_owner_to_zone", "$zone_id / $user_id"));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
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
    public static function delete_owner_from_zone($db, int $zone_id, int $user_id): bool
    {
        if ((UserManager::verify_permission($db, 'zone_meta_edit_others')) || (UserManager::verify_permission($db, 'zone_meta_edit_own')) && UserManager::verify_user_is_owner_zoneid($db, $_GET["id"])) {
            if (UserManager::is_valid_user($db, $user_id)) {
                if ($db->queryOne("SELECT COUNT(id) FROM zones WHERE domain_id=" . $db->quote($zone_id, 'integer')) > 1) {
                    $db->query("DELETE FROM zones WHERE owner=" . $db->quote($user_id, 'integer') . " AND domain_id=" . $db->quote($zone_id, 'integer'));
                } else {
                    $error = new ErrorMessage(_('There must be at least one owner for a zone.'));
                    $errorPresenter = new ErrorPresenter();
                    $errorPresenter->present($error);
                }
                return true;
            } else {
                $error = new ErrorMessage(sprintf(_('Invalid argument(s) given to function %s %s'), "delete_owner_from_zone", "$zone_id / $user_id"));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
        } else {
            return false;
        }
    }

    /** Get Domain Name by domain ID
     *
     * @param int $id Domain ID
     *
     * @return bool|string Domain name
     */
    public function get_domain_name_by_id(int $id): bool|string
    {
        $pdns_db_name = $this->config->get('pdns_db_name');
        $domains_table = $pdns_db_name ? $pdns_db_name . '.domains' : 'domains';

        $result = $this->db->queryRow("SELECT name FROM $domains_table WHERE id=" . $this->db->quote($id, 'integer'));
        if ($result) {
            return $result["name"];
        } else {
            $error = new ErrorMessage("Domain does not exist.");
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            return false;
        }
    }

    public function get_domain_id_by_name(string $name): bool|int
    {
        if (empty($name)) {
            return false;
        }

        $pdns_db_name = $this->config->get('pdns_db_name');
        $domains_table = $pdns_db_name ? $pdns_db_name . '.domains' : 'domains';

        $query = "SELECT id FROM $domains_table WHERE name = :name";
        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':name', $name);
        $stmt->execute();

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['id'] : false;
    }

    /** Get zone id from name
     *
     * @param string $zname Zone name
     * @return bool|int Zone ID
     */
    public function get_zone_id_from_name(string $zname): bool|int
    {
        if (!empty($zname)) {
            $pdns_db_name = $this->config->get('pdns_db_name');
            $domains_table = $pdns_db_name ? $pdns_db_name . '.domains' : 'domains';

            $result = $this->db->queryRow("SELECT id FROM $domains_table WHERE name=" . $this->db->quote($zname, 'text'));
            if ($result) {
                return $result["id"];
            } else {
                $error = new ErrorMessage("Zone does not exist.");
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);

                return false;
            }
        } else {
            $error = new ErrorMessage(sprintf(_('Invalid argument(s) given to function %s %s'), "get_zone_id_from_name", "Not a valid domainname: $zname"));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);
        }
    }

    /** Get Zone details from Zone ID
     *
     * @param int $zid Zone ID
     * @return array array of zone details [type,name,master_ip,record_count]
     */
    public function get_zone_info_from_id(int $zid): array
    {
        $perm_view = Permission::getViewPermission($this->db);

        if ($perm_view == "none") {
            $error = new ErrorMessage(_("You do not have the permission to view this zone."));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);
        } else {
            $pdns_db_name = $this->config->get('pdns_db_name');
            $domains_table = $pdns_db_name ? $pdns_db_name . '.domains' : 'domains';
            $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

            $query = "SELECT $domains_table.type AS type,
					$domains_table.name AS name,
					$domains_table.master AS master_ip,
					count($records_table.domain_id) AS record_count
					FROM $domains_table LEFT OUTER JOIN $records_table ON $domains_table.id = $records_table.domain_id
					WHERE $domains_table.id = " . $this->db->quote($zid, 'integer') . "
					GROUP BY $domains_table.id, $domains_table.type, $domains_table.name, $domains_table.master";
            $result = $this->db->queryRow($query);
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
    public function get_zone_info_from_ids(array $zones): array
    {
        $dnsRecord = new DnsRecord($this->db, $this->config);
        $zone_infos = array();
        foreach ($zones as $zone) {
            $zone_info = $dnsRecord->get_zone_info_from_id($zone);
            $zone_infos[] = $zone_info;
        }
        return $zone_infos;
    }

    public static function convert_ipv4addr_to_ptrrec(string $ip): string
    {
        $ip_octets = explode('.', $ip);
        return implode('.', array_reverse($ip_octets)) . '.in-addr.arpa';
    }

    /** Convert IPv6 Address to PTR
     *
     * @param string $ip IPv6 Address
     * @return string PTR form of address
     */
    public static function convert_ipv6addr_to_ptrrec(string $ip): string
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
    public function get_best_matching_zone_id_from_name(string $domain): int
    {
        // rev-patch
        // string to find the correct zone
        // %ip6.arpa and %in-addr.arpa is looked for

        $match = 72; // the longest ip6.arpa has a length of 72
        $found_domain_id = -1;

        $pdns_db_name = $this->config->get('pdns_db_name');
        $domains_table = $pdns_db_name ? $pdns_db_name . '.domains' : 'domains';

        // get all reverse-zones
        $query = "SELECT name, id FROM $domains_table
                   WHERE name like " . $this->db->quote('%.arpa', 'text') . "
                   ORDER BY length(name) DESC";

        $response = $this->db->query($query);
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
    public function domain_exists(string $domain): bool
    {
        $dns = new Dns($this->db, $this->config);

        $pdns_db_name = $this->config->get('pdns_db_name');
        $domains_table = $pdns_db_name ? $pdns_db_name . '.domains' : 'domains';

        if ($dns->is_valid_hostname_fqdn($domain, 0)) {
            $result = $this->db->queryRow("SELECT id FROM $domains_table WHERE name=" . $this->db->quote($domain, 'text'));
            return (bool)$result;
        } else {
            $error = new ErrorMessage(_('This is an invalid zone name.'));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);
        }
    }

    /** Get All Supermasters
     *
     * Gets an array of arrays of supermaster details
     *
     * @return array[] supermasters detail [master_ip,ns_name,account]s
     */
    public function get_supermasters(): array
    {
        $pdns_db_name = $this->config->get('pdns_db_name');
        $supermasters_table = $pdns_db_name ? $pdns_db_name . ".supermasters" : "supermasters";

        $result = $this->db->query("SELECT ip, nameserver, account FROM $supermasters_table");

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
    public function supermaster_exists(string $master_ip): bool
    {
        if (Dns::is_valid_ipv4($master_ip, false) || Dns::is_valid_ipv6($master_ip)) {
            $pdns_db_name = $this->config->get('pdns_db_name');
            $supermasters_table = $pdns_db_name ? $pdns_db_name . ".supermasters" : "supermasters";

            $result = $this->db->queryOne("SELECT ip FROM $supermasters_table WHERE ip = " . $this->db->quote($master_ip, 'text'));
            return (bool)$result;
        } else {
            $error = new ErrorMessage(sprintf(_('Invalid argument(s) given to function %s %s'), "supermaster_exists", "No or no valid IPv4 or IPv6 address given."));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);
        }
    }

    /** Check if Supermaster IP Address and NS Name combo exists
     *
     * @param string $master_ip Supermaster IP Address
     * @param string $ns_name Supermaster NS Name
     *
     * @return boolean true if exists, false otherwise
     */
    public function supermaster_ip_name_exists(string $master_ip, string $ns_name): bool
    {
        $dns = new Dns($this->db, $this->config);

        if ((Dns::is_valid_ipv4($master_ip) || Dns::is_valid_ipv6($master_ip)) && $dns->is_valid_hostname_fqdn($ns_name, 0)) {
            $pdns_db_name = $this->config->get('pdns_db_name');
            $supermasters_table = $pdns_db_name ? $pdns_db_name . ".supermasters" : "supermasters";

            $result = $this->db->queryOne("SELECT ip FROM $supermasters_table WHERE ip = " . $this->db->quote($master_ip, 'text') .
                " AND nameserver = " . $this->db->quote($ns_name, 'text'));
            return (bool)$result;
        } else {
            $error = new ErrorMessage(sprintf(_('Invalid argument(s) given to function %s %s'), "supermaster_exists", "No or no valid IPv4 or IPv6 address given."));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);
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
     * @return boolean|array false or array of zone details [id,name,type,count_records]
     */
    public function get_zones(string $perm, int $userid = 0, string $letterstart = 'all', int $rowstart = 0, int $rowamount = 999999, string $sortby = 'name', string $sortDirection = 'ASC'): bool|array
    {
        $db_type = $this->config->get('db_type');
        $pdnssec_use = $this->config->get('pdnssec_use');
        $iface_zone_comments = $this->config->get('iface_zone_comments');
        $iface_zonelist_serial = $this->config->get('iface_zonelist_serial');
        $iface_zonelist_template = $this->config->get('iface_zonelist_template');

        $pdns_db_name = $this->config->get('pdns_db_name');
        $domains_table = $pdns_db_name ? $pdns_db_name . '.domains' : 'domains';
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';
        $cryptokeys_table = $pdns_db_name ? $pdns_db_name . '.cryptokeys' : 'cryptokeys';
        $domainmetadata_table = $pdns_db_name ? $pdns_db_name . '.domainmetadata' : 'domainmetadata';

        if ($letterstart == '_') {
            $letterstart = '\_';
        }

        $sql_add = '';
        if ($perm != "own" && $perm != "all") {
            $error = new ErrorMessage(_("You do not have the permission to view this zone."));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            return false;
        } else {
            if ($perm == "own") {
                $sql_add = " AND zones.domain_id = $domains_table.id AND zones.owner = " . $this->db->quote($userid, 'integer');
            }
            if ($letterstart != 'all' && $letterstart != 1) {
                $sql_add .= " AND " . DbCompat::substr($db_type) . "($domains_table.name,1,1) = " . $this->db->quote($letterstart, 'text') . " ";
            } elseif ($letterstart == 1) {
                $sql_add .= " AND " . DbCompat::substr($db_type) . "($domains_table.name,1,1) " . DbCompat::regexp($db_type) . " '[0-9]'";
            }
        }

        if ($sortby == 'owner') {
            $sortby = 'users.username';
        } elseif ($sortby == 'count_records') {
            $sortby = "COUNT($records_table.id)";
        } else {
            $sortby = "$domains_table.$sortby";
        }

        $sql_sortby = $sortby == "$domains_table.name" ? SortHelper::getZoneSortOrder($domains_table, $db_type, $sortDirection) : $sortby . " " . $sortDirection;

        $query = "SELECT $domains_table.id,
                        $domains_table.name,
                        $domains_table.type,
                        COUNT($records_table.id) AS count_records,
                        users.username,
                        users.fullname
                        " . ($pdnssec_use ? ", COUNT($cryptokeys_table.id) > 0 OR COUNT($domainmetadata_table.id) > 0 AS secured" : "") . "
                        " . ($iface_zone_comments ? ", zones.comment" : "") . "
                        FROM $domains_table
                        LEFT JOIN zones ON $domains_table.id=zones.domain_id
                        LEFT JOIN $records_table ON $records_table.domain_id=$domains_table.id AND $records_table.type IS NOT NULL
                        LEFT JOIN users ON users.id=zones.owner";

        if ($pdnssec_use) {
            $query .= " LEFT JOIN $cryptokeys_table ON $domains_table.id = $cryptokeys_table.domain_id AND $cryptokeys_table.active
                        LEFT JOIN $domainmetadata_table ON $domains_table.id = $domainmetadata_table.domain_id AND $domainmetadata_table.kind = 'PRESIGNED'";
        }

        $query .= " WHERE 1=1" . $sql_add . "
                    GROUP BY $domains_table.name, $domains_table.id, $domains_table.type, users.username, users.fullname
                    " . ($iface_zone_comments ? ", zones.comment" : "") . "
                    ORDER BY " . $sql_sortby;

        if ($letterstart != 'all') {
            $this->db->setLimit($rowamount, $rowstart);
        }
        $result = $this->db->query($query);
        $this->db->setLimit(0);

        $ret = array();
        while ($r = $result->fetch()) {
            //FIXME: name is not guaranteed to be unique with round-robin record sets
            $ret[$r["name"]]["id"] = $r["id"];
            $ret[$r["name"]]["name"] = $r["name"];
            $ret[$r["name"]]["utf8_name"] = idn_to_utf8(htmlspecialchars($r["name"]), IDNA_NONTRANSITIONAL_TO_ASCII);
            $ret[$r["name"]]["type"] = $r["type"];
            $ret[$r["name"]]["count_records"] = $r["count_records"];
            $ret[$r["name"]]["comment"] = $r["comment"] ?? '';
            $ret[$r["name"]]["owners"][] = $r["username"];
            $ret[$r["name"]]["full_names"][] = $r["fullname"] ?: '';
            $ret[$r["name"]]["users"][] = $r["username"];

            if ($pdnssec_use) {
                $ret[$r["name"]]["secured"] = $r["secured"];
            }

            if ($iface_zonelist_serial) {
                $ret[$r["name"]]["serial"] = $this->get_serial_by_zid($r["id"]);
            }

            if ($iface_zonelist_template) {
                $ret[$r["name"]]["template"] = ZoneTemplate::get_zone_templ_name($this->db, $r["id"]);
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
     * @return int Count of zones matched
     */
    public static function zone_count_ng($db, $config, string $perm, string $letterstart = 'all'): int
    {
        $pdns_db_name = $config->get('pdns_db_name');
        $domains_table = $pdns_db_name ? $pdns_db_name . '.domains' : 'domains';

        $tables = $domains_table;
        $query_addon = '';

        if ($perm != "own" && $perm != "all") {
            return 0;
        }

        if ($perm == "own") {
            $query_addon = " AND zones.domain_id = $domains_table.id
                AND zones.owner = " . $db->quote($_SESSION['userid'], 'integer');
            $tables .= ', zones';
        }

        if ($letterstart != 'all' && $letterstart != 1) {
            $query_addon .= " AND $domains_table.name LIKE " . $db->quote($letterstart . "%", 'text') . " ";
        } elseif ($letterstart == 1) {
            $query_addon .= " AND " . DbCompat::substr($config->get('db_type')) . "($domains_table.name,1,1) " . DbCompat::regexp($config->get('db_type')) . " '[0-9]'";
        }


        $query = "SELECT COUNT($domains_table.id) AS count_zones FROM $tables WHERE 1=1 $query_addon";

        return $db->queryOne($query);
    }

    /** Get a Record from a Record ID
     *
     * Retrieve all fields of the record and send it back to the function caller.
     *
     * @param int $id Record ID
     * @return int|array array of record detail, or -1 if nothing found
     */
    public function get_record_from_id(int $id): int|array
    {
        $pdns_db_name = $this->config->get('pdns_db_name');
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

        $result = $this->db->queryRow("SELECT * FROM $records_table WHERE id=" . $this->db->quote($id, 'integer') . " AND type IS NOT NULL");
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
                "prio" => $result["prio"],
                "disabled" => $result["disabled"],
                "ordername" => $result["ordername"],
                "auth" => $result["auth"],
            );
        } else {
            return -1;
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
     * @param string $sortDirection Sort direction [default='ASC']
     * @param bool $fetchComments Whether to fetch record comments [default=false]
     *
     * @return int|array array of record detail, or -1 if nothing found
     */
    public function get_records_from_domain_id($db_type, int $id, int $rowstart = 0, int $rowamount = 999999, string $sortby = 'name', string $sortDirection = 'ASC', bool $fetchComments = false): array|int
    {
        if (!is_numeric($id)) {
            $error = new ErrorMessage(sprintf(_('Invalid argument(s) given to function %s'), "get_records_from_domain_id"));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            return -1;
        }

        $pdns_db_name = $this->config->get('pdns_db_name');
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';
        $comments_table = $pdns_db_name ? $pdns_db_name . '.comments' : 'comments';

        $this->db->setLimit($rowamount, $rowstart);

        if ($sortby == 'name') {
            $sortby = "$records_table.name";
        }
        $sql_sortby = $sortby == "$records_table.name" ? SortHelper::getRecordSortOrder($records_table, $db_type, $sortDirection) : $sortby . " " . $sortDirection;
        if ($sortby == "$records_table.name" and $sortDirection == 'ASC') {
            $sql_sortby = "$records_table.type = 'SOA' DESC, $records_table.type = 'NS' DESC, ". $sql_sortby;
        }

        $query = "SELECT $records_table.*, " . ($fetchComments ? "$comments_table.comment" : "NULL AS comment") . "
            FROM $records_table
            " . ($fetchComments ? "LEFT JOIN $comments_table ON $records_table.domain_id = $comments_table.domain_id AND $records_table.name = $comments_table.name AND $records_table.type = $comments_table.type" : "") . "
            WHERE $records_table.domain_id=" . $this->db->quote($id, 'integer') . " AND $records_table.type IS NOT NULL
            " . ($fetchComments ? "GROUP BY $records_table.id, $comments_table.comment" : "") . "
            ORDER BY " . $sql_sortby;

        $records = $this->db->query($query);
        $this->db->setLimit(0);

        if ($records) {
            $result = $records->fetchAll();
        } else {
            return -1;
        }

        return $result;
    }

    /** Get list of owners for Domain ID
     *
     * @param int $id Domain ID
     *
     * @return array array of owners [id,fullname]
     */
    public static function get_users_from_domain_id($db, int $id): array
    {
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
            return [];
        }
        return $owners;
    }

    /** Get Domain Type for Domain ID
     *
     * @param int $id Domain ID
     *
     * @return string Domain Type [NATIVE,MASTER,SLAVE]
     */
    public function get_domain_type(int $id): string
    {
        $pdns_db_name = $this->config->get('pdns_db_name');
        $domains_table = $pdns_db_name ? $pdns_db_name . '.domains' : 'domains';

        $type = $this->db->queryOne("SELECT type FROM $domains_table WHERE id = " . $this->db->quote($id, 'integer'));
        if ($type == "") {
            $type = "NATIVE";
        }
        return $type;
    }

    /** Get Slave Domain's Master
     *
     * @param int $id Domain ID
     *
     * @return array|bool|void Master server
     */
    public function get_domain_slave_master(int $id)
    {
        $pdns_db_name = $this->config->get('pdns_db_name');
        $domains_table = $pdns_db_name ? $pdns_db_name . '.domains' : 'domains';
        return $this->db->queryOne("SELECT master FROM $domains_table WHERE type = 'SLAVE' and id = " . $this->db->quote($id, 'integer'));
    }

    /** Change Zone Type
     *
     * @param $db
     * @param string $type New Zone Type [NATIVE,MASTER,SLAVE]
     * @param int $id Zone ID
     *
     * @return void
     */
    public function change_zone_type(string $type, int $id): void
    {
        $pdns_db_name = $this->config->get('pdns_db_name');
        $domains_table = $pdns_db_name ? $pdns_db_name . '.domains' : 'domains';

        $add = '';
        $params = array(':type' => $type, ':id' => $id);

        // It is not really necessary to clear the field that contains the IP address
        // of the master if the type changes from slave to something else. PowerDNS will
        // ignore the field if the type isn't something else then slave. But then again,
        // it's much clearer this way.
        if ($type != "SLAVE") {
            $add = ", master = :master";
            $params[':master'] = '';
        }
        $query = "UPDATE $domains_table SET type = :type" . $add . " WHERE id = :id";
        $stmt = $this->db->prepare($query);

        $stmt->execute($params);
    }

    /** Change Slave Zone's Master IP Address
     *
     * @param int $zone_id Zone ID
     * @param string $ip_slave_master Master IP Address
     *
     * @return null
     */
    public function change_zone_slave_master(int $zone_id, string $ip_slave_master)
    {
        if (Dns::are_multiple_valid_ips($ip_slave_master)) {
            $pdns_db_name = $this->config->get('pdns_db_name');
            $domains_table = $pdns_db_name ? $pdns_db_name . '.domains' : 'domains';

            $stmt = $this->db->prepare("UPDATE $domains_table SET master = ? WHERE id = ?");
            $stmt->execute(array($ip_slave_master, $zone_id));
        } else {
            $error = new ErrorMessage(sprintf(_('Invalid argument(s) given to function %s %s'), "change_zone_slave_master", "This is not a valid IPv4 or IPv6 address: $ip_slave_master"));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);
        }
    }

    /** Get Serial for Zone ID
     *
     * @param int $zid Zone ID
     *
     * @return string Serial Number or false if not found
     */
    public function get_serial_by_zid(int $zid): string
    {
        $pdns_db_name = $this->config->get('pdns_db_name');
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

        $query = "SELECT content FROM $records_table where TYPE = " . $this->db->quote('SOA', 'text') . " and domain_id = " . $this->db->quote($zid, 'integer');
        $rr_soa = $this->db->queryOne($query);
        $rr_soa_fields = explode(" ", $rr_soa);
        return $rr_soa_fields[2] ?? '';
    }

    /** Validate Account is valid string
     *
     * @param string $account Account name alphanumeric and ._-
     *
     * @return boolean true is valid, false otherwise
     */
    public static function validate_account(string $account): bool
    {
        if (preg_match("/^[A-Z0-9._-]+$/i", $account)) {
            return true;
        } else {
            return false;
        }
    }

    /** Get Zone Template ID for Zone ID
     *
     * @param $db
     * @param int $zone_id Zone ID
     *
     * @return int Zone Template ID
     */
    public static function get_zone_template($db, int $zone_id): int
    {
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
    public function update_zone_records($db_type, $dns_ttl, int $zone_id, int $zone_template_id)
    {
        $perm_edit = Permission::getEditPermission($this->db);
        $user_is_zone_owner = UserManager::verify_user_is_owner_zoneid($this->db, $zone_id);

        if (UserManager::verify_permission($this->db, 'zone_master_add')) {
            $zone_master_add = "1";
        }

        if (UserManager::verify_permission($this->db, 'zone_slave_add')) {
            $zone_slave_add = "1";
        }

        $soa_rec = $this->get_soa_record($zone_id);
        $this->db->beginTransaction();

        $pdns_db_name = $this->config->get('pdns_db_name');
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

        if ($zone_template_id != 0) {
            if ($perm_edit == "all" || ($perm_edit == "own" && $user_is_zone_owner == "1")) {
                if ($db_type == 'pgsql') {
                    $query = "DELETE FROM $records_table r USING records_zone_templ rzt WHERE rzt.domain_id = :zone_id AND rzt.zone_templ_id = :zone_template_id AND r.id = rzt.record_id";
                } else if ($db_type == 'sqlite') {
                    $query = "DELETE FROM $records_table WHERE id IN (SELECT r.id FROM $records_table r LEFT JOIN records_zone_templ rzt ON r.id = rzt.record_id WHERE rzt.domain_id = :zone_id AND rzt.zone_templ_id = :zone_template_id)";
                } else {
                    $query = "DELETE r, rzt FROM $records_table r LEFT JOIN records_zone_templ rzt ON r.id = rzt.record_id WHERE rzt.domain_id = :zone_id AND rzt.zone_templ_id = :zone_template_id";
                }
                $stmt = $this->db->prepare($query);
                $stmt->execute(array(':zone_id' => $zone_id, ':zone_template_id' => $zone_template_id));
            } else {
                $error = new ErrorMessage(_("You do not have the permission to delete a zone."));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
            if ($zone_master_add == "1" || $zone_slave_add == "1") {
                $domain = $this->get_domain_name_by_id($zone_id);
                $templ_records = ZoneTemplate::get_zone_templ_records($this->db, $zone_template_id);

                foreach ($templ_records as $r) {
                    //fixme: appears to be a bug and regex match should occur against $domain
                    if ((preg_match('/in-addr.arpa/i', $zone_id) && ($r["type"] == "NS" || $r["type"] == "SOA")) || (!preg_match('/in-addr.arpa/i', $zone_id))) {
                        $zoneTemplate = new ZoneTemplate($this->db, $this->config);
                        $name = $zoneTemplate->parse_template_value($r["name"], $domain);
                        $type = $r["type"];
                        if ($type == "SOA") {
                            $this->db->exec("DELETE FROM $records_table WHERE domain_id = " . $this->db->quote($zone_id, 'integer') . " AND type = 'SOA'");
                            $content = $this->get_updated_soa_record($soa_rec);
                            if ($content == "") {
                                $content = $zoneTemplate->parse_template_value($r["content"], $domain);
                            }
                        } else {
                            $content = $zoneTemplate->parse_template_value($r["content"], $domain);
                        }

                        $ttl = $r["ttl"];
                        $prio = intval($r["prio"]);

                        if (!$ttl) {
                            $ttl = $dns_ttl;
                        }

                        $query = "INSERT INTO $records_table (domain_id, name, type, content, ttl, prio) VALUES ("
                            . $this->db->quote($zone_id, 'integer') . ","
                            . $this->db->quote($name, 'text') . ","
                            . $this->db->quote($type, 'text') . ","
                            . $this->db->quote($content, 'text') . ","
                            . $this->db->quote($ttl, 'integer') . ","
                            . $this->db->quote($prio, 'integer') . ")";
                        $this->db->exec($query);

                        if ($db_type == 'pgsql') {
                            $record_id = $this->db->lastInsertId('records_id_seq');
                        } else {
                            $record_id = $this->db->lastInsertId();
                        }

                        $query = "INSERT INTO records_zone_templ (domain_id, record_id, zone_templ_id) VALUES ("
                            . $this->db->quote($zone_id, 'integer') . ","
                            . $this->db->quote($record_id, 'integer') . ","
                            . $this->db->quote($zone_template_id, 'integer') . ")";
                        $this->db->query($query);
                    }
                }
            }
        }

        $query = "UPDATE zones
                    SET zone_templ_id = " . $this->db->quote($zone_template_id, 'integer') . "
                    WHERE domain_id = " . $this->db->quote($zone_id, 'integer');
        $this->db->exec($query);
        $this->db->commit();
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
    public function delete_domains(array $domains): bool
    {
        $pdnssec_use = $this->config->get('pdnssec_use');
        $pdns_db_name = $this->config->get('pdns_db_name');
        $domains_table = $pdns_db_name ? "$pdns_db_name.domains" : "domains";
        $records_table = $pdns_db_name ? "$pdns_db_name.records" : "records";

        $this->db->beginTransaction();

        foreach ($domains as $id) {
            $perm_edit = Permission::getEditPermission($this->db);
            $user_is_zone_owner = UserManager::verify_user_is_owner_zoneid($this->db, $id);

            if ($perm_edit == "all" || ($perm_edit == "own" && $user_is_zone_owner == "1")) {
                if (is_numeric($id)) {
                    $zone_type = $this->get_domain_type($id);
                    if ($pdnssec_use && $zone_type == 'MASTER') {
                        $pdns_api_url = $this->config->get('pdns_api_url');
                        $pdns_api_key = $this->config->get('pdns_api_key');

                        $dnssecProvider = DnssecProviderFactory::create(
                            $this->db,
                            new FakeConfiguration($pdns_api_url, $pdns_api_key)
                        );

                        $dnsRecord = new DnsRecord($this->db, $this->config);
                        $zone_name = $dnsRecord->get_domain_name_by_id($id);
                        if ($dnssecProvider->isZoneSecured($zone_name, $this->config)) {
                            $dnssecProvider->unsecureZone($zone_name);
                        }
                    }

                    $this->db->exec("DELETE FROM zones WHERE domain_id=" . $this->db->quote($id, 'integer'));
                    $this->db->exec("DELETE FROM $records_table WHERE domain_id=" . $this->db->quote($id, 'integer'));
                    $this->db->query("DELETE FROM records_zone_templ WHERE domain_id=" . $this->db->quote($id, 'integer'));
                    $this->db->exec("DELETE FROM $domains_table WHERE id=" . $this->db->quote($id, 'integer'));
                } else {
                    $error = new ErrorMessage(sprintf(_('Invalid argument(s) given to function %s %s'), "delete_domains", "id must be a number"));
                    $errorPresenter = new ErrorPresenter();
                    $errorPresenter->present($error);
                }
            } else {
                $error = new ErrorMessage(_("You do not have the permission to delete a zone."));
                $errorPresenter = new ErrorPresenter();
                $errorPresenter->present($error);
            }
        }

        $this->db->commit();

        return true;
    }

    /** Check if record exists
     *
     * @param string $name Record name
     *
     * @return boolean true on success, false on failure
     */
    public function record_name_exists(string $name): bool
    {
        $pdns_db_name = $this->config->get('pdns_db_name');
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

        $query = "SELECT COUNT(id) FROM $records_table WHERE name = " . $this->db->quote($name, 'text');
        $count = $this->db->queryOne($query);
        return $count > 0;
    }

    /** Return domain level for given name
     *
     * @param string $name Zone name
     *
     * @return int domain level
     */
    public static function get_domain_level(string $name): int
    {
        return substr_count($name, '.') + 1;
    }

    /** Return domain second level domain for given name
     *
     * @param string $name Zone name
     *
     * @return string 2nd level domain name
     */
    public static function get_second_level_domain(string $name): string
    {
        $domain_parts = explode('.', $name);
        $domain_parts = array_reverse($domain_parts);
        return $domain_parts[1] . '.' . $domain_parts[0];
    }

    /** Set timezone
     *
     * Set timezone to configured tz or UTC it not set
     *
     * @return void
     */
    public function set_timezone(): void
    {
        $timezone = $this->config->get('timezone');

        if (isset($timezone)) {
            date_default_timezone_set($timezone);
        } else if (!ini_get('date.timezone')) {
            date_default_timezone_set('UTC');
        }
    }

    public function has_similar_records($domain_id, $name, $type, $record_id): bool {
        $query = "SELECT COUNT(*) FROM records
              WHERE domain_id = :domain_id AND name = :name AND type = :type AND id != :record_id";
        $stmt = $this->db->prepare($query);
        $stmt->execute([
            ':domain_id' => $domain_id,
            ':name' => $name,
            ':type' => $type,
            ':record_id' => $record_id
        ]);
        return (bool)$stmt->fetchColumn();
    }
}