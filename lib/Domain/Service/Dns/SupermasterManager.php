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

use PDO;
use Poweradmin\Domain\Service\DnsValidation\HostnameValidator;
use Poweradmin\Domain\Service\DnsValidation\IPAddressValidator;
use Poweradmin\Domain\Service\SupermasterBackendInterface;
use Poweradmin\Domain\Config\ConfigurationInterface;
use Poweradmin\Infrastructure\Database\DbCompat;

/**
 * Creates, updates and deletes PowerDNS supermasters.
 */
class SupermasterManager
{
    private PDO $db;
    private ConfigurationInterface $config;
    private HostnameValidator $hostnameValidator;
    private IPAddressValidator $ipAddressValidator;
    private SupermasterBackendInterface $backendProvider;

    /**
     * Constructor
     *
     * @param PDO $db Database connection
     * @param ConfigurationInterface $config Configuration manager
     * @param SupermasterBackendInterface $backendProvider DNS backend provider
     */
    public function __construct(PDO $db, ConfigurationInterface $config, SupermasterBackendInterface $backendProvider)
    {
        $this->db = $db;
        $this->config = $config;
        $this->hostnameValidator = new HostnameValidator($config);
        $this->ipAddressValidator = new IPAddressValidator();
        $this->backendProvider = $backendProvider;
    }

    /**
     * Add a trusted supermaster to the global supermasters table.
     *
     * @param string $master_ip Supermaster IP address
     * @param string $ns_name Hostname of the supermaster found in NS records for the domain
     * @param string $account Account name used for tracking
     */
    public function addSupermaster(string $master_ip, string $ns_name, string $account): SupermasterWriteResult
    {
        $refusal = $this->validateFields($master_ip, $ns_name, $account);
        if ($refusal !== null) {
            return $refusal;
        }

        if ($this->supermasterIpNameExists($master_ip, $ns_name)) {
            return SupermasterWriteResult::refused(SupermasterWriteResult::ERR_EXISTS, _('There is already a supermaster with this IP address and hostname.'), 409);
        }

        return $this->backendProvider->addSupermaster($master_ip, $ns_name, $account)
            ? SupermasterWriteResult::ok()
            : SupermasterWriteResult::refused(SupermasterWriteResult::ERR_BACKEND, _('An error occurred. Please try again.'), 500);
    }

