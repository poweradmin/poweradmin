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

namespace Poweradmin\Application\Controller\Log;

use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Utility\DnsIdnService;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Infrastructure\Logger\DbZoneLogger;
use Poweradmin\Infrastructure\Utility\CsvFormulaEscaper;

/**
 * Renders the zone log page, limited to owned zones for users without the view-others permission.
 */
class ListLogZonesController extends AbstractListLogController
{
    private ?DbZoneLogger $dbZoneLogger = null;

    /**
     * Owner-only filter applies when the user may see their own zones' logs but
     * not others'. "all" holders (ueberuser or zone_logs_view_others) see everything.
     */
    private bool $applyOwnerFilter = false;

    /** @var int[]|null Scope for the log queries; null means unrestricted. */
    private ?array $ownedZoneIds = null;

    private ?int $requestedZoneId = null;

    private ?string $zoneFilterName = null;

    private bool $isReverseZone = false;

    private function dbZoneLogger(): DbZoneLogger
    {
        return $this->dbZoneLogger ??= $this->services()->zoneLogger();
    }

    protected function authorize(): bool
    {
        $logPermission = $this->services()->permissionService()->getZoneLogPermissionLevel((int)$this->getCurrentUserId());

        if ($logPermission === 'none') {
            // Existing deny path: logs the access denial via AuditService and halts.
            $this->checkPermission(Permission::PERM_USER_IS_UEBERUSER, 'You do not have the permission to see any logs');
            return false;
        }

        $this->applyOwnerFilter = $logPermission === 'own';
        return true;
    }

    protected function getPageName(): string
    {
        return 'list_log_zones';
    }

    protected function getPageTitleText(): string
    {
        return _('Zone logs');
    }

    protected function getTemplateName(): string
    {
        return 'list_log_zones.html';
    }

    protected function getPaginationRoute(): string
    {
        return '/zones/logs?start={PageNumber}';
    }

    protected function getExportFilenamePrefix(): string
    {
        return 'zone-logs';
    }

    protected function getAdditionalFilterParams(): array
    {
        return ['operation', 'user'];
    }

    protected function transformNameFilter(string $name): string
    {
        return DnsIdnService::toPunycode($name);
    }

    protected function prepareListing(array &$filters): void
    {
        $this->ownedZoneIds = $this->applyOwnerFilter ? $this->resolveOwnedZoneIds() : null;

        // Exact zone scope from the per-zone "Logs" button on edit.html.
        // Intersect with the ownership filter so non-admins cannot peek at zones
        // they don't own by guessing IDs.
        $zoneIdParam = $this->httpRequest->getQueryParam('zone_id');
        $this->requestedZoneId = (is_numeric($zoneIdParam) && (int) $zoneIdParam > 0) ? (int) $zoneIdParam : null;
        if ($this->requestedZoneId !== null) {
            if ($this->ownedZoneIds !== null) {
                $this->ownedZoneIds = in_array($this->requestedZoneId, $this->ownedZoneIds, true) ? [$this->requestedZoneId] : [];
            } else {
                $this->ownedZoneIds = [$this->requestedZoneId];
            }
            // DbZoneLogger ignores unknown filter keys; including zone_id here only
            // affects pagination URL generation via paginationVariables().
            $filters['zone_id'] = (string) $this->requestedZoneId;
        }

        // Only resolve the zone name/breadcrumb when the requested zone survived the
        // ownership intersection, so owner-scoped users cannot enumerate other zones' names.
        if ($this->requestedZoneId !== null && in_array($this->requestedZoneId, $this->ownedZoneIds, true)) {
            $domainName = $this->services()->domainRepository()->getDomainNameById($this->requestedZoneId);
            $this->zoneFilterName = $domainName !== null ? DnsIdnService::toUtf8($domainName) : null;
            $this->isReverseZone = $domainName !== null && DnsHelper::isReverseZoneName($domainName);
        }
    }

    protected function countLogs(array $filters): int
    {
        return $this->dbZoneLogger()->countFilteredLogs($filters, $this->ownedZoneIds);
    }

    protected function fetchLogs(array $filters, int $limit, int $offset): array
    {
        return $this->dbZoneLogger()->getFilteredLogs($filters, $limit, $offset, $this->ownedZoneIds);
    }

    protected function getAdditionalRenderParams(): array
    {
        return [
            'operation' => $this->httpRequest->getQueryParam('operation', ''),
            'user_filter' => $this->httpRequest->getQueryParam('user', ''),
            'zone_id_filter' => $this->requestedZoneId,
            'zone_name' => $this->zoneFilterName,
            'is_reverse_zone' => $this->isReverseZone,
            'operations' => $this->dbZoneLogger()->getDistinctOperations(),
            'users' => $this->applyOwnerFilter
                ? $this->dbZoneLogger()->getDistinctUsersForZones($this->ownedZoneIds ?? [])
                : $this->dbZoneLogger()->getDistinctUsers(),
            'is_owner_view' => $this->applyOwnerFilter,
        ];
    }

    /**
     * Ids that match log_zones.zone_id for zones the current non-admin user owns.
     *
     * @return int[]
     */
    private function resolveOwnedZoneIds(): array
    {
        $userId = $this->getCurrentUserId() ?? 0;

        return $userId > 0 ? $this->services()->zoneRepository()->getOwnedZoneIds($userId) : [];
    }

    protected function parseLogEvents(array $logs): array
    {
        $result = [];
        foreach ($logs as $log) {
            $row = [
                'timestamp' => $log['created_at'],
            ];
            $parts = explode(' ', $log['event']);
            foreach ($parts as $part) {
                $kv = explode(':', $part, 2);
                if (count($kv) === 2) {
                    $row[$kv[0]] = $kv[1];
                }
            }
            $result[] = $row;
        }
        return $result;
    }

    protected function writeCsvRows($output, array $parsed): void
    {
        fputcsv($output, CsvFormulaEscaper::escapeRow(array_keys($parsed[0])));
        foreach ($parsed as $row) {
            fputcsv($output, CsvFormulaEscaper::escapeRow($row));
        }
    }
}
