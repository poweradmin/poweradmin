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

use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Domain\Service\DnsValidation\IPAddressValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOLayer;
use Poweradmin\Infrastructure\Service\MessageService;

/**
 * Service class for managing PowerDNS supermasters
 */
class SupermasterManager implements SupermasterManagerInterface
{
    private PDOLayer $db;
    private ConfigurationManager $config;
    private MessageService $messageService;
    private HostnameValidator $hostnameValidator;
    private IPAddressValidator $ipAddressValidator;

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
        $this->messageService = new MessageService();
        $this->hostnameValidator = new HostnameValidator($config);
        $this->ipAddressValidator = new IPAddressValidator();
    }

    /**
     * Add Supermaster
     *
     * Add a trusted supermaster to the global supermasters table
     *
     * @param string $master_ip Supermaster IP address
     * @param string $ns_name Hostname of supermasterfound in NS records for domain
     * @param string $account Account name used for tracking
     *
     * @return boolean true on success
     */
    public function addSupermaster(string $master_ip, string $ns_name, string $account): bool
    {
        if (!$this->ipAddressValidator->isValidIPv4($master_ip) && !$this->ipAddressValidator->isValidIPv6($master_ip)) {
            $this->messageService->addSystemError(_('This is not a valid IPv4 or IPv6 address.'));
            return false;
        }

        if (!$this->hostnameValidator->isValid($ns_name)) {
            $this->messageService->addSystemError(_('Invalid hostname.'));
            return false;
        }

        if (!self::validateAccount($account)) {
            $this->messageService->addSystemError(sprintf(_('Invalid argument(s) given to function %s %s'), "addSupermaster", "given account name is invalid (alpha chars only)"));
            return false;
        }

        if ($this->supermasterIpNameExists($master_ip, $ns_name)) {
            $this->messageService->addSystemError(_('There is already a supermaster with this IP address and hostname.'));
            return false;
        } else {
            $pdns_db_name = $this->config->get('database', 'pdns_name');
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

    /**
     * Delete Supermaster
     *
     * Delete a supermaster from the global supermasters table
     *
     * @param string $master_ip Supermaster IP address
     * @param string $ns_name Hostname of supermaster
     *
     * @return boolean true on success
     */
    public function deleteSupermaster(string $master_ip, string $ns_name): bool
    {
        if ($this->ipAddressValidator->isValidIPv4($master_ip) || $this->ipAddressValidator->isValidIPv6($master_ip) || $this->hostnameValidator->isValid($ns_name)) {
            $pdns_db_name = $this->config->get('database', 'pdns_name');
            $supermasters_table = $pdns_db_name ? $pdns_db_name . ".supermasters" : "supermasters";

            $stmt = $this->db->prepare("DELETE FROM $supermasters_table WHERE ip = :master_ip AND nameserver = :ns_name");
            $stmt->execute([
                ':master_ip' => $master_ip,
                ':ns_name' => $ns_name
            ]);
            return true;
        } else {
            $this->messageService->addSystemError(sprintf(_('Invalid argument(s) given to function %s %s'), "deleteSupermaster", "No or no valid ipv4 or ipv6 address given."));
        }
        return false;
    }

    /**
     * Get All Supermasters
     *
     * Gets an array of arrays of supermaster details
     *
     * @return array[] supermasters detail [master_ip,ns_name,account]s
     */
    public function getSupermasters(): array
    {
        $pdns_db_name = $this->config->get('database', 'pdns_name');
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

    /**
     * Get Supermaster Info from IP
     *
     * Retrieve supermaster details from supermaster IP address
     *
     * @param string $master_ip Supermaster IP address
     *
     * @return array array of supermaster details
     */
    public function getSupermasterInfoFromIp(string $master_ip): array
    {
        if ($this->ipAddressValidator->isValidIPv4($master_ip) || $this->ipAddressValidator->isValidIPv6($master_ip)) {
            $pdns_db_name = $this->config->get('database', 'pdns_name');
            $supermasters_table = $pdns_db_name ? $pdns_db_name . ".supermasters" : "supermasters";

            $stmt = $this->db->prepare("SELECT ip,nameserver,account FROM $supermasters_table WHERE ip = :master_ip");
            $stmt->execute([':master_ip' => $master_ip]);
            $result = $stmt->fetch();

            return array(
                "master_ip" => $result["ip"],
                "ns_name" => $result["nameserver"],
                "account" => $result["account"]
            );
        } else {
            $this->messageService->addSystemError(sprintf(_('Invalid argument(s) given to function %s %s'), "getSupermasterInfoFromIp", "No or no valid ipv4 or ipv6 address given."));
            return array();
        }
    }

    /**
     * Check if Supermaster IP address exists
     *
     * @param string $master_ip Supermaster IP
     *
     * @return boolean true if exists, otherwise false
     */
    public function supermasterExists(string $master_ip): bool
    {
        if ($this->ipAddressValidator->isValidIPv4($master_ip) || $this->ipAddressValidator->isValidIPv6($master_ip)) {
            $pdns_db_name = $this->config->get('database', 'pdns_name');
            $supermasters_table = $pdns_db_name ? $pdns_db_name . ".supermasters" : "supermasters";

            $stmt = $this->db->prepare("SELECT ip FROM $supermasters_table WHERE ip = :master_ip");
            $stmt->execute([':master_ip' => $master_ip]);
            $result = $stmt->fetchColumn();
            return (bool)$result;
        } else {
            $this->messageService->addSystemError(sprintf(_('Invalid argument(s) given to function %s %s'), "supermasterExists", "No or no valid IPv4 or IPv6 address given."));
            return false;
        }
    }

    /**
     * Check if Supermaster IP Address and NS Name combo exists
     *
     * @param string $master_ip Supermaster IP Address
     * @param string $ns_name Supermaster NS Name
     *
     * @return boolean true if exists, false otherwise
     */
    public function supermasterIpNameExists(string $master_ip, string $ns_name): bool
    {
        if (($this->ipAddressValidator->isValidIPv4($master_ip) || $this->ipAddressValidator->isValidIPv6($master_ip)) && $this->hostnameValidator->isValid($ns_name)) {
            $pdns_db_name = $this->config->get('database', 'pdns_name');
            $supermasters_table = $pdns_db_name ? $pdns_db_name . ".supermasters" : "supermasters";

            $stmt = $this->db->prepare("SELECT ip FROM $supermasters_table WHERE ip = :master_ip AND nameserver = :ns_name");
            $stmt->execute([
                ':master_ip' => $master_ip,
                ':ns_name' => $ns_name
            ]);
            $result = $stmt->fetchColumn();
            return (bool)$result;
        } else {
            $this->messageService->addSystemError(sprintf(_('Invalid argument(s) given to function %s %s'), "supermasterExists", "No or no valid IPv4 or IPv6 address given."));
            return false;
        }
    }

    /**
     * Update Supermaster
     *
     * Update a trusted supermaster in the global supermasters table
     *
     * @param string $old_master_ip Original supermaster IP address
     * @param string $old_ns_name Original hostname of supermaster
     * @param string $new_master_ip New supermaster IP address
     * @param string $new_ns_name New hostname of supermaster
     * @param string $account Account name used for tracking
     *
     * @return boolean true on success
     */
    public function updateSupermaster(string $old_master_ip, string $old_ns_name, string $new_master_ip, string $new_ns_name, string $account): bool
    {
        // Validate IP addresses
        if (!$this->ipAddressValidator->isValidIPv4($new_master_ip) && !$this->ipAddressValidator->isValidIPv6($new_master_ip)) {
            $this->messageService->addSystemError(_('This is not a valid IPv4 or IPv6 address.'));
            return false;
        }

        // Validate hostnames
        if (!$this->hostnameValidator->isValid($new_ns_name)) {
            $this->messageService->addSystemError(_('Invalid hostname.'));
            return false;
        }

        // Validate account
        if (!self::validateAccount($account)) {
            $this->messageService->addSystemError(sprintf(_('Invalid argument(s) given to function %s %s'), "updateSupermaster", "given account name is invalid (alpha chars only)"));
            return false;
        }

        // Check if source supermaster exists
        if (!$this->supermasterIpNameExists($old_master_ip, $old_ns_name)) {
            $this->messageService->addSystemError(_('The supermaster you are trying to edit does not exist.'));
            return false;
        }

        // Check for duplicate if IP or hostname changed
        if (
            ($old_master_ip !== $new_master_ip || $old_ns_name !== $new_ns_name)
            && $this->supermasterIpNameExists($new_master_ip, $new_ns_name)
        ) {
            $this->messageService->addSystemError(_('There is already a supermaster with this IP address and hostname.'));
            return false;
        }

        $pdns_db_name = $this->config->get('database', 'pdns_name');
        $supermasters_table = $pdns_db_name ? $pdns_db_name . ".supermasters" : "supermasters";

        $stmt = $this->db->prepare("UPDATE $supermasters_table SET ip = :new_master_ip, nameserver = :new_ns_name, account = :account 
                                    WHERE ip = :old_master_ip AND nameserver = :old_ns_name");

        $result = $stmt->execute([
            ':new_master_ip' => $new_master_ip,
            ':new_ns_name' => $new_ns_name,
            ':account' => $account,
            ':old_master_ip' => $old_master_ip,
            ':old_ns_name' => $old_ns_name
        ]);

        return (bool)$result;
    }

    /**
     * Validate Account is valid string
     *
     * @param string $account Account name alphanumeric and ._-
     *
     * @return boolean true is valid, false otherwise
     */
    public static function validateAccount(string $account): bool
    {
        if (preg_match("/^[A-Z0-9._-]+$/i", $account)) {
            return true;
        } else {
            return false;
        }
    }
}