    /**
     * Delete a supermaster from the global supermasters table.
     *
     * @param string $master_ip Supermaster IP address
     * @param string $ns_name Hostname of the supermaster
     */
    public function deleteSupermaster(string $master_ip, string $ns_name): SupermasterWriteResult
    {
        if (!$this->isValidIp($master_ip) && !$this->hostnameValidator->isValid($ns_name)) {
            return SupermasterWriteResult::refused(SupermasterWriteResult::ERR_INVALID_IP, _('This is not a valid IPv4 or IPv6 address.'));
        }

        return $this->backendProvider->deleteSupermaster($master_ip, $ns_name)
            ? SupermasterWriteResult::ok()
            : SupermasterWriteResult::refused(SupermasterWriteResult::ERR_BACKEND, _('An error occurred. Please try again.'), 500);
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
        $accountMatch = DbCompat::accentSensitiveEquals($this->config->get('database', 'type'), 'username', ':account');
        $fullnameStmt = $this->db->prepare("SELECT fullname FROM users WHERE $accountMatch");

        $supermasters = [];
        foreach ($this->backendProvider->getSupermasters() as $sm) {
            $account = (string)($sm['account'] ?? '');

            // The account names the Poweradmin user the zones will be handed to
            $fullname = '';
            if ($account !== '') {
                $fullnameStmt->execute([':account' => $account]);
                $fullname = $fullnameStmt->fetchColumn() ?: '';
            }

            $supermasters[] = [
                "master_ip" => (string)($sm['master_ip'] ?? ''),
                "ns_name" => (string)($sm['ns_name'] ?? ''),
                "account" => $account,
                "fullname" => $fullname,
            ];
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
        if (!$this->isValidIp($master_ip)) {
            return [];
        }

        foreach ($this->getSupermasters() as $sm) {
            if ($sm['master_ip'] === $master_ip) {
                return $sm;
            }
        }
        return array();
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
        if (!$this->isValidIp($master_ip)) {
            return false;
        }

        foreach ($this->backendProvider->getSupermasters() as $sm) {
            if (($sm['master_ip'] ?? '') === $master_ip) {
                return true;
            }
        }
        return false;
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
        if (!$this->isValidIp($master_ip) || !$this->hostnameValidator->isValid($ns_name)) {
            return false;
        }

        foreach ($this->backendProvider->getSupermasters() as $sm) {
            if (($sm['master_ip'] ?? '') === $master_ip && ($sm['ns_name'] ?? '') === $ns_name) {
                return true;
            }
        }
        return false;
    }

    /**
     * Update a trusted supermaster in the global supermasters table.
     *
     * @param string $old_master_ip Original supermaster IP address
     * @param string $old_ns_name Original hostname of the supermaster
     * @param string $new_master_ip New supermaster IP address
     * @param string $new_ns_name New hostname of the supermaster
     * @param string $account Account name used for tracking
     */
    public function updateSupermaster(string $old_master_ip, string $old_ns_name, string $new_master_ip, string $new_ns_name, string $account): SupermasterWriteResult
    {
        $refusal = $this->validateFields($new_master_ip, $new_ns_name, $account);
        if ($refusal !== null) {
            return $refusal;
        }

        if (!$this->supermasterIpNameExists($old_master_ip, $old_ns_name)) {
            return SupermasterWriteResult::refused(SupermasterWriteResult::ERR_NOT_FOUND, _('The supermaster you are trying to edit does not exist.'), 404);
        }

        // A duplicate only matters when the identifying pair changes
        if (
            ($old_master_ip !== $new_master_ip || $old_ns_name !== $new_ns_name)
            && $this->supermasterIpNameExists($new_master_ip, $new_ns_name)
        ) {
            return SupermasterWriteResult::refused(SupermasterWriteResult::ERR_EXISTS, _('There is already a supermaster with this IP address and hostname.'), 409);
        }

        return $this->backendProvider->updateSupermaster($old_master_ip, $old_ns_name, $new_master_ip, $new_ns_name, $account)
            ? SupermasterWriteResult::ok()
            : SupermasterWriteResult::refused(SupermasterWriteResult::ERR_BACKEND, _('An error occurred. Please try again.'), 500);
    }

    private function validateFields(string $master_ip, string $ns_name, string $account): ?SupermasterWriteResult
    {
        if (!$this->isValidIp($master_ip)) {
            return SupermasterWriteResult::refused(SupermasterWriteResult::ERR_INVALID_IP, _('This is not a valid IPv4 or IPv6 address.'));
        }
        if (!$this->hostnameValidator->isValid($ns_name)) {
            return SupermasterWriteResult::refused(SupermasterWriteResult::ERR_INVALID_HOSTNAME, _('Invalid hostname.'));
        }
        if (!self::validateAccount($account)) {
            return SupermasterWriteResult::refused(SupermasterWriteResult::ERR_INVALID_ACCOUNT, sprintf(_('Invalid argument(s) given to function %s %s'), 'validateAccount', 'given account name is invalid (alpha chars only)'));
        }

        return null;
    }

    private function isValidIp(string $ip): bool
    {
        return $this->ipAddressValidator->isValidIPv4($ip) || $this->ipAddressValidator->isValidIPv6($ip);
    }

    /**
     * Get distinct slave server IP addresses
     *
     * @return array List of unique slave server IP addresses
     */
    public function getSlaveServerIPs(): array
    {
        $ips = [];
        foreach ($this->backendProvider->getSupermasters() as $sm) {
            $ips[(string)($sm['master_ip'] ?? '')] = true;
        }
        return array_keys($ips);
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
