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

namespace Poweradmin\Module\CsvExport\Controller;

use Poweradmin\BaseController;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Repository\DbUserRepository;
use Poweradmin\Infrastructure\Repository\DbZoneRepository;

class CsvExportController extends BaseController
{
    public function run(): void
    {
        $userContextService = new UserContextService();
        if (!$userContextService->isAuthenticated()) {
            $this->showError(_('You need to be logged in to export zone data.'));
            return;
        }

        $zone_id = (int)($this->requestData['id'] ?? 0);
        if ($zone_id === 0) {
            $this->showError(_('Invalid zone ID.'));
            return;
        }

        $userId = $userContextService->getLoggedInUserId();
        $userRepository = new DbUserRepository($this->db, $this->getConfig());
        $permissionService = new PermissionService($userRepository);
        $perm_view = $permissionService->getViewPermissionLevel($userId);
        $user_is_zone_owner = UserManager::verifyUserIsOwnerZoneId($this->db, $zone_id);

        if ($perm_view == "none" || ($perm_view == "own" && $user_is_zone_owner == "0")) {
            $this->showError(_('You do not have permission to export this zone.'));
            return;
        }

        $zoneRepository = new DbZoneRepository($this->db, $this->getConfig());
        $zone_name = $zoneRepository->getDomainNameById($zone_id);

        if (!$zone_name) {
            $this->showError(_('There is no zone with this ID.'));
            return;
        }

        $dnsRecord = new DnsRecord($this->db, $this->getConfig());
        $records = $dnsRecord->getRecordsFromDomainId($this->getConfig()->get('database', 'type', 'mysql'), $zone_id);

        if (empty($records)) {
            $this->showError(_('This zone does not have any records to export.'));
            return;
        }

        $sanitized_filename = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $zone_name);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $sanitized_filename . '_records.csv');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Content-Transfer-Encoding: binary');

        $output = fopen('php://output', 'w');

        // Add BOM for UTF-8
        fputs($output, "\xEF\xBB\xBF");

        $header = ['Name', 'Type', 'Content', 'Priority', 'TTL', 'Disabled'];

        if ($this->getConfig()->get('interface', 'show_record_comments', false)) {
            $header[] = 'Comment';
        }

        fputcsv($output, $header, ',', '"', '');

        foreach ($records as $record) {
            $row = [
                $record['name'],
                $record['type'],
                $record['content'],
                $record['prio'],
                $record['ttl'],
                $record['disabled'] ? 'Yes' : 'No'
            ];

            if ($this->getConfig()->get('interface', 'show_record_comments', false)) {
                $row[] = $record['comment'] ?? '';
            }

            fputcsv($output, $row, ',', '"', '');
        }

        fclose($output);
        exit();
    }
}
