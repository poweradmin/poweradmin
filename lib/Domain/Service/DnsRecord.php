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

use Exception;
use Poweradmin\Domain\Repository\DomainRepository;
use Poweradmin\Domain\Repository\RecordRepository;
use Poweradmin\Domain\Service\Dns\DomainManager;
use Poweradmin\Domain\Service\Dns\RecordManager;
use Poweradmin\Domain\Service\Dns\SOARecordManager;
use Poweradmin\Domain\Service\Dns\SupermasterManager;
use Poweradmin\Domain\Utility\DomainUtility;
use Poweradmin\Infrastructure\Service\DnsServiceFactory;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;
use Poweradmin\Infrastructure\Database\PDOLayer;

/**
 * DNS record functions
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 * @deprecated This class is being refactored into smaller services. Use the specific service classes instead.
 */
class DnsRecord
{
    private PDOLayer $db;
    private ConfigurationManager $config;
    private DnsRecordValidationServiceInterface $validationService;

    // New service instances
    /** @var SOARecordManager */
    private $soaRecordManager;
    /** @var DomainRepository */
    private $domainRepository;
    /** @var RecordRepository */
    private $recordRepository;
    /** @var RecordManager */
    private $recordManager;
    /** @var DomainManager */
    private $domainManager;
    /** @var SupermasterManager */
    private $supermasterManager;

    public function __construct(PDOLayer $db, ConfigurationManager $config)
    {
        $this->db = $db;
        $this->config = $config;
        $this->validationService = DnsServiceFactory::createDnsRecordValidationService($db, $config);

        // Initialize the new service instances
        $this->initializeDependencies();
    }

    /**
     * Initialize the dependencies for the class
     */
    private function initializeDependencies(): void
    {
        // Create the new service instances
        $this->soaRecordManager = new SOARecordManager($this->db, $this->config);
        $this->domainRepository = new DomainRepository($this->db, $this->config);
        $this->recordRepository = new RecordRepository($this->db, $this->config);

        // Create services with dependencies on repositories
        $this->recordManager = new RecordManager(
            $this->db,
            $this->config,
            $this->validationService,
            $this->soaRecordManager,
            $this->domainRepository
        );

        $this->domainManager = new DomainManager(
            $this->db,
            $this->config,
            $this->soaRecordManager,
            $this->domainRepository
        );

        $this->supermasterManager = new SupermasterManager($this->db, $this->config);
    }

    /** Check if Zone ID exists
     *
     * @param int $zid Zone ID
     *
     * @return int Domain count or false on failure
     */
    public function zoneIdExists(int $zid): int
    {
        return $this->domainRepository->zoneIdExists($zid);
    }

    /** Get Zone ID from Record ID
     *
     * @param int $rid Record ID
     *
     * @return int Zone ID
     */
    public function getZoneIdFromRecordId(int $rid): int
    {
        return $this->recordRepository->getZoneIdFromRecordId($rid);
    }

    /** Count Zone Records for Zone ID
     *
     * @param int $zone_id Zone ID
     *
     * @return int Record count
     */
    public function countZoneRecords(int $zone_id): int
    {
        return $this->recordRepository->countZoneRecords($zone_id);
    }

    /** Get SOA record content for Zone ID
     *
     * @param int $zone_id Zone ID
     *
     * @return string SOA content
     */
    public function getSOARecord(int $zone_id): string
    {
        return $this->soaRecordManager->getSOARecord($zone_id);
    }

    /** Get SOA Serial Number
     *
     * @param string $soa_rec SOA record content
     *
     * @return string|null SOA serial
     */
    public static function getSOASerial(string $soa_rec): ?string
    {
        return SOARecordManager::getSOASerial($soa_rec);
    }

    /** Get Next Date
     *
     * @param string $curr_date Current date in YYYYMMDD format
     *
     * @return string Date +1 day
     */
    public static function getNextDate(string $curr_date): string
    {
        return SOARecordManager::getNextDate($curr_date);
    }

    /** Get Next Serial
     *
     * @param int|string $curr_serial Current Serial No
     *
     * @return string|int Next serial number
     */
    public function getNextSerial(int|string $curr_serial): int|string
    {
        return $this->soaRecordManager->getNextSerial($curr_serial);
    }

