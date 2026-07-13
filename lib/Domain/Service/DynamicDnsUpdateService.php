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

use Poweradmin\Domain\Model\RecordType;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Domain\Model\User;
use Poweradmin\Domain\Model\ZoneType;
use Poweradmin\Domain\Repository\DynamicDnsRepositoryInterface;
use Poweradmin\Domain\ValueObject\DynamicDnsRequest;
use Poweradmin\Domain\ValueObject\HostnameValue;
use Poweradmin\Domain\ValueObject\IpAddressList;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Poweradmin\Infrastructure\Utility\IpAddressRetriever;

class DynamicDnsUpdateService
{
    public function __construct(
        private readonly DynamicDnsValidationService $validationService,
        private readonly DynamicDnsAuthenticationService $authService,
        private readonly DynamicDnsRepositoryInterface $repository,
        private readonly ?LegacyLogger $auditLogger = null,
        private readonly ?IpAddressRetriever $ipRetriever = null
    ) {
    }

    public function processUpdate(DynamicDnsRequest $request): string
    {
        $validationResult = $this->validationService->validateRequest($request);
        if (!$validationResult->isValid()) {
            return $this->determineErrorCode($validationResult->getErrors());
        }

        $clientIp = $this->ipRetriever?->getClientIp() ?? '';
        $user = $this->authService->authenticateUser($request, $clientIp);
        if (!$user) {
            return 'badauth';
        }

        try {
            // Lowercase up front so record lookups/inserts are case-consistent with
            // PowerDNS's lowercase storage; a mixed-case client otherwise creates a
            // duplicate record on case-sensitive backends (pgsql/API).
            $hostname = $this->validationService->createValidatedHostname(strtolower($request->getHostname()));
            $ipList = $this->validationService->createValidatedIpList($request->getIpv4(), $request->getIpv6());
        } catch (\Exception $e) {
            return 'dnserr';
        }

        $result = $this->applyForUser($user, $request->getUsername(), $hostname, $ipList, $request->isDualstackUpdate());

        if ($result['status'] !== 'good') {
            // The dyndns2 text protocol has no read-only status; report it as not the client's.
            return $result['status'] === 'readonly' ? '!yours' : $result['status'];
        }

        // dyndns2 clients expect "nochg <ip>" when the address already matched and
        // "good <ip>" when a record was written, so they can confirm the accepted address.
        $prefix = $result['changed'] ? 'good' : 'nochg';
        $primaryIp = $result['applied_ipv4'][0] ?? $result['applied_ipv6'][0] ?? '';
        return $primaryIp === '' ? $prefix : $prefix . ' ' . $primaryIp;
    }

    /**
     * Apply an update for a user that was already authenticated by the caller (e.g. via API key).
     * The shared zone-matching and audit-logging logic lives here so any front end can reuse it.
     *
     * @param int[]|null $allowedZoneIds When provided, the resolved zone must be in this
     *                                   list (used to honor an API key's zone scope); null
     *                                   means no additional restriction.
     * @return array{status: string, zone_id: ?int, applied_ipv4: list<string>, applied_ipv6: list<string>, changed: bool}
     */
    public function applyForUser(
        User $user,
        string $username,
        HostnameValue $hostname,
        IpAddressList $ipList,
        bool $dualstackUpdate,
        ?array $allowedZoneIds = null
    ): array {
        $zoneId = $this->findOwningZoneId($user, $hostname);
        if ($zoneId === null) {
            return $this->emptyResult('nohost', null);
        }

        if ($allowedZoneIds !== null && !in_array($zoneId, $allowedZoneIds, true)) {
            return $this->emptyResult('forbidden', $zoneId);
        }

        // Secondary and Consumer zones replicate from a primary - records are read-only
        if (ZoneType::isReadOnly($this->repository->getZoneType($zoneId))) {
            return $this->emptyResult('readonly', $zoneId);
        }

        try {
            $updateResult = $this->updateZone($zoneId, $hostname, $ipList, $dualstackUpdate);
        } catch (\Exception $e) {
            return $this->emptyResult('dnserr', $zoneId);
        }

        if (!$updateResult['wasUpdated'] && !$updateResult['hasValidRecords']) {
            return $this->emptyResult('!yours', $zoneId);
        }

        $appliedV4 = $ipList->getSortedIpv4Addresses();
        $appliedV6 = $ipList->getSortedIpv6Addresses();

        if ($updateResult['wasUpdated']) {
            $primaryIp = $appliedV4[0] ?? $appliedV6[0] ?? '';
            $this->logAuditEntry($username, $hostname, $zoneId, $primaryIp);
        }

        return [
            'status' => 'good',
            'zone_id' => $zoneId,
            'applied_ipv4' => $appliedV4,
            'applied_ipv6' => $appliedV6,
            'changed' => $updateResult['wasUpdated'],
        ];
    }

