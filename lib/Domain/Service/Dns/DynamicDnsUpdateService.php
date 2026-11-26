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

use Closure;
use Poweradmin\Domain\Model\RecordType;
use Poweradmin\Domain\Port\AuditLoggerInterface;
use Poweradmin\Domain\Port\RecordChangeWriterInterface;
use Poweradmin\Domain\Port\ZoneCacheFlusherInterface;
use Poweradmin\Domain\Service\Auth\DynamicDnsAuthenticationService;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Domain\Model\User;
use Poweradmin\Domain\Model\ZoneType;
use Poweradmin\Domain\Repository\DynamicDnsRepositoryInterface;
use Poweradmin\Domain\ValueObject\DynamicDnsRequest;
use Poweradmin\Domain\ValueObject\HostnameValue;
use Poweradmin\Domain\ValueObject\IpAddressList;

/**
 * Runs a DDNS update: validates and authenticates it, picks the owning zone and syncs the A/AAAA records.
 */
readonly class DynamicDnsUpdateService
{
    /** The TTL the repositories give every DDNS record. */
    private const DDNS_TTL = 60;

    /**
     * @param string $clientIp The caller's address, recorded with failed login attempts
     * @param Closure|null $requiresApproval fn(int $userId, int $zoneId): bool, true when the user's changes
     *        to the zone go through change requests; omitted, updates are never refused for review
     * @param Closure|null $changeLoggerFor fn(int $userId, string $username): RecordChangeWriterInterface, the
     *        record change log writer attributed to the DDNS client; omitted, record changes are not logged
     */
    public function __construct(
        private DynamicDnsValidationService $validationService,
        private DynamicDnsAuthenticationService $authService,
        private DynamicDnsRepositoryInterface $repository,
        private ?AuditLoggerInterface $auditService = null,
        private string $clientIp = '',
        private ?Closure $requiresApproval = null,
        private ?ZoneCacheFlusherInterface $zoneCacheFlusher = null,
        private ?PostWriteZoneRectifier $zoneRectifier = null,
        private ?Closure $changeLoggerFor = null
    ) {
    }

    public function processUpdate(DynamicDnsRequest $request): string
    {
        $validationResult = $this->validationService->validateRequest($request);
        if (!$validationResult->isValid()) {
            return $this->determineErrorCode($validationResult->getErrors());
        }

        $user = $this->authService->authenticateUser($request, $this->clientIp);
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
            // The dyndns2 text protocol has no read-only, review or CNAME status; report those as not the client's.
            return in_array($result['status'], ['readonly', 'approval', 'cname'], true) ? '!yours' : $result['status'];
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
     * @param bool $mayRemoveRecords False refuses (status 'nodelete') a sync that would leave
     *                               a record type with fewer records than it has now.
     * @return array{status: string, zone_id: ?int, applied_ipv4: list<string>, applied_ipv6: list<string>, changed: bool}
     */
    public function applyForUser(
        User $user,
        string $username,
        HostnameValue $hostname,
        IpAddressList $ipList,
        bool $dualstackUpdate,
        ?array $allowedZoneIds = null,
        bool $mayRemoveRecords = true
    ): array {
        $owningZone = $this->findOwningZone($user, $hostname);
        if ($owningZone === null) {
            return $this->emptyResult('nohost', null);
        }
        [$zoneId, $zoneName] = $owningZone;

        if ($allowedZoneIds !== null && !in_array($zoneId, $allowedZoneIds, true)) {
            return $this->emptyResult('forbidden', $zoneId);
        }

        // Secondary and Consumer zones replicate from a primary - records are read-only
        if (ZoneType::isReadOnly($this->repository->getZoneType($zoneId))) {
            return $this->emptyResult('readonly', $zoneId);
        }

        // A DDNS client cannot file a change request, so a reviewed zone refuses the update
        if ($this->requiresApproval !== null && ($this->requiresApproval)($user->getId(), $zoneId)) {
            return $this->emptyResult('approval', $zoneId);
        }

        try {
            if ($this->repository->hasCnameRecord($zoneId, $hostname)) {
                return $this->emptyResult('cname', $zoneId);
            }

            $changeLogger = $this->changeLoggerFor === null ? null : ($this->changeLoggerFor)($user->getId(), $username);
            $updateResult = $this->updateZone($zoneId, $zoneName, $hostname, $ipList, $dualstackUpdate, $mayRemoveRecords, $changeLogger);
        } catch (\Exception $e) {
            return $this->emptyResult('dnserr', $zoneId);
        }

        if ($updateResult['removalRefused']) {
            return $this->emptyResult('nodelete', $zoneId);
        }

        if (!$updateResult['wasUpdated'] && !$updateResult['hasValidRecords']) {
            return $this->emptyResult('!yours', $zoneId);
        }

        $appliedV4 = $ipList->getSortedIpv4Addresses();
        $appliedV6 = $ipList->getSortedIpv6Addresses();

        if ($updateResult['wasUpdated']) {
            $primaryIp = $appliedV4[0] ?? $appliedV6[0] ?? '';
            $this->auditService?->logDynamicDnsUpdate($username, $hostname->getValue(), $zoneId, $primaryIp);
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

    /**
     * Pick the most-specific zone the user owns that contains the supplied hostname.
     * Avoids writing the same record into every owned zone, which would create
     * authoritative duplicates across unrelated zones.
     *
     * @return array{0: int, 1: string}|null The zone id and name
     */
    private function findOwningZone(User $user, HostnameValue $hostname): ?array
    {
        $userZones = $this->authService->getUserZones($user);
        $fqdn = strtolower($hostname->getValue());

        $best = null;
        $bestLength = -1;
        foreach ($userZones as $zoneId => $zoneName) {
            $zone = strtolower($zoneName);
            $isMatch = DnsHelper::isWithinZone($fqdn, $zone);
            $apexLength = strlen(DnsHelper::zoneApexName($zone));
            // On an equal apex the base zone wins over its view variant (DDNS has no view context)
            $beatsBest = $apexLength > $bestLength
                || ($apexLength === $bestLength && $best !== null
                    && DnsHelper::isViewVariant($best[1]) && !DnsHelper::isViewVariant($zoneName));
            if ($isMatch && $beatsBest) {
                $best = [$zoneId, $zoneName];
                $bestLength = $apexLength;
            }
        }

        return $best;
    }

    private function updateZone(
        int $zoneId,
        string $zoneName,
        HostnameValue $hostname,
        IpAddressList $ipList,
        bool $dualstackUpdate,
        bool $mayRemoveRecords,
        ?RecordChangeWriterInterface $changeLogger
    ): array {
        // Dualstack updates always process both record types so the opposite family is
        // cleared when switching from dual-stack to single-stack.
        $wanted = [];
        if ($dualstackUpdate || $ipList->hasIpv4Addresses()) {
            $wanted[RecordType::A] = $ipList->getSortedIpv4Addresses();
        }
        if ($dualstackUpdate || $ipList->hasIpv6Addresses()) {
            $wanted[RecordType::AAAA] = $ipList->getSortedIpv6Addresses();
        }

        // Every family is read and checked before any write, so a refused removal
        // leaves the name untouched rather than half-synced.
        $existing = [];
        foreach ($wanted as $recordType => $ips) {
            $existing[$recordType] = $this->repository->getDnsRecords($zoneId, $hostname, $recordType);
            if (!$mayRemoveRecords && count(array_unique($ips)) < count($existing[$recordType])) {
                return ['wasUpdated' => false, 'hasValidRecords' => true, 'removalRefused' => true];
            }
        }

        // One unit, so a failure part-way leaves neither new addresses nor a stale serial behind
        [$wasUpdated, $hasValidRecords, $pendingLogs] = $this->repository->atomically(function () use ($zoneId, $zoneName, $hostname, $wanted, $existing, $changeLogger): array {
            $wasUpdated = false;
            $hasValidRecords = false;
            $pendingLogs = [];
            foreach ($wanted as $recordType => $ips) {
                $syncResult = $this->syncDnsRecords($zoneId, $zoneName, $hostname, $recordType, $ips, $existing[$recordType], $changeLogger);
                $wasUpdated = $wasUpdated || $syncResult['wasUpdated'];
                $hasValidRecords = $hasValidRecords || $syncResult['hasExistingRecords'] || $syncResult['finalIpCount'] > 0;
                array_push($pendingLogs, ...$syncResult['logs']);
            }
            if ($wasUpdated) {
                $this->repository->updateSOASerial($zoneId);
            }

            return [$wasUpdated, $hasValidRecords, $pendingLogs];
        });

        // Log inside the transaction and a failed insert aborts it on PostgreSQL
        foreach ($pendingLogs as $log) {
            $this->logChange($log);
        }

        // After the commit, so the rectify and the cache flush see the rows
        if ($wasUpdated) {
            $this->zoneRectifier?->rectify($zoneName);
            $this->zoneCacheFlusher?->flushZone($zoneName);
        }

        return [
            'wasUpdated' => $wasUpdated,
            'hasValidRecords' => $hasValidRecords,
            'removalRefused' => false,
        ];
    }

    /**
     * @param array<string, int|string> $existing current record ids of this type, keyed by content
     */
    private function syncDnsRecords(int $zoneId, string $zoneName, HostnameValue $hostname, string $recordType, array $newIps, array $existing, ?RecordChangeWriterInterface $changeLogger): array
    {
        $hasExistingRecords = !empty($existing);
        $wasUpdated = false;
        $logs = [];

        foreach ($newIps as $ip) {
            if (isset($existing[$ip])) {
                unset($existing[$ip]);
                continue;
            }
            $recordId = $this->repository->insertDnsRecord($zoneId, $hostname, $recordType, $ip);
            $logs[] = static fn() => $changeLogger?->logRecordCreate(
                self::snapshot($recordId, $zoneName, $hostname, $recordType, $ip, self::DDNS_TTL),
                $zoneId
            );
            $wasUpdated = true;
        }

        foreach ($existing as $content => $recordId) {
            $this->repository->deleteDnsRecord($recordId);
            $logs[] = static fn() => $changeLogger?->logRecordDelete(
                self::snapshot($recordId, $zoneName, $hostname, $recordType, (string)$content, null),
                $zoneId
            );
            $wasUpdated = true;
        }

        return [
            'logs' => $logs,
            'wasUpdated' => $wasUpdated,
            'hasExistingRecords' => $hasExistingRecords,
            'finalIpCount' => count($newIps),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function snapshot(int|string|null $recordId, string $zoneName, HostnameValue $hostname, string $recordType, string $content, ?int $ttl): array
    {
        return [
            'id' => $recordId,
            'name' => $hostname->getValue(),
            'type' => $recordType,
            'content' => $content,
            'ttl' => $ttl,
            'prio' => 0,
            'disabled' => false,
            'zone_name' => $zoneName,
        ];
    }

    /**
     * A failed log line must not undo or fail the update it describes.
     */
    private function logChange(callable $log): void
    {
        try {
            $log();
        } catch (\Throwable) {
        }
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