    /** Return SOA record
     *
     * Returns SOA record with incremented serial number
     *
     * @param string $soa_rec Current SOA record
     *
     * @return string true if success
     */
    public function getUpdatedSOARecord(string $soa_rec): string
    {
        return $this->soaRecordManager->getUpdatedSOARecord($soa_rec);
    }

    /** Update SOA serial
     *
     * Increments SOA serial to next possible number
     *
     * @param int $domain_id Domain ID
     *
     * @return boolean true if success
     */
    public function updateSOASerial(int $domain_id): bool
    {
        return $this->soaRecordManager->updateSOASerial($domain_id);
    }

    /** Get Zone comment
     *
     * @param int $zone_id Zone ID
     *
     * @return string Zone Comment
     */
    public static function getZoneComment($db, int $zone_id): string
    {
        return RecordManager::getZoneComment($db, $zone_id);
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
    public function editZoneComment(int $zone_id, string $comment): bool
    {
        return $this->recordManager->editZoneComment($zone_id, $comment);
    }

    /** Edit a record
     *
     * This function validates it if correct it inserts it into the database.
     *
     * @param array $record Record structure to update
     *
     * @return boolean true if successful
     */
    public function editRecord(array $record): bool
    {
        return $this->recordManager->editRecord($record);
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
     * @param mixed $prio Priority of record
     *
     * @return boolean true if successful
     * @throws Exception
     */
    public function addRecord(int $zone_id, string $name, string $type, string $content, int $ttl, mixed $prio): bool
    {
        return $this->recordManager->addRecord($zone_id, $name, $type, $content, $ttl, $prio);
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
    public function addSupermaster(string $master_ip, string $ns_name, string $account): bool
    {
        return $this->supermasterManager->addSupermaster($master_ip, $ns_name, $account);
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
    public function deleteSupermaster(string $master_ip, string $ns_name): bool
    {
        return $this->supermasterManager->deleteSupermaster($master_ip, $ns_name);
    }

    /** Update Supermaster
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
        return $this->supermasterManager->updateSupermaster($old_master_ip, $old_ns_name, $new_master_ip, $new_ns_name, $account);
    }

    /** Get Supermaster Info from IP
     *
     * Retrieve supermaster details from supermaster IP address
     *
     * @param string $master_ip Supermaster IP address
     *
     * @return array array of supermaster details
     */
    public function getSupermasterInfoFromIp(string $master_ip): array
    {
        return $this->supermasterManager->getSupermasterInfoFromIp($master_ip);
    }

    /** Delete a record by a given record id
     *
     * @param int $rid Record ID
     *
     * @return boolean true on success
     */
    public function deleteRecord(int $rid): bool
    {
        return $this->recordManager->deleteRecord($rid);
    }

    /** Delete record reference to zone template
     *
     * @param int $rid Record ID
     *
     * @return boolean true on success
     */
    public static function deleteRecordZoneTempl($db, int $rid): bool
    {
        return RecordManager::deleteRecordZoneTempl($db, $rid);
    }

    /**
     * Add a domain to the database
     *
     * @param string $domain A domain name
     * @param int $owner Owner ID for domain
     * @param string $type Type of domain ['NATIVE','MASTER','SLAVE']
     * @param string $slave_master Master server hostname for domain
     * @param int|string $zone_template ID of zone template ['none' or int]
     *
     * @return boolean true on success
     */
    public function addDomain($db, string $domain, int $owner, string $type, string $slave_master, int|string $zone_template): bool
    {
        return $this->domainManager->addDomain($db, $domain, $owner, $type, $slave_master, $zone_template);
    }

    /** Deletes a domain by a given id
     *
     * Function always succeeds. If the field is not found in the database, that's what we want anyway.
     *
     * @param int $id Zone ID
     *
     * @return boolean true on success
     */
    public function deleteDomain(int $id): bool
    {
        return $this->domainManager->deleteDomain($id);
    }

    /** Record ID to Domain ID
     *
     * Gets the id of the domain by a given record id
     *
     * @param int $id Record ID
     * @return int Domain ID of record
     */
    public function recidToDomid(int $id): int
    {
        return $this->recordRepository->recidToDomid($id);
    }

    /** Change owner of a domain
     *
     * @param int $zone_id Zone ID
     * @param int $user_id User ID
     *
     * @return boolean true when successful
     */
    public static function addOwnerToZone($db, int $zone_id, int $user_id): bool
    {
        return DomainManager::addOwnerToZone($db, $zone_id, $user_id);
    }

    /** Delete owner from zone
     *
     * @param int $zone_id Zone ID
     * @param int $user_id User ID
     *
     * @return boolean true on success
     */
    public static function deleteOwnerFromZone($db, int $zone_id, int $user_id): bool
    {
        return DomainManager::deleteOwnerFromZone($db, $zone_id, $user_id);
    }

    /** Get Domain Name by domain ID
     *
     * @param int $id Domain ID
     *
     * @return bool|string Domain name
     */
    public function getDomainNameById(int $id): bool|string
    {
        return $this->domainRepository->getDomainNameById($id);
    }

    public function getDomainIdByName(string $name): bool|int
    {
        return $this->domainRepository->getDomainIdByName($name);
    }

    /** Get zone id from name
     *
     * @param string $zname Zone name
     * @return bool|int Zone ID
     */
    public function getZoneIdFromName(string $zname): bool|int
    {
        return $this->domainRepository->getZoneIdFromName($zname);
    }

    /** Get Zone details from Zone ID
     *
     * @param int $zid Zone ID
     * @return array array of zone details [type,name,master_ip,record_count]
     */
    public function getZoneInfoFromId(int $zid): array
    {
        return $this->domainRepository->getZoneInfoFromId($zid);
    }

    /** Get Zone(s) details from Zone IDs
     *
     * @param array $zones Zone IDs
     * @return array
     */
    public function getZoneInfoFromIds(array $zones): array
    {
        return $this->domainRepository->getZoneInfoFromIds($zones);
    }

    /** Convert IPv4 Address to PTR
     *
     * @param string $ip IPv4 Address
     * @return string PTR form of address
     */
    public static function convertIPv4AddrToPtrRec(string $ip): string
    {
        return DomainUtility::convertIPv4AddrToPtrRec($ip);
    }

    /** Convert IPv6 Address to PTR
     *
     * @param string $ip IPv6 Address
     * @return string PTR form of address
     */
    public static function convertIPv6AddrToPtrRec(string $ip): string
    {
        return DomainUtility::convertIPv6AddrToPtrRec($ip);
    }

    /** Get Best Matching in-addr.arpa Zone ID from Domain Name
     *
     * @param string $domain Domain name
     *
     * @return int Zone ID
     */
    public function getBestMatchingZoneIdFromName(string $domain): int
    {
        return $this->domainRepository->getBestMatchingZoneIdFromName($domain);
    }

    /** Check if Domain Exists
     *
     * Check if a domain is already existing.
     *
     * @param string $domain Domain name
     * @return boolean true if existing, false if it doesn't exist.
     */
    public function domainExists(string $domain): bool
    {
        return $this->domainRepository->domainExists($domain);
    }

    /** Get All Supermasters
     *
     * Gets an array of arrays of supermaster details
     *
     * @return array[] supermasters detail [master_ip,ns_name,account]s
     */
    public function getSupermasters(): array
    {
        return $this->supermasterManager->getSupermasters();
    }

    /** Check if Supermaster IP address exists
     *
     * @param string $master_ip Supermaster IP
     *
     * @return boolean true if exists, otherwise false
     */
    public function supermasterExists(string $master_ip): bool
    {
        return $this->supermasterManager->supermasterExists($master_ip);
    }

    /** Check if Supermaster IP Address and NS Name combo exists
     *
     * @param string $master_ip Supermaster IP Address
     * @param string $ns_name Supermaster NS Name
     *
     * @return boolean true if exists, false otherwise
     */
    public function supermasterIpNameExists(string $master_ip, string $ns_name): bool
    {
        return $this->supermasterManager->supermasterIpNameExists($master_ip, $ns_name);
    }

    /** Get a Record from a Record ID
     *
     * Retrieve all fields of the record and send it back to the function caller.
     *
     * @param int $id Record ID
     * @return int|array array of record detail, or -1 if nothing found
     */
    public function getRecordFromId(int $id): int|array
    {
        return $this->recordRepository->getRecordFromId($id);
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
    public function getRecordsFromDomainId($db_type, int $id, int $rowstart = 0, int $rowamount = 999999, string $sortby = 'name', string $sortDirection = 'ASC', bool $fetchComments = false): array|int
    {
        return $this->recordRepository->getRecordsFromDomainId($db_type, $id, $rowstart, $rowamount, $sortby, $sortDirection, $fetchComments);
    }

    /** Get list of owners for Domain ID
     *
     * @param int $id Domain ID
     *
     * @return array array of owners [id,fullname]
     */
    public static function getUsersFromDomainId($db, int $id): array
    {
        $owners = array();

        $stmt = $db->prepare("SELECT owner FROM zones WHERE domain_id = ?");
        $stmt->execute([$id]);
        $id_owners = $stmt;
        if ($id_owners) {
            while ($r = $id_owners->fetch()) {
                $userStmt = $db->prepare("SELECT username, fullname FROM users WHERE id = ?");
                $userStmt->execute([$r['owner']]);
                $result = $userStmt->fetch();
                if ($result) {
                    $owners[] = array(
                        "id" => $r['owner'],
                        "fullname" => $result["fullname"],
                        "username" => $result["username"],
                    );
                }
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
    public function getDomainType(int $id): string
    {
        return $this->domainRepository->getDomainType($id);
    }

    /** Get Slave Domain's Master
     *
     * @param int $id Domain ID
     *
     * @return array|bool|void Master server
     */
    public function getDomainSlaveMaster(int $id)
    {
        return $this->domainRepository->getDomainSlaveMaster($id);
    }

    /** Change Zone Type
     *
     * @param $db
     * @param string $type New Zone Type [NATIVE,MASTER,SLAVE]
     * @param int $id Zone ID
     *
     * @return void
     */
    public function changeZoneType(string $type, int $id): void
    {
        $this->domainManager->changeZoneType($type, $id);
    }

    /** Change Slave Zone's Master IP Address
     *
     * @param int $zone_id Zone ID
     * @param string $ip_slave_master Master IP Address
     *
     * @return null
     */
    public function changeZoneSlaveMaster(int $zone_id, string $ip_slave_master)
    {
        return $this->domainManager->changeZoneSlaveMaster($zone_id, $ip_slave_master);
    }

    /** Get Zone Template ID for Zone ID
     *
     * @param $db
     * @param int $zone_id Zone ID
     *
     * @return int Zone Template ID
     */
    public static function getZoneTemplate($db, int $zone_id): int
    {
        return DomainManager::getZoneTemplate($db, $zone_id);
    }

    /** Update All Zone Records for Zone ID with Zone Template
     *
     * @param string $db_type Database type
     * @param int $dns_ttl Default TTL
     * @param int $zone_id Zone ID to update
     * @param int $zone_template_id Zone Template ID to use for update
     *
     * @return void
     */
    public function updateZoneRecords(string $db_type, int $dns_ttl, int $zone_id, int $zone_template_id): void
    {
        $this->domainManager->updateZoneRecords($db_type, $dns_ttl, $zone_id, $zone_template_id);
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
    public function deleteDomains(array $domains): bool
    {
        return $this->domainManager->deleteDomains($domains);
    }

    /** Check if record exists
     *
     * @param string $name Record name
     *
     * @return boolean true on success, false on failure
     */
    public function recordNameExists(string $name): bool
    {
        return $this->recordRepository->recordNameExists($name);
    }

    /** Return domain level for given name
     *
     * @param string $name Zone name
     *
     * @return int domain level
     */
    public static function getDomainLevel(string $name): int
    {
        return DomainUtility::getDomainLevel($name);
    }

    /** Return domain second level domain for given name
     *
     * @param string $name Zone name
     *
     * @return string 2nd level domain name
     */
    public static function getSecondLevelDomain(string $name): string
    {
        return DomainUtility::getSecondLevelDomain($name);
    }

    public function hasSimilarRecords($domain_id, $name, $type, $record_id): bool
    {
        return $this->recordRepository->hasSimilarRecords($domain_id, $name, $type, $record_id);
    }

    /**
     * Check if a record with the given parameters already exists
     *
     * @param int $domain_id Domain ID
     * @param string $name Record name
     * @param string $type Record type
     * @param string $content Record content
     * @return bool True if record exists, false otherwise
     */
    public function recordExists(int $domain_id, string $name, string $type, string $content): bool
    {
        return $this->recordRepository->recordExists($domain_id, $name, $type, $content);
    }
}
