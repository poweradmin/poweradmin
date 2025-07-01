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
use Poweradmin\Domain\Model\User;
use Poweradmin\Domain\Repository\DynamicDnsRepositoryInterface;
use Poweradmin\Domain\ValueObject\DynamicDnsRequest;
use Poweradmin\Domain\ValueObject\HostnameValue;
use Poweradmin\Domain\ValueObject\IpAddressList;

class DynamicDnsUpdateService
{
    private DynamicDnsValidationService $validationService;
    private DynamicDnsAuthenticationService $authService;
    private DynamicDnsRepositoryInterface $repository;

    public function __construct(
        DynamicDnsValidationService $validationService,
        DynamicDnsAuthenticationService $authService,
        DynamicDnsRepositoryInterface $repository
    ) {
        $this->validationService = $validationService;
        $this->authService = $authService;
        $this->repository = $repository;
    }

    public function processUpdate(DynamicDnsRequest $request): string
    {
        $validationResult = $this->validationService->validateRequest($request);
        if (!$validationResult->isValid()) {
            return $this->determineErrorCode($validationResult->getErrors());
        }

        $user = $this->authService->authenticateUser($request);
        if (!$user) {
            return 'badauth2';
        }

        try {
            $hostname = $this->validationService->createValidatedHostname($request->getHostname());
            $ipList = $this->validationService->createValidatedIpList($request->getIpv4(), $request->getIpv6());

            $updateResult = $this->updateUserZones($user, $hostname, $ipList, $request->isDualstackUpdate());

            return $updateResult['wasUpdated'] ? 'good' : '!yours';
        } catch (\Exception $e) {
            return 'dnserr';
        }
    }

    private function updateUserZones(User $user, HostnameValue $hostname, IpAddressList $ipList, bool $dualstackUpdate): array
    {
        $userZones = $this->authService->getUserZones($user);
        $wasUpdated = false;

        foreach ($userZones as $zoneId) {
            $zoneUpdated = false;

            if ($dualstackUpdate || $ipList->hasIpv4Addresses()) {
                if ($this->syncDnsRecords($zoneId, $hostname, RecordType::A, $ipList->getSortedIpv4Addresses())) {
                    $zoneUpdated = true;
                    $wasUpdated = true;
                }
            }

            if ($dualstackUpdate || $ipList->hasIpv6Addresses()) {
                if ($this->syncDnsRecords($zoneId, $hostname, RecordType::AAAA, $ipList->getSortedIpv6Addresses())) {
                    $zoneUpdated = true;
                    $wasUpdated = true;
                }
            }

            if ($zoneUpdated) {
                $this->repository->updateSOASerial($zoneId);
            }
        }

        return ['wasUpdated' => $wasUpdated];
    }

    private function syncDnsRecords(int $zoneId, HostnameValue $hostname, string $recordType, array $newIps): bool
    {
        if (empty($newIps)) {
            return false;
        }

        $existing = $this->repository->getDnsRecords($zoneId, $hostname, $recordType);

        $zoneUpdated = false;

        foreach ($newIps as $ip) {
            if (isset($existing[$ip])) {
                unset($existing[$ip]);
            } else {
                $this->repository->insertDnsRecord($zoneId, $hostname, $recordType, $ip);
                $zoneUpdated = true;
            }
        }

        foreach ($existing as $recordId) {
            $this->repository->deleteDnsRecord($recordId);
            $zoneUpdated = true;
        }

        return $zoneUpdated;
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
