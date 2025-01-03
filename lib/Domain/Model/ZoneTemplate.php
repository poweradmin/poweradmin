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

namespace Poweradmin\Domain\Model;

use Poweradmin\Application\Presenter\ErrorPresenter;
use Poweradmin\Domain\Error\ErrorMessage;
use Poweradmin\Domain\Service\Dns;
use Poweradmin\Infrastructure\Database\PDOLayer;
use Poweradmin\AppConfiguration;

/**
 *  Template functions
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class ZoneTemplate
{
    private AppConfiguration $config;
    private PDOLayer $db;

    public function __construct(PDOLayer $db, AppConfiguration $config)
    {
        $this->db = $db;
        $this->config = $config;
    }

    /**
     * @param string $domain
     * @param array $record
     * @param array $options
     * @return array
     */
    public static function replaceWithTemplatePlaceholders(string $domain, array $record, array $options = []): array
    {
        if (empty($domain)) {
            return [$record['name'], $record['content']];
        }

        $pattern = '/(\.)?' . preg_quote($domain, '/') . '$/';
        $name = preg_replace($pattern, '$1[ZONE]', $record['name']);
        $content = preg_replace($pattern, '$1[ZONE]', $record['content']);

        if (isset($record['type']) && $record['type'] === 'SOA') {
            $parts = explode(' ', $content);

            if (isset($options['NS1']) && $parts[0] === $options['NS1']) {
                $parts[0] = '[NS1]';
            }

            if (isset($options['HOSTMASTER']) && $parts[1] === $options['HOSTMASTER']) {
                $parts[1] = '[HOSTMASTER]';
            }

            if (preg_match('/\d{10}/', $parts[2])) {
                $parts[2] = '[SERIAL]';
            }

            $content = implode(' ', $parts);
        }

        return [$name, $content];
    }

    /** Get a list of all available zone templates
     *
     * @param int $userid User ID
     *
     * @return array array of zone templates [id,name,descr]
     */
    public function get_list_zone_templ(int $userid): array
    {
        $query = "SELECT zt.id, zt.name, zt.descr, u.username, u.fullname, COUNT(z.zone_templ_id) as zones_linked
            FROM zone_templ zt
            LEFT JOIN users u ON zt.owner = u.id
            LEFT JOIN zones z ON zt.id = z.zone_templ_id";
        $params = [];

        if (!UserManager::verify_permission($this->db, 'user_is_ueberuser')) {
            $query .= " WHERE zt.owner = :userid OR zt.owner = 0";
            $params[':userid'] = $userid;
        }

        $query .= " GROUP BY zt.id, zt.name, zt.descr, u.username, u.fullname ORDER BY zt.name";

        $stmt = $this->db->prepare($query);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** Add a zone template
     *
     * @param $db
     * @param array $details zone template details
     * @param int $userid User ID that owns template
     *
     * @return boolean true on success, false otherwise
     */
    public static function add_zone_templ($db, array $details, int $userid): bool
    {
        $zone_name_exists = ZoneTemplate::zone_templ_name_exists($db, $details['templ_name']);
        if (!(UserManager::verify_permission($db, 'zone_master_add'))) {
            $error = new ErrorMessage(_("You do not have the permission to add a zone template."));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            return false;
        } elseif ($zone_name_exists != '0') {
            $error = new ErrorMessage(_('Zone template with this name already exists, please choose another one.'));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);
        } else {
            $stmt = $db->prepare("INSERT INTO zone_templ (name, descr, owner) VALUES (:name, :descr, :owner)");
            $stmt->execute([
                ':name' => $details['templ_name'],
                ':descr' => $details['templ_descr'],
                ':owner' => isset($details['templ_global']) ? 0 : $userid
            ]);
            return true;
        }
        return false;
    }

    public static function get_zone_templ_name($db, $zone_id)
    {
        $stmt = $db->prepare("SELECT zt.name FROM zones z JOIN zone_templ zt ON zt.id = z.zone_templ_id WHERE z.domain_id = :zone_id");
        $stmt->execute([':zone_id' => $zone_id]);
        $result = $stmt->fetch();

        return $result ? $result['name'] : '';
    }

    /** Get name and description of template based on template ID
     *
     * @param int $zone_templ_id Zone template ID
     *
     * @return array zone template details
     */
    public static function get_zone_templ_details($db, int $zone_templ_id): array
    {
        $query = "SELECT *"
            . " FROM zone_templ"
            . " WHERE id = " . $db->quote($zone_templ_id, 'integer');

        $result = $db->query($query);
        return $result->fetch() ?: [];
    }

    /** Delete a zone template
     *
     * @param int $zone_templ_id Zone template ID
     *
     * @return boolean true on success, false otherwise
     */
    public static function delete_zone_templ($db, int $zone_templ_id): bool
    {
        if (!(UserManager::verify_permission($db, 'zone_master_add'))) {
            $error = new ErrorMessage(_("You do not have the permission to delete zone templates."));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            return false;
        } else {
            // Delete the zone template
            $query = "DELETE FROM zone_templ"
                . " WHERE id = " . $db->quote($zone_templ_id, 'integer');
            $db->query($query);

            // Delete the zone template records
            $query = "DELETE FROM zone_templ_records"
                . " WHERE zone_templ_id = " . $db->quote($zone_templ_id, 'integer');
            $db->query($query);

            // Delete references to zone template
            $query = "DELETE FROM records_zone_templ"
                . " WHERE zone_templ_id = " . $db->quote($zone_templ_id, 'integer');
            $db->query($query);
            return true;
        }
    }

    /** Delete all zone templates for specific user
     *
     * @param $db
     * @param int $userid User ID
     *
     * @return boolean true on success, false otherwise
     */
    public static function delete_zone_templ_userid($db, int $userid): bool
    {
        if (!(UserManager::verify_permission($db, 'zone_master_add'))) {
            $error = new ErrorMessage(_("You do not have the permission to delete zone templates."));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            return false;
        } else {
            $query = "DELETE FROM zone_templ"
                . " WHERE owner = " . $db->quote($userid, 'integer');
            $db->query($query);
            return true;
        }
    }

    /** Count zone template records
     *
     * @param $db
     * @param int $zone_templ_id Zone template ID
     *
     * @return int number of records
     */
    public static function count_zone_templ_records($db, int $zone_templ_id): int
    {
        $query = "SELECT COUNT(id) FROM zone_templ_records WHERE zone_templ_id = " . $db->quote($zone_templ_id, 'integer');
        return $db->queryOne($query);
    }

    /** Check if zone template exist
     *
     * @param int $zone_templ_id Zone template ID
     *
     * @return boolean true on success, false otherwise
     */
    public static function zone_templ_id_exists($db, int $zone_templ_id): bool
    {
        $query = "SELECT COUNT(id) FROM zone_templ WHERE id = " . $db->quote($zone_templ_id, 'integer');
        return $db->queryOne($query);
    }

    /** Get a zone template record from an id
     *
     * Retrieve all fields of the record and send it back to the function caller.
     *
     * @param int $id zone template record id
     *
     * @return array zone template record
     * [id,zone_templ_id,name,type,content,ttl,prio] or -1 if nothing is found
     */
    public static function get_zone_templ_record_from_id($db, int $id): array
    {
        $result = $db->queryRow("SELECT id, zone_templ_id, name, type, content, ttl, prio FROM zone_templ_records WHERE id=" . $db->quote($id, 'integer'));
        return $result ? array(
            "id" => $result["id"],
            "zone_templ_id" => $result["zone_templ_id"],
            "name" => $result["name"],
            "type" => $result["type"],
            "content" => $result["content"],
            "ttl" => $result["ttl"],
            "prio" => $result["prio"],
        ) : [];
    }

    /** Get all zone template records from a zone template id
     *
     * Retrieve all fields of the records and send it back to the function caller.
     *
     * @param int $id zone template ID
     * @param int $rowstart Starting row (default=0)
     * @param int $rowamount Number of rows per query (default=999999)
     * @param string $sortby Column to sort by (default='name')
     *
     * @return array zone template records numerically indexed
     * [id,zone_templd_id,name,type,content,ttl,pro] or empty array if nothing is found
     */
    public static function get_zone_templ_records($db, int $id, int $rowstart = 0, int $rowamount = 999999, string $sortby = 'name'): array
    {
        $db->setLimit($rowamount, $rowstart);

        $allowedSortColumns = ['name', 'type', 'content', 'priority', 'ttl'];
        $sortby = in_array($sortby, $allowedSortColumns) ? htmlspecialchars($sortby) : 'name';

        $stmt = $db->prepare("SELECT id FROM zone_templ_records WHERE zone_templ_id = :id ORDER BY " . $sortby);
        $stmt->execute([':id' => $id]);
        $db->setLimit(0);

        $ret[] = array();
        $retCount = 0;
        while ($r = $stmt->fetch()) {
            // Call get_record_from_id for each row.
            $ret[$retCount] = ZoneTemplate::get_zone_templ_record_from_id($db, $r["id"]);
            $retCount++;
        }
        return ($retCount > 0 ? $ret : []);
    }

    /** Add a record for a zone template
     *
     * This function validates and if correct it inserts it into the database.
     * TODO: actual validation?
     *
     * @param int $zone_templ_id zone template ID
     * @param string $name name part of record
     * @param string $type record type
     * @param string $content record content
     * @param int $ttl TTL
     * @param int $prio Priority
     *
     * @return boolean true if successful, false otherwise
     */
    public static function add_zone_templ_record($db, int $zone_templ_id, string $name, string $type, string $content, int $ttl, int $prio): bool
    {
        if (!(UserManager::verify_permission($db, 'zone_master_add'))) {
            $error = new ErrorMessage(_("You do not have the permission to add a record to this zone."));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            return false;
        }

        if ($content == '') {
            $error = new ErrorMessage(_('Your content field doesnt have a legit value.'));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            return false;
        }

        if ($name == '') {
            $error = new ErrorMessage(_('Invalid hostname.'));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            return false;
        }

        if (!Dns::is_valid_rr_prio($prio, $type)) {
            $error = new ErrorMessage(_('Invalid value for prio field.'));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            return false;
        }

        $query = "INSERT INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio) VALUES (:zone_templ_id, :name, :type, :content, :ttl, :prio)";
        $stmt = $db->prepare($query);
        $stmt->execute([
            ':zone_templ_id' => $zone_templ_id,
            ':name' => $name,
            ':type' => $type,
            ':content' => $content,
            ':ttl' => $ttl,
            ':prio' => $prio
        ]);

        return true;
    }

    /** Modify zone template reocrd
     *
     * Edit a record for a zone template.
     * This function validates it if correct it inserts it into the database.
     *
     * @param array $record zone record array
     *
     * @return boolean true on success, false otherwise
     */
    public static function edit_zone_templ_record($db, array $record): bool
    {
        if (!(UserManager::verify_permission($db, 'zone_master_add'))) {
            $error = new ErrorMessage(_("You do not have the permission to edit this record."));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            return false;
        }

        if ($record['name'] == "") {
            $error = new ErrorMessage(_('Invalid hostname.'));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            return false;
        }

        if (!Dns::is_valid_rr_prio($record['prio'], $record['type'])) {
            $error = new ErrorMessage(_('Invalid value for prio field.'));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            return false;
        }

        $query = "UPDATE zone_templ_records
                                SET name=" . $db->quote($record['name'], 'text') . ",
                                type=" . $db->quote($record['type'], 'text') . ",
                                content=" . $db->quote($record['content'], 'text') . ",
                                ttl=" . $db->quote($record['ttl'], 'integer') . ",
                                prio=" . $db->quote($record['prio'] ?? 0, 'integer') . "
                                WHERE id=" . $db->quote($record['rid'], 'integer');
        $db->query($query);
        return true;
    }

    /** Delete a record for a zone template by a given id
     *
     * @param int $rid template record id
     *
     * @return boolean true on success, false otherwise
     */
    public static function delete_zone_templ_record($db, int $rid): bool
    {
        if (!(UserManager::verify_permission($db, 'zone_master_add'))) {
            $error = new ErrorMessage(_("You do not have the permission to delete this record."));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            return false;
        } else {
            $query = "DELETE FROM zone_templ_records WHERE id = " . $db->quote($rid, 'integer');
            $db->query($query);
            return true;
        }
    }

    /** Check if the session user is the owner for the zone template
     *
     * @param int $zone_templ_id zone template id
     * @param int $userid user id
     *
     * @return boolean true on success, false otherwise
     */
    public static function get_zone_templ_is_owner($db, int $zone_templ_id, int $userid): bool
    {
        $query = "SELECT owner FROM zone_templ WHERE id = " . $db->quote($zone_templ_id, 'integer');
        $result = $db->queryOne($query);

        if ($result == $userid) {
            return true;
        } else {
            return false;
        }
    }

    /** Add a zone template from zone / another template
     *
     * @param $db
     * @param string $template_name template name
     * @param string $description description
     * @param int $userid user id
     * @param array $records array of zone records
     * @param array $options
     * @param string $domain domain to substitute with '[ZONE]' (optional) [default=null]
     *
     * @return boolean true on success, false otherwise
     */
    public static function add_zone_templ_save_as($db, string $template_name, string $description, int $userid, array $records, array $options, string $domain = ''): bool
    {
        if (!(UserManager::verify_permission($db, 'zone_master_add'))) {
            $error = new ErrorMessage(_("You do not have the permission to add a zone template."));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            return false;
        } else {
            $db->beginTransaction();

            $query = "INSERT INTO zone_templ (name, descr, owner)
			VALUES ("
                . $db->quote($template_name, 'text') . ", "
                . $db->quote($description, 'text') . ", "
                . $db->quote($userid, 'integer') . ")";

            $db->exec($query);

            $zone_templ_id = $db->lastInsertId();

            foreach ($records as $record) {
                list($name, $content) = self::replaceWithTemplatePlaceholders($domain, $record, $options);

                $query2 = "INSERT INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio) VALUES ("
                    . $db->quote($zone_templ_id, 'integer') . ","
                    . $db->quote($name, 'text') . ","
                    . $db->quote($record['type'], 'text') . ","
                    . $db->quote($content, 'text') . ","
                    . $db->quote($record['ttl'], 'integer') . ","
                    . $db->quote($record['prio'] ?? 0, 'integer') . ")";
                $db->exec($query2);
            }

            $db->commit();
        }
        return true;
    }

    /** Get list of all zones using template
     *
     * @param int $zone_templ_id zone template id
     * @param int $userid user id
     *
     * @return array array of zones ids
     */
    public function get_list_zone_use_templ(int $zone_templ_id, int $userid): array
    {
        $perm_edit = Permission::getEditPermission($this->db);

        $sql_add = '';

        $pdns_db_name = $this->config->get('pdns_db_name');
        $domains_table = $pdns_db_name ? $pdns_db_name . '.domains' : 'domains';
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

        if ($perm_edit != "all") {
            $sql_add = " AND zones.domain_id = $domains_table.id
				AND zones.owner = " . $this->db->quote($userid, 'integer');
        }

        $query = "SELECT $domains_table.id,
			$domains_table.name,
			$domains_table.type,
			Record_Count.count_records
			FROM $domains_table
			LEFT JOIN zones ON $domains_table.id=zones.domain_id
			LEFT JOIN (
				SELECT COUNT(domain_id) AS count_records, domain_id FROM $records_table GROUP BY domain_id
			) Record_Count ON Record_Count.domain_id=$domains_table.id
			WHERE 1=1" . $sql_add . "
                        AND zone_templ_id = " . $this->db->quote($zone_templ_id, 'integer') . "
			GROUP BY $domains_table.name, $domains_table.id, $domains_table.type, Record_Count.count_records";

        $result = $this->db->query($query);

        $zone_list = array();
        while ($zone = $result->fetch()) {
            $zone_list[] = $zone['id'];
        }
        return $zone_list;
    }

    /** Modify zone template
     *
     * @param array $details array of new zone template details
     * @param int $zone_templ_id zone template id
     *
     * @return boolean true on success, false otherwise
     */
    public static function edit_zone_templ($db, array $details, int $zone_templ_id, $user_id): bool
    {
        $zone_name_exists = ZoneTemplate::zone_templ_name_and_id_exists($db, $details['templ_name'], $zone_templ_id);
        if (!(UserManager::verify_permission($db, 'zone_master_add'))) {
            $error = new ErrorMessage(_("You do not have the permission to add a zone template."));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            return false;
        } elseif ($zone_name_exists != '0') {
            $error = new ErrorMessage(_('Zone template with this name already exists, please choose another one.'));
            $errorPresenter = new ErrorPresenter();
            $errorPresenter->present($error);

            return false;
        } else {
            $query = 'UPDATE zone_templ SET name=:templ_name, descr=:templ_descr';
            $params = [
                "templ_name" => $details['templ_name'],
                "templ_descr" => $details['templ_descr'],
                "templ_id" => $zone_templ_id
            ];

            if (isset($details['templ_global'])) {
                $query .= ', owner=0';
            } else {
                $query .= ', owner=:templ_owner';
                $params['templ_owner'] = $user_id;
            }
            $query .= ' WHERE id=:templ_id';
            $stmt = $db->prepare($query);

            $stmt->execute($params);

            return true;
        }
    }

    /** Check if zone template name exists
     *
     * @param $db
     * @param string $zone_templ_name zone template name
     *
     * @return bool number of matching templates
     */
    public static function zone_templ_name_exists($db, string $zone_templ_name): bool
    {
        $query = "SELECT COUNT(id) FROM zone_templ WHERE name = " . $db->quote($zone_templ_name, 'text');
        return $db->queryOne($query);
    }

    /** Check if zone template name and id exists
     *
     * @param $db
     * @param string $zone_templ_name zone template name
     * @param int $zone_templ_id zone template id
     *
     * @return bool number of matching templates
     */
    public static function zone_templ_name_and_id_exists($db, string $zone_templ_name, int $zone_templ_id): bool
    {
        $query = "SELECT COUNT(id) FROM zone_templ WHERE name = {$db->quote($zone_templ_name, 'text')} AND id != {$db->quote($zone_templ_id, 'integer')}";
        return $db->queryOne($query);
    }

    /** Parse string and substitute domain and serial
     *
     * @param string $val string to parse containing tokens '[ZONE]' and '[SERIAL]'
     * @param string $domain domain to substitute for '[ZONE]'
     *
     * @return string interpolated/parsed string
     */
    public function parse_template_value(string $val, string $domain): string
    {
        $dns_ns1 = $this->config->get('dns_ns1');
        $dns_ns2 = $this->config->get('dns_ns2');
        $dns_ns3 = $this->config->get('dns_ns3');
        $dns_ns4 = $this->config->get('dns_ns4');
        $dns_hostmaster = $this->config->get('dns_hostmaster');

        $serial = date("Ymd");
        $serial .= "00";

        $val = str_replace('[ZONE]', $domain, $val);
        $val = str_replace('[SERIAL]', $serial, $val);
        $val = str_replace('[NS1]', $dns_ns1, $val);
        $val = str_replace('[NS2]', $dns_ns2, $val);
        $val = str_replace('[NS3]', $dns_ns3, $val);
        $val = str_replace('[NS4]', $dns_ns4, $val);
        return str_replace('[HOSTMASTER]', $dns_hostmaster, $val);
    }
}
