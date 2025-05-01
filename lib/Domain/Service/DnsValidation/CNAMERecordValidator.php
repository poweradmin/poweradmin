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

namespace Poweradmin\Domain\Service\DnsValidation;

use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Service\MessageService;
use Poweradmin\Infrastructure\Database\PDOLayer;

/**
 * Validator for CNAME DNS records
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class CNAMERecordValidator implements DnsRecordValidatorInterface
{
    private HostnameValidator $hostnameValidator;
    private TTLValidator $ttlValidator;
    private MessageService $messageService;
    private ConfigurationManager $config;
    private PDOLayer $db;

    /**
     * Constructor
     *
     * @param ConfigurationManager $config
     * @param PDOLayer $db
     */
    public function __construct(ConfigurationManager $config, PDOLayer $db)
    {
        $this->hostnameValidator = new HostnameValidator($config);
        $this->ttlValidator = new TTLValidator();
        $this->messageService = new MessageService();
        $this->config = $config;
        $this->db = $db;
    }

    /**
     * Validate CNAME record
     *
     * @param string $content Target hostname
     * @param string $name CNAME hostname
     * @param mixed $prio Priority (not used for CNAME records)
     * @param int|string $ttl TTL value
     * @param int $defaultTTL Default TTL value
     * @param int $rid Record ID (for checking uniqueness)
     * @param string $zone Zone name (for checking empty CNAME)
     *
     * @return array|bool Array with validated data or false if validation fails
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, $defaultTTL, int $rid = 0, string $zone = ''): array|bool
    {
        // 1. Validate CNAME uniqueness (no other records should exist with same name)
        if (!$this->isValidCnameUnique($name, $rid)) {
            return false;
        }

        // 2. Check for MX or NS records pointing to this CNAME
        if (!$this->isValidCnameName($name)) {
            return false;
        }

        // 3. Validate CNAME hostname
        $hostnameResult = $this->hostnameValidator->isValidHostnameFqdn($name, 1);
        if ($hostnameResult === false) {
            return false;
        }
        $name = $hostnameResult['hostname'];

        // 4. Validate target hostname
        $contentHostnameResult = $this->hostnameValidator->isValidHostnameFqdn($content, 0);
        if ($contentHostnameResult === false) {
            return false;
        }
        $content = $contentHostnameResult['hostname'];

        // 5. Check that zone does not have an empty CNAME RR
        if (!empty($zone) && !$this->isNotEmptyCnameRR($name, $zone)) {
            return false;
        }

        // 6. Validate TTL
        $validatedTtl = $this->ttlValidator->isValidTTL($ttl, $defaultTTL);
        if ($validatedTtl === false) {
            return false;
        }

        // 7. Validate priority (should be 0 for CNAME records)
        $validatedPrio = $this->validatePriority($prio);
        if ($validatedPrio === false) {
            $this->messageService->addSystemError(_('Invalid value for prio field.'));
            return false;
        }

        return [
            'content' => $content,
            'name' => $name,
            'prio' => $validatedPrio,
            'ttl' => $validatedTtl
        ];
    }

    /**
     * Validate priority for CNAME records
     * CNAME records don't use priority, so it should be 0
     *
     * @param mixed $prio Priority value
     *
     * @return int|bool 0 if valid, false otherwise
     */
    private function validatePriority(mixed $prio): int|bool
    {
        // If priority is not provided or empty, set it to 0
        if (!isset($prio) || $prio === "") {
            return 0;
        }

        // If provided, ensure it's 0 for CNAME records
        if (is_numeric($prio) && intval($prio) === 0) {
            return 0;
        }

        return false;
    }

    /**
     * Check if CNAME is unique (doesn't overlap other record types)
     *
     * @param string $name CNAME
     * @param int $rid Record ID
     *
     * @return boolean true if unique, false if duplicate
     */
    public function isValidCnameUnique(string $name, int $rid): bool
    {
        $pdns_db_name = $this->config->get('database', 'pdns_name');
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

        $where = ($rid > 0 ? " AND id != " . $this->db->quote($rid, 'integer') : '');
        // Check if there are any records with this name
        $query = "SELECT id FROM $records_table
                    WHERE name = " . $this->db->quote($name, 'text') .
                    " AND TYPE != 'CNAME'" .
                    $where;

        $response = $this->db->queryOne($query);
        if ($response) {
            $this->messageService->addSystemError(_('This is not a valid CNAME. There already exists a record with this name.'));
            return false;
        }
        return true;
    }

    /**
     * Check if CNAME is valid
     *
     * Check if any MX or NS entries exist which invalidate CNAME
     *
     * @param string $name CNAME to lookup
     *
     * @return boolean true if valid, false otherwise
     */
    public function isValidCnameName(string $name): bool
    {
        $pdns_db_name = $this->config->get('database', 'pdns_name');
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

        $query = "SELECT id FROM $records_table
                WHERE content = " . $this->db->quote($name, 'text') . "
                AND (type = " . $this->db->quote('MX', 'text') . " OR type = " . $this->db->quote('NS', 'text') . ")";

        $response = $this->db->queryOne($query);

        if (!empty($response)) {
            $this->messageService->addSystemError(_('This is not a valid CNAME. Did you assign an MX or NS record to the record?'));
            return false;
        }

        return true;
    }

    /**
     * Check that the zone does not have an empty CNAME RR
     *
     * @param string $name
     * @param string $zone
     * @return bool
     */
    public function isNotEmptyCnameRR(string $name, string $zone): bool
    {
        if ($name == $zone) {
            $this->messageService->addSystemError(_('Empty CNAME records are not allowed.'));
            return false;
        }
        return true;
    }

    /**
     * Check if CNAME already exists
     *
     * @param string $name CNAME
     * @param int $rid Record ID
     *
     * @return boolean true if non-existent, false if exists
     */
    public function isValidCnameExistence(string $name, int $rid): bool
    {
        $pdns_db_name = $this->config->get('database', 'pdns_name');
        $records_table = $pdns_db_name ? $pdns_db_name . '.records' : 'records';

        $where = ($rid > 0 ? " AND id != " . $this->db->quote($rid, 'integer') : '');
        $query = "SELECT id FROM $records_table
                        WHERE name = " . $this->db->quote($name, 'text') . $where . "
                        AND TYPE = 'CNAME'";

        $response = $this->db->queryOne($query);
        if ($response) {
            $this->messageService->addSystemError(_('This is not a valid record. There already exists a CNAME with this name.'));
            return false;
        }
        return true;
    }
}