    /**
     * @return array{status: string, zone_id: ?int, applied_ipv4: list<string>, applied_ipv6: list<string>, changed: bool}
     */
    private function emptyResult(string $status, ?int $zoneId): array
    {
        return [
            'status' => $status,
            'zone_id' => $zoneId,
            'applied_ipv4' => [],
            'applied_ipv6' => [],
            'changed' => false,
        ];
    }

    private function logAuditEntry(string $username, HostnameValue $hostname, int $zoneId, string $primaryIp): void
    {
        if ($this->auditLogger === null) {
            return;
        }
        $clientIp = $this->ipRetriever?->getClientIp() ?? '';
        $this->auditLogger->logNotice(sprintf(
            'client_ip:%s user:%s operation:dynamic_dns_update hostname:%s zone_id:%d ip:%s',
            $clientIp,
            $username,
            $hostname->getValue(),
            $zoneId,
            $primaryIp
        ));
    }

    /**
     * Pick the most-specific zone the user owns that contains the supplied hostname.
     * Avoids writing the same record into every owned zone, which would create
     * authoritative duplicates across unrelated zones.
     */
    private function findOwningZoneId(User $user, HostnameValue $hostname): ?int
    {
        $userZones = $this->authService->getUserZones($user);
        $fqdn = strtolower($hostname->getValue());

        $bestZoneId = null;
        $bestLength = -1;
        foreach ($userZones as $zoneId => $zoneName) {
            $zone = strtolower($zoneName);
            $isMatch = DnsHelper::isWithinZone($fqdn, $zone);
            if ($isMatch && strlen($zone) > $bestLength) {
                $bestZoneId = $zoneId;
                $bestLength = strlen($zone);
            }
        }

        return $bestZoneId;
    }

    private function updateZone(int $zoneId, HostnameValue $hostname, IpAddressList $ipList, bool $dualstackUpdate): array
    {
        $wasUpdated = false;
        $hasValidRecords = false;

        // Dualstack updates always process both record types so the opposite family is
        // cleared when switching from dual-stack to single-stack.
        if ($dualstackUpdate || $ipList->hasIpv4Addresses()) {
            $syncResult = $this->syncDnsRecords($zoneId, $hostname, RecordType::A, $ipList->getSortedIpv4Addresses());
            $wasUpdated = $wasUpdated || $syncResult['wasUpdated'];
            $hasValidRecords = $hasValidRecords || $syncResult['hasExistingRecords'] || $syncResult['finalIpCount'] > 0;
        }

        if ($dualstackUpdate || $ipList->hasIpv6Addresses()) {
            $syncResult = $this->syncDnsRecords($zoneId, $hostname, RecordType::AAAA, $ipList->getSortedIpv6Addresses());
            $wasUpdated = $wasUpdated || $syncResult['wasUpdated'];
            $hasValidRecords = $hasValidRecords || $syncResult['hasExistingRecords'] || $syncResult['finalIpCount'] > 0;
        }

        if ($wasUpdated) {
            $this->repository->updateSOASerial($zoneId);
        }

        return [
            'wasUpdated' => $wasUpdated,
            'hasValidRecords' => $hasValidRecords,
        ];
    }

    private function syncDnsRecords(int $zoneId, HostnameValue $hostname, string $recordType, array $newIps): array
    {
        $existing = $this->repository->getDnsRecords($zoneId, $hostname, $recordType);
        $hasExistingRecords = !empty($existing);
        $wasUpdated = false;

        foreach ($newIps as $ip) {
            if (isset($existing[$ip])) {
                unset($existing[$ip]);
                continue;
            }
            $this->repository->insertDnsRecord($zoneId, $hostname, $recordType, $ip);
            $wasUpdated = true;
        }

        foreach ($existing as $recordId) {
            $this->repository->deleteDnsRecord($recordId);
            $wasUpdated = true;
        }

        return [
            'wasUpdated' => $wasUpdated,
            'hasExistingRecords' => $hasExistingRecords,
            'finalIpCount' => count($newIps),
        ];
    }

    private function determineErrorCode(array $errors): string
    {
        foreach ($errors as $error) {
            if (str_contains($error, 'User agent')) {
                return 'badagent';
            }
            if (str_contains($error, 'Username')) {
                return 'badauth';
            }
            if (str_contains($error, 'hostname') || str_contains($error, 'Hostname')) {
                return 'notfqdn';
            }
            if (str_contains($error, 'IP address')) {
                return 'dnserr';
            }
        }

        return 'dnserr';
    }
}
