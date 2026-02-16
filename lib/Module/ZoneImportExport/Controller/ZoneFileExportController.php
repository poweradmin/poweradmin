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

namespace Poweradmin\Module\ZoneImportExport\Controller;

use Poweradmin\BaseController;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Infrastructure\Repository\DbUserRepository;
use Poweradmin\Infrastructure\Repository\DbZoneRepository;
use Poweradmin\Module\ZoneImportExport\Service\BindZoneFileGenerator;

class ZoneFileExportController extends BaseController
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

        // Try PowerDNS API first, fall back to DB-based generation
        $content = $this->tryApiExport($zone_name);

        if ($content === null) {
            $content = $this->generateFromDb($zone_id, $zone_name);
        }

        if ($content === null) {
            $this->showError(_('This zone does not have any records to export.'));
            return;
        }

        $sanitized_filename = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $zone_name);

        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $sanitized_filename . '.zone');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');

        echo $content;
        exit();
    }

    private function tryApiExport(string $zone_name): ?string
    {
        $apiUrl = $this->getConfig()->get('pdns_api', 'url', '');
        $apiKey = $this->getConfig()->get('pdns_api', 'key', '');

        if (empty($apiUrl) || empty($apiKey)) {
            return null;
        }

        try {
            $serverName = $this->getConfig()->get('pdns_api', 'server_name', 'localhost');
            $url = rtrim($apiUrl, '/') . "/api/v1/servers/$serverName/zones/$zone_name./export";

            $context = stream_context_create([
                'http' => [
                    'header' => "X-API-Key: $apiKey\r\n",
                    'method' => 'GET',
                    'ignore_errors' => true,
                    'timeout' => 10,
                ]
            ]);

            $response = @file_get_contents($url, false, $context);

            if ($response === false) {
                return null;
            }

            $responseCode = 0;
            if (isset($http_response_header)) {
                foreach ($http_response_header as $header) {
                    if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches)) {
                        $responseCode = (int)$matches[1];
                    }
                }
            }

            if ($responseCode === 200 && !empty(trim($response))) {
                return $response;
            }
        } catch (\Exception $e) {
            error_log('PowerDNS API export failed: ' . $e->getMessage());
        }

        return null;
    }

    private function generateFromDb(int $zone_id, string $zone_name): ?string
    {
        $dnsRecord = new DnsRecord($this->db, $this->getConfig());
        $records = $dnsRecord->getRecordsFromDomainId(
            $this->getConfig()->get('database', 'type', 'mysql'),
            $zone_id
        );

        if (empty($records)) {
            return null;
        }

        $generator = new BindZoneFileGenerator();
        return $generator->generate($zone_name, $records);
    }
}
