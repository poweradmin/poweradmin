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

namespace Poweradmin\Domain\Service;

use Poweradmin\Application\Service\AuditService;
use Poweradmin\Domain\Model\ZoneType;
use Poweradmin\Domain\Repository\ZoneRepositoryInterface;
use Poweradmin\Infrastructure\Api\PowerdnsApiClient;

/**
 * Catalog zone membership.
 *
 * PowerDNS stores membership as a `catalog` field on the member zone naming its
 * producer. There is no separate endpoint, so every operation here is a read or a
 * write of that one field.
 */
class CatalogZoneService
{
    /**
     * Kinds PowerDNS will actually publish from a catalog. Its member query is
     * "type in ('MASTER','PRODUCER') and catalog = ?" with an enabled apex SOA, so
     * any other kind accepts a catalog value and is then silently never served.
     */
    public const PUBLISHABLE_KINDS = [ZoneType::MASTER, ZoneType::PRODUCER];

    private DnsBackendProvider $backendProvider;
    private PermissionService $permissionService;

    private ?AuditService $auditService;
    private ?ZoneRepositoryInterface $zoneRepository;

    /** @var array<int, array{id: int, name: string, catalog: string}>|null */
    private ?array $producers = null;

    public function __construct(
        DnsBackendProvider $backendProvider,
        PermissionService $permissionService,
        ?AuditService $auditService = null,
        ?ZoneRepositoryInterface $zoneRepository = null
    ) {
        $this->backendProvider = $backendProvider;
        $this->permissionService = $permissionService;
        $this->auditService = $auditService;
        $this->zoneRepository = $zoneRepository;
    }

    /**
     * The form PowerDNS stores in domains.catalog and matches members against.
     */
    public function normalizeName(string $name): string
    {
        return PowerdnsApiClient::canonicalZoneName($name);
    }

    /**
     * Whether the user may change zone configuration on this zone.
     *
     * Membership is governed by zone_meta_edit_*, the same permission that already
     * covers zone type, primary IP and template.
     */
    public function canManageZone(int $userId, int $zoneId): bool
    {
        $level = $this->permissionService->getZoneMetaEditPermissionLevel($userId);

        if ($level === 'all') {
            return true;
        }

        return $level === 'own' && $this->permissionService->userOwnsZone($userId, $zoneId);
    }

    /**
     * Producer zones available as catalogs.
     *
     * @return array<int, array{id: int, name: string, catalog: string}>
     */
    public function getProducers(): array
    {
        // A single request can ask for producers several times (render, then a
        // lookup on submit); one backend read is enough.
        return $this->producers ??= $this->backendProvider->getZonesByKind(ZoneType::PRODUCER);
    }

    /**
     * The producer whose catalog this zone is in, or null when it is not a member.
     *
     * Resolving to the producer's id here keeps name normalisation out of the
     * templates, which cannot compare a stored catalog to a zone name safely.
     *
     * @return array{id: int, name: string, catalog: string}|null
     */
    public function getCatalogProducer(int $zoneId): ?array
    {
        $catalog = $this->getCatalog($zoneId);

        return $catalog === '' ? null : $this->findProducer(fn(array $p): bool => $this->normalizeName($p['name']) === $catalog);
    }

    /**
     * Zones that could be added to a catalog: publishable, and not already a member.
     *
     * @return array<int, array{id: int, name: string, catalog: string}>
     */
    public function getEligibleMembers(): array
    {
        $eligible = [];
        foreach (self::PUBLISHABLE_KINDS as $kind) {
            foreach ($this->backendProvider->getZonesByKind($kind) as $zone) {
                if ($zone['catalog'] === '') {
                    $eligible[] = $zone;
                }
            }
        }

        usort($eligible, fn(array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $eligible;
    }

    /**
     * Members of a producer, each flagged with whether PowerDNS will publish it.
     *
     * @return array<int, array{id: int, name: string, kind: string, is_published: bool}>
     */
    public function getMembers(string $producerName): array
    {
        $catalog = $this->normalizeName($producerName);

        // Guarded here rather than in each provider, which would otherwise be free
        // to disagree about what an empty catalog name selects.
        if ($catalog === '') {
            return [];
        }

        $members = [];
        foreach ($this->backendProvider->getCatalogMembers($catalog) as $member) {
            $member['is_published'] = in_array($member['kind'], self::PUBLISHABLE_KINDS, true);
            $members[] = $member;
        }

        return $members;
    }

    public function getCatalog(int $zoneId): string
    {
        return $this->backendProvider->getZoneCatalog($zoneId);
    }

    /**
     * Put $memberZoneId into the catalog published by $producerZoneId.
     *
     * Both zones are checked: the member's own row changes, and publishing through
     * the producer changes what that zone serves.
     */
    public function assign(int $userId, int $memberZoneId, int $producerZoneId): bool
    {
        $producer = $this->findProducer(fn(array $p): bool => $p['id'] === $producerZoneId);
        if ($producer === null) {
            return false;
        }

        if (!$this->canManageZone($userId, $memberZoneId) || !$this->canManageZone($userId, $producerZoneId)) {
            return false;
        }

        if (!$this->backendProvider->updateZoneCatalog($memberZoneId, $producer['name'])) {
            return false;
        }

        $this->auditService?->logZoneCatalogAssign($memberZoneId, $this->zoneName($memberZoneId), $producer['name']);

        return true;
    }

    /**
     * Remove $memberZoneId from whichever catalog it is currently in.
     *
     * The producer is resolved from the member's stored value rather than from the
     * caller, so a request cannot name a catalog the caller happens to have rights on.
     */
    public function clear(int $userId, int $memberZoneId): bool
    {
        if (!$this->canManageZone($userId, $memberZoneId)) {
            return false;
        }

        $current = $this->backendProvider->getZoneCatalog($memberZoneId);
        if ($current === '') {
            return true;
        }

        $producer = $this->findProducer(fn(array $p): bool => $this->normalizeName($p['name']) === $current);

        // A catalog this install does not own the producer for can only be cleared
        // by someone who may edit any zone.
        if ($producer === null) {
            if ($this->permissionService->getZoneMetaEditPermissionLevel($userId) !== 'all') {
                return false;
            }
        } elseif (!$this->canManageZone($userId, $producer['id'])) {
            return false;
        }

        if (!$this->backendProvider->updateZoneCatalog($memberZoneId, '')) {
            return false;
        }

        $this->auditService?->logZoneCatalogClear($memberZoneId, $this->zoneName($memberZoneId), $current);

        return true;
    }

    /**
     * Names are only needed for the audit trail, so a miss is not worth failing on.
     */
    private function zoneName(int $zoneId): string
    {
        return (string)$this->zoneRepository?->getDomainNameById($zoneId);
    }

    /**
     * @param callable(array{id: int, name: string, catalog: string}): bool $match
     * @return array{id: int, name: string, catalog: string}|null
     */
    private function findProducer(callable $match): ?array
    {
        foreach ($this->getProducers() as $producer) {
            if ($match($producer)) {
                return $producer;
            }
        }

        return null;
    }
}
