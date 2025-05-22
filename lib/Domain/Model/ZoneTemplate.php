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

use Poweradmin\Domain\Service\DnsFormatter;
use Poweradmin\Domain\Service\DnsValidation\DnsCommonValidator;
use Poweradmin\Domain\Service\DomainParsingService;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;
use Poweradmin\Infrastructure\Database\PDOCommon;
use Poweradmin\Infrastructure\Service\MessageService;

/**
 * Template functions
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class ZoneTemplate
{
    private const DEFAULT_MAX_ROWS = 9999;
    private ConfigurationInterface $config;
    private PDOCommon $db;
    private DnsFormatter $dnsFormatter;
    private MessageService $messageService;
    private DnsCommonValidator $dnsCommonValidator;
    private DomainParsingService $domainParsingService;

    public function __construct(PDOCommon $db, ConfigurationInterface $config)
    {
        $this->db = $db;
        $this->config = $config;
        $this->dnsFormatter = new DnsFormatter($config);
        $this->messageService = new MessageService();
        $this->dnsCommonValidator = new DnsCommonValidator($db, $config);
        $this->domainParsingService = new DomainParsingService();
    }

    /**
     * Replace domain and specific placeholders in DNS records with template placeholders
     *
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

        // Parse domain into its components
        $domainService = new DomainParsingService();
        $domainComponents = $domainService->parseDomain($domain);
        $domainName = $domainComponents['domain']; // Example: 'example' from 'example.com'
        $tld = $domainComponents['tld'];           // Example: 'com' from 'example.com'

        // Replace domain in name field
        $pattern = '/(\.)?' . preg_quote($domain, '/') . '$/';
        $name = preg_replace($pattern, '$1[ZONE]', $record['name']);

        // Replace domain in content field - first handle direct matches
        $content = preg_replace($pattern, '$1[ZONE]', $record['content']);

        // Now handle cases like example-com.mail.protection.outlook.com
        // Look for domain parts that might be used in content like example-com or example-net
        if (!empty($domainName) && !empty($tld)) {
            $domainHyphenatedPattern = $domainName . '-' . $tld;

            // Replace example-com with [DOMAIN]-[TLD]
            if (strpos($content, $domainHyphenatedPattern) !== false) {
                $content = str_replace($domainHyphenatedPattern, '[DOMAIN]-[TLD]', $content);
            }

            // We'll only use [DOMAIN] and [TLD] placeholders for specific patterns
            // where we can't use [ZONE] directly, like domain-tld formats
            // We won't replace standalone domain and TLD components by default
        }

        // Special handling for SOA records
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

    /**
     * Get a list of all available zone templates
     *
     * @param int $userid User ID
     *
     * @return array array of zone templates [id,name,descr]
     */
    public function getListZoneTempl(int $userid): array
    {
        $query = "SELECT zt.id, zt.name, zt.descr, zt.owner, zt.created_by,
                      owner_user.username as owner_username, 
                      owner_user.fullname as owner_fullname,
                      creator_user.username as creator_username, 
                      creator_user.fullname as creator_fullname,
                      COUNT(z.zone_templ_id) as zones_linked
                FROM zone_templ zt
                LEFT JOIN users owner_user ON zt.owner = owner_user.id
                LEFT JOIN users creator_user ON zt.created_by = creator_user.id
                LEFT JOIN zones z ON zt.id = z.zone_templ_id";
        $params = [];

        if (!UserManager::verifyPermission($this->db, 'user_is_ueberuser')) {
            $query .= " WHERE zt.owner = :userid OR zt.owner = 0";
            $params[':userid'] = $userid;
        }

        $query .= " GROUP BY zt.id, zt.name, zt.descr, zt.owner, zt.created_by, 
                           owner_user.username, owner_user.fullname, 
                           creator_user.username, creator_user.fullname 
                  ORDER BY zt.name";

        $stmt = $this->db->prepare($query);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Add a zone template
     *
     * @param array $details zone template details
     * @param int $userid User ID that owns template
     *
     * @return boolean true on success, false otherwise
     */
    public function addZoneTempl(array $details, int $userid): bool
    {
        $zone_name_exists = $this->zoneTemplNameExists($details['templ_name']);

        if (!(UserManager::verifyPermission($this->db, 'zone_templ_add'))) {
            $this->messageService->addSystemError(_("You do not have the permission to add a zone template."));
            return false;
        } elseif ($zone_name_exists != '0') {
            $this->messageService->addSystemError(_('Zone template with this name already exists, please choose another one.'));
        } else {
            $this->db->beginTransaction();

            try {
                // Insert the zone template
                $stmt = $this->db->prepare("INSERT INTO zone_templ (name, descr, owner, created_by) VALUES (:name, :descr, :owner, :created_by)");
                $stmt->execute([
                    ':name' => $details['templ_name'],
                    ':descr' => $details['templ_descr'],
                    ':owner' => isset($details['templ_global']) ? 0 : $userid,
                    ':created_by' => $userid // Always set created_by to current user
                ]);

                // Get the new template ID
                $zone_templ_id = $this->db->lastInsertId();

                // Add a default SOA record to the template
                $this->addDefaultSOARecordToTemplate((int)$zone_templ_id);

                $this->db->commit();
                return true;
            } catch (\Exception $e) {
                $this->db->rollBack();
                $this->messageService->addSystemError(_('Error creating zone template: ') . $e->getMessage());
                return false;
            }
        }
        return false;
    }

    /**
     * Add a default SOA record to a zone template
     *
     * @param int $zone_templ_id Zone template ID
     * @return bool True on success, false otherwise
     */
    private function addDefaultSOARecordToTemplate(int $zone_templ_id): bool
    {
        try {
            // Default values for SOA record
            $name = '[ZONE]';
            $type = 'SOA';
            $content = '[NS1] [HOSTMASTER] [SERIAL] 28800 7200 604800 86400';
            $ttl = (int)$this->config->get('dns', 'ttl');
            $prio = 0;

            // Insert the SOA record
            $stmt = $this->db->prepare("INSERT INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio) VALUES (:zone_templ_id, :name, :type, :content, :ttl, :prio)");
            $stmt->execute([
                ':zone_templ_id' => $zone_templ_id,
                ':name' => $name,
                ':type' => $type,
                ':content' => $content,
                ':ttl' => $ttl,
                ':prio' => $prio
            ]);

            return true;
        } catch (\Exception $e) {
            $this->messageService->addSystemError(_('Error adding default SOA record to template: ') . $e->getMessage());
            return false;
        }
    }

    public static function getZoneTemplName($db, $zone_id)
    {
        $stmt = $db->prepare("SELECT zt.name FROM zones z JOIN zone_templ zt ON zt.id = z.zone_templ_id WHERE z.domain_id = :zone_id");
        $stmt->execute([':zone_id' => $zone_id]);
        $result = $stmt->fetch();

        return $result ? $result['name'] : '';
    }

    /**
     * Get name and description of template based on template ID
     *
     * @param int $zone_templ_id Zone template ID
     *
     * @return array zone template details
     */
    public static function getZoneTemplDetails($db, int $zone_templ_id): array
    {
        $stmt = $db->prepare("SELECT * FROM zone_templ WHERE id = :id");
        $stmt->execute([':id' => $zone_templ_id]);
        return $stmt->fetch() ?: [];
    }

    /** Delete a zone template
     *
     * @param int $zone_templ_id Zone template ID
     *
     * @return boolean true on success, false otherwise
     */
    public function deleteZoneTempl(int $zone_templ_id): bool
    {
        if (!(UserManager::verifyPermission($this->db, 'zone_templ_edit'))) {
            $this->messageService->addSystemError(_("You do not have the permission to delete zone templates."));
            return false;
        } else {
            try {
                $this->db->beginTransaction();

                // Delete the zone template
                $stmt = $this->db->prepare("DELETE FROM zone_templ WHERE id = :zone_templ_id");
                $stmt->execute([':zone_templ_id' => $zone_templ_id]);

                // Delete the zone template records
                $stmt = $this->db->prepare("DELETE FROM zone_templ_records WHERE zone_templ_id = :zone_templ_id");
                $stmt->execute([':zone_templ_id' => $zone_templ_id]);

                // Delete references to zone template
                $stmt = $this->db->prepare("DELETE FROM records_zone_templ WHERE zone_templ_id = :zone_templ_id");
                $stmt->execute([':zone_templ_id' => $zone_templ_id]);

                $this->db->commit();
                return true;
            } catch (\Exception $e) {
                $this->db->rollBack();
                $this->messageService->addSystemError(_('Error deleting zone template: ') . $e->getMessage());
                return false;
            }
        }
    }

    /**
     * Delete all zone templates for specific user
     *
     * @param int $userid User ID
     *
     * @return boolean true on success, false otherwise
     */
    public function deleteZoneTemplUserId(int $userid): bool
    {
        if (!(UserManager::verifyPermission($this->db, 'zone_templ_edit'))) {
            $this->messageService->addSystemError(_("You do not have the permission to delete zone templates."));
            return false;
        } else {
            try {
                $stmt = $this->db->prepare("DELETE FROM zone_templ WHERE owner = :owner");
                $stmt->execute([':owner' => $userid]);
                return true;
            } catch (\Exception $e) {
                $this->messageService->addSystemError(_('Error deleting user zone templates: ') . $e->getMessage());
                return false;
            }
        }
    }

    /**
     * Count zone template records
     *
     * @param $db
     * @param int $zone_templ_id Zone template ID
     *
     * @return int number of records
     */
    public static function countZoneTemplRecords($db, int $zone_templ_id): int
    {
        $stmt = $db->prepare("SELECT COUNT(id) FROM zone_templ_records WHERE zone_templ_id = :zone_templ_id");
        $stmt->execute([':zone_templ_id' => $zone_templ_id]);
        return $stmt->fetchColumn();
    }

    /**
     * Check if zone template exist
     *
     * @param int $zone_templ_id Zone template ID
     *
     * @return boolean true on success, false otherwise
     */
    public static function zoneTemplIdExists($db, int $zone_templ_id): bool
    {
        $stmt = $db->prepare("SELECT COUNT(id) FROM zone_templ WHERE id = :id");
        $stmt->execute([':id' => $zone_templ_id]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Get a zone template record from an id
     *
     * Retrieve all fields of the record and send it back to the function caller.
     *
     * @param int $id zone template record id
     *
     * @return array zone template record
     * [id,zone_templ_id,name,type,content,ttl,prio] or -1 if nothing is found
     */
    public static function getZoneTemplRecordFromId($db, int $id): array
    {
        $stmt = $db->prepare("SELECT id, zone_templ_id, name, type, content, ttl, prio FROM zone_templ_records WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch();
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

    /**
     * Get all zone template records from a zone template id
     *
     * Retrieve all fields of the records and send it back to the function caller.
     *
     * @param int $id zone template ID
     * @param int $rowstart Starting row (default=0)
     * @param int $rowamount Number of rows per query (default=9999)
     * @param string $sortby Column to sort by (default='name')
     *
     * @return array zone template records numerically indexed
     * [id,zone_templd_id,name,type,content,ttl,pro] or empty array if nothing is found
     */
    public static function getZoneTemplRecords($db, int $id, int $rowstart = 0, int $rowamount = self::DEFAULT_MAX_ROWS, string $sortby = 'name'): array
    {
        $allowedSortColumns = ['name', 'type', 'content', 'priority', 'ttl'];
        $sortby = in_array($sortby, $allowedSortColumns) ? htmlspecialchars($sortby) : 'name';

        $query = "SELECT id FROM zone_templ_records WHERE zone_templ_id = :id ORDER BY " . $sortby;
        if ($rowamount < self::DEFAULT_MAX_ROWS) {
            $query .= " LIMIT " . $rowamount;
            if ($rowstart > 0) {
                $query .= " OFFSET " . $rowstart;
            }
        }

        $stmt = $db->prepare($query);
        $stmt->execute([':id' => $id]);

        $ret = [];
        $retCount = 0;
        while ($r = $stmt->fetch()) {
            // Call get_record_from_id for each row.
            $ret[$retCount] = ZoneTemplate::getZoneTemplRecordFromId($db, $r["id"]);
            $retCount++;
        }
        return ($retCount > 0 ? $ret : []);
    }

    /**
     * Add a record for a zone template
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
    public function addZoneTemplRecord(int $zone_templ_id, string $name, string $type, string $content, int $ttl, int $prio): bool
    {
        if (!(UserManager::verifyPermission($this->db, 'zone_templ_edit'))) {
            $this->messageService->addSystemError(_("You do not have the permission to add a record to this zone template."));
            return false;
        }

        if ($content == '') {
            $this->messageService->addSystemError(_('Your content field doesnt have a legit value.'));
            return false;
        }

        if ($name == '') {
            $this->messageService->addSystemError(_('Invalid hostname.'));
            return false;
        }

        // Check if priority is valid for this record type
        if (!is_numeric($prio) || $prio < 0 || $prio > 65535) {
            if ($type == 'MX' || $type == 'SRV') {
                $this->messageService->addSystemError(_('Priority for MX/SRV records must be a number between 0 and 65535.'));
                return false;
            }
        }

        // Add double quotes to content if it is a TXT record and dns_txt_auto_quote is enabled
        $content = $this->dnsFormatter->formatContent($type, $content);

        $query = "INSERT INTO zone_templ_records (zone_templ_id, name, type, content, ttl, prio) VALUES (:zone_templ_id, :name, :type, :content, :ttl, :prio)";
        $stmt = $this->db->prepare($query);
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

    /**
     * Modify zone template record
     *
     * Edit a record for a zone template.
     * This function validates it if correct it inserts it into the database.
     *
     * @param array $record zone record array
     *
     * @return boolean true on success, false otherwise
     */
    public function editZoneTemplRecord(array $record): bool
    {
        if (!(UserManager::verifyPermission($this->db, 'zone_templ_edit'))) {
            $this->messageService->addSystemError(_("You do not have the permission to edit this record."));
            return false;
        }

        if ($record['name'] == "") {
            $this->messageService->addSystemError(_('Invalid hostname.'));
            return false;
        }

        // Check if priority is valid for this record type
        if (!is_numeric($record['prio']) || $record['prio'] < 0 || $record['prio'] > 65535) {
            if ($record['type'] == 'MX' || $record['type'] == 'SRV') {
                $this->messageService->addSystemError(_('Priority for MX/SRV records must be a number between 0 and 65535.'));
                return false;
            }
        }

        // Add double quotes to content if it is a TXT record and dns_txt_auto_quote is enabled
        $record['content'] = $this->dnsFormatter->formatContent($record['type'], $record['content']);

        try {
            $stmt = $this->db->prepare("UPDATE zone_templ_records
                                SET name = :name,
                                type = :type,
                                content = :content,
                                ttl = :ttl,
                                prio = :prio
                                WHERE id = :id");

            $stmt->execute([
                ':name' => $record['name'],
                ':type' => $record['type'],
                ':content' => $record['content'],
                ':ttl' => $record['ttl'],
                ':prio' => $record['prio'] ?? 0,
                ':id' => $record['rid']
            ]);

            return true;
        } catch (\Exception $e) {
            $this->messageService->addSystemError(_('Error updating zone template record: ') . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete a record for a zone template by a given id
     *
     * @param int $rid template record id
     *
     * @return boolean true on success, false otherwise
     */
    public function deleteZoneTemplRecord(int $rid): bool
    {
        if (!(UserManager::verifyPermission($this->db, 'zone_templ_edit'))) {
            $this->messageService->addSystemError(_("You do not have the permission to delete this record."));
            return false;
        } else {
            try {
                $stmt = $this->db->prepare("DELETE FROM zone_templ_records WHERE id = :id");
                $stmt->execute([':id' => $rid]);
                return true;
            } catch (\Exception $e) {
                $this->messageService->addSystemError(_('Error deleting zone template record: ') . $e->getMessage());
                return false;
            }
        }
    }

    /**
     * Check if the session user is the owner for the zone template
     *
     * @param int $zone_templ_id zone template id
     * @param int $userid user id
     *
     * @return boolean true on success, false otherwise
     */
    public function isUserOwnerOfTemplate(int $zone_templ_id, int $userid): bool
    {
        try {
            $stmt = $this->db->prepare("SELECT owner FROM zone_templ WHERE id = :id");
            $stmt->execute([':id' => $zone_templ_id]);
            $result = $stmt->fetchColumn();

            return ($result == $userid);
        } catch (\Exception $e) {
            $this->messageService->addSystemError(_('Error checking template ownership: ') . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if the session user is the owner for the zone template (static version for backward compatibility)
     *
     * @deprecated Use instance method isUserOwnerOfTemplate() instead
     * @param mixed $db Database connection
     * @param int $zone_templ_id zone template id
     * @param int $userid user id
     *
     * @return boolean true on success, false otherwise
     */
    public static function getZoneTemplIsOwner($db, int $zone_templ_id, int $userid): bool
    {
        $stmt = $db->prepare("SELECT owner FROM zone_templ WHERE id = :id");
        $stmt->execute([':id' => $zone_templ_id]);
        $result = $stmt->fetchColumn();

        return ($result == $userid);
    }

    /**
     * Add a zone template from zone / another template
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
    public function addZoneTemplSaveAs(string $template_name, string $description, int $userid, array $records, array $options, string $domain = ''): bool
    {
        if (!(UserManager::verifyPermission($this->db, 'zone_templ_add'))) {
            $this->messageService->addSystemError(_("You do not have the permission to add a zone template."));
            return false;
        } else {
            try {
                $this->db->beginTransaction();

                // Determine if the template should be global based on options
                $isGlobal = isset($options['global']) && $options['global'] === true;
                $owner = $isGlobal ? 0 : $userid; // 0 for global templates, user ID otherwise

                $stmt = $this->db->prepare("INSERT INTO zone_templ (name, descr, owner, created_by) 
                    VALUES (:name, :descr, :owner, :created_by)");

                $stmt->execute([
                    ':name' => $template_name,
                    ':descr' => $description,
                    ':owner' => $owner,
                    ':created_by' => $userid
                ]);

                $zone_templ_id = $this->db->lastInsertId();

                // Check if the records include an SOA record
                $hasSOA = false;

                // Prepare statement once outside the loop for better performance
                $recordStmt = $this->db->prepare("INSERT INTO zone_templ_records 
                    (zone_templ_id, name, type, content, ttl, prio) 
                    VALUES (:zone_templ_id, :name, :type, :content, :ttl, :prio)");

                foreach ($records as $record) {
                    if ($record['type'] === 'SOA') {
                        $hasSOA = true;
                    }

                    list($name, $content) = self::replaceWithTemplatePlaceholders($domain, $record, $options);

                    $recordStmt->execute([
                        ':zone_templ_id' => $zone_templ_id,
                        ':name' => $name,
                        ':type' => $record['type'],
                        ':content' => $content,
                        ':ttl' => $record['ttl'],
                        ':prio' => $record['prio'] ?? 0
                    ]);
                }

                // If there's no SOA record, add one automatically
                if (!$hasSOA) {
                    $this->addDefaultSOARecordToTemplate((int)$zone_templ_id);
                }

                $this->db->commit();
                return true;
            } catch (\Exception $e) {
                $this->db->rollBack();
                $this->messageService->addSystemError(_('Error creating zone template: ') . $e->getMessage());
                return false;
            }
        }
    }

    /**
     * Get list of all zones using template
     *
     * @param int $zone_templ_id zone template id
     * @param int $userid user id
     *
     * @return array array of zones ids
     */
    public function getListZoneUseTempl(int $zone_templ_id, int $userid): array
    {
        $perm_edit = Permission::getEditPermission($this->db);

        $pdns_db_name = $this->config->get('database', 'pdns_name');
        $domains_table = $pdns_db_name ? $pdns_db_name . '.domains' : 'domains';
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

        $params = [':zone_templ_id' => $zone_templ_id];
        $sql_add = '';

        if ($perm_edit != "all") {
            $sql_add = " AND zones.domain_id = $domains_table.id AND zones.owner = :userid";
            $params[':userid'] = $userid;
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
            AND zone_templ_id = :zone_templ_id
            GROUP BY $domains_table.name, $domains_table.id, $domains_table.type, Record_Count.count_records";

        try {
            $stmt = $this->db->prepare($query);
            $stmt->execute($params);

            $zone_list = [];
            while ($zone = $stmt->fetch()) {
                $zone_list[] = $zone['id'];
            }
            return $zone_list;
        } catch (\Exception $e) {
            $this->messageService->addSystemError(_('Error retrieving zones using template: ') . $e->getMessage());
            return [];
        }
    }

    /**
     * Get detailed information about zones using a specific template
     *
     * @param int $zone_templ_id zone template id
     * @param int $userid user id
     *
     * @return array array of zone details
     */
    public function getZonesUsingTemplate(int $zone_templ_id, int $userid): array
    {
        $perm_edit = Permission::getEditPermission($this->db);

        $pdns_db_name = $this->config->get('database', 'pdns_name');
        $domains_table = $pdns_db_name ? $pdns_db_name . '.domains' : 'domains';
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

        $params = [':zone_templ_id' => $zone_templ_id];
        $sql_add = '';

        if ($perm_edit != "all") {
            $sql_add = " AND zones.domain_id = $domains_table.id AND zones.owner = :userid";
            $params[':userid'] = $userid;
        }

        $query = "SELECT $domains_table.id,
                $domains_table.name,
                $domains_table.type,
                Record_Count.count_records,
                zones.owner,
                zones.comment,
                u.username as owner_name,
                u.fullname as owner_fullname
                FROM $domains_table
                LEFT JOIN zones ON $domains_table.id=zones.domain_id
                LEFT JOIN users u ON zones.owner=u.id
                LEFT JOIN (
                    SELECT COUNT(domain_id) AS count_records, domain_id FROM $records_table GROUP BY domain_id
                ) Record_Count ON Record_Count.domain_id=$domains_table.id
                WHERE 1=1" . $sql_add . "
                AND zone_templ_id = :zone_templ_id
                GROUP BY $domains_table.name, $domains_table.id, $domains_table.type, 
                        Record_Count.count_records, zones.owner, zones.comment, 
                        u.username, u.fullname
                ORDER BY $domains_table.name";

        try {
            $stmt = $this->db->prepare($query);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (\Exception $e) {
            $this->messageService->addSystemError(_('Failed to get list of zones using template: ') . $e->getMessage());
            return [];
        }
    }

    /**
     * Modify zone template
     *
     * @param array $details array of new zone template details
     * @param int $zone_templ_id zone template id
     * @param int $user_id User ID that is editing the template
     *
     * @return boolean true on success, false otherwise
     */
    public function editZoneTempl(array $details, int $zone_templ_id, int $user_id): bool
    {
        $zone_name_exists = $this->zoneTemplNameAndIdExists($details['templ_name'], $zone_templ_id);
        if (!(UserManager::verifyPermission($this->db, 'zone_templ_edit'))) {
            $this->messageService->addSystemError(_("You do not have the permission to edit a zone template."));
            return false;
        } elseif ($zone_name_exists != '0') {
            $this->messageService->addSystemError(_('Zone template with this name already exists, please choose another one.'));
            return false;
        } else {
            $query = 'UPDATE zone_templ SET name=:templ_name, descr=:templ_descr';
            $params = [
                "templ_name" => $details['templ_name'],
                "templ_descr" => $details['templ_descr'],
                "templ_id" => $zone_templ_id
            ];

            // When making a template global, we set owner=0 but keep created_by intact
            if (isset($details['templ_global'])) {
                $query .= ', owner=0';
            } else {
                $query .= ', owner=:templ_owner';
                $params['templ_owner'] = $user_id;
            }

            $query .= ' WHERE id=:templ_id';
            $stmt = $this->db->prepare($query);

            $stmt->execute($params);

            return true;
        }
    }


    /**
     * Check if zone template name exists
     *
     * @param string $zone_templ_name zone template name
     *
     * @return bool number of matching templates
     */
    public function zoneTemplNameExists(string $zone_templ_name): bool
    {
        try {
            $stmt = $this->db->prepare("SELECT COUNT(id) FROM zone_templ WHERE name = :name");
            $stmt->execute([':name' => $zone_templ_name]);
            return (bool) $stmt->fetchColumn();
        } catch (\Exception $e) {
            $this->messageService->addSystemError(_('Error checking template name existence: ') . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if zone template name and id exists
     *
     * @param string $zone_templ_name zone template name
     * @param int $zone_templ_id zone template id
     *
     * @return bool number of matching templates
     */
    public function zoneTemplNameAndIdExists(string $zone_templ_name, int $zone_templ_id): bool
    {
        try {
            $stmt = $this->db->prepare("SELECT COUNT(id) FROM zone_templ WHERE name = :name AND id != :id");
            $stmt->execute([
                ':name' => $zone_templ_name,
                ':id' => $zone_templ_id
            ]);
            return (bool) $stmt->fetchColumn();
        } catch (\Exception $e) {
            $this->messageService->addSystemError(_('Error checking template existence: ') . $e->getMessage());
            return false;
        }
    }

    /**
     * Parse string and substitute domain and serial
     *
     * @param string $val string to parse containing tokens '[ZONE]' and '[SERIAL]'
     * @param string $domain domain to substitute for '[ZONE]'
     *
     * @return string interpolated/parsed string
     */
    public function parseTemplateValue(string $val, string $domain): string
    {
        $dns_ns1 = $this->config->get('dns', 'ns1');
        $dns_ns2 = $this->config->get('dns', 'ns2');
        $dns_ns3 = $this->config->get('dns', 'ns3');
        $dns_ns4 = $this->config->get('dns', 'ns4');
        $dns_hostmaster = $this->config->get('dns', 'hostmaster');

        // Get SOA parameters for SOA records
        $soa_refresh = $this->config->get('dns', 'soa_refresh');
        $soa_retry = $this->config->get('dns', 'soa_retry');
        $soa_expire = $this->config->get('dns', 'soa_expire');
        $soa_minimum = $this->config->get('dns', 'soa_minimum');

        $serial = date("Ymd");
        $serial .= "00";

        // Parse domain components
        $domainComponents = $this->domainParsingService->parseDomain($domain);
        $domainName = $domainComponents['domain'];
        $tld = $domainComponents['tld'];

        $val = str_replace('[ZONE]', $domain, $val);
        $val = str_replace('[DOMAIN]', $domainName, $val);
        $val = str_replace('[TLD]', $tld, $val);
        $val = str_replace('[SERIAL]', $serial, $val);
        $val = str_replace('[NS1]', $dns_ns1, $val);
        $val = str_replace('[NS2]', $dns_ns2, $val);
        $val = str_replace('[NS3]', $dns_ns3, $val);
        $val = str_replace('[NS4]', $dns_ns4, $val);
        $val = str_replace('[HOSTMASTER]', $dns_hostmaster, $val);

        // Add SOA value placeholders
        $val = str_replace('[SOA_REFRESH]', $soa_refresh, $val);
        $val = str_replace('[SOA_RETRY]', $soa_retry, $val);
        $val = str_replace('[SOA_EXPIRE]', $soa_expire, $val);
        $val = str_replace('[SOA_MINIMUM]', $soa_minimum, $val);

        // Check if this is an SOA record that should have SOA parameters
        if (str_contains($val, 'SOA')) {
            // Extract all parts of the string
            $parts = explode(' ', $val);

            // Check if the SOA parameters are already included
            // SOA record should have at least 7 parts:
            // domain IN SOA ns hostmaster serial refresh retry expire minimum
            if (count($parts) < 7) {
                // Append the SOA parameters if they're missing
                $val .= " $soa_refresh $soa_retry $soa_expire $soa_minimum";
            }
        }

        return $val;
    }
}
