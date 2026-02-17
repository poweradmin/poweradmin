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
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Service\DnsIdnService;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Domain\Service\PermissionService;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Application\Service\RecordCommentService;
use Poweradmin\Application\Service\RecordCommentSyncService;
use Poweradmin\Application\Service\RecordManagerService;
use Poweradmin\Domain\Repository\RecordRepository;
use Poweradmin\Infrastructure\Logger\LegacyLogger;
use Poweradmin\Infrastructure\Repository\DbRecordCommentRepository;
use Poweradmin\Infrastructure\Repository\DbUserRepository;
use Poweradmin\Infrastructure\Repository\DbZoneRepository;
use Poweradmin\Module\ZoneImportExport\Service\BindZoneFileParser;

class ZoneFileImportController extends BaseController
{
    private UserContextService $userContextService;

    public function __construct(array $request)
    {
        parent::__construct($request);
        $this->userContextService = new UserContextService();
    }

    public function run(): void
    {
        $this->checkImportPermission();

        if ($this->isPost()) {
            $this->handleUpload();
        } else {
            $this->showForm();
        }
    }

    public function execute(): void
    {
        $this->checkImportPermission();
        $this->validateCsrfToken();
        $this->handleExecute();
    }

    private function checkImportPermission(): void
    {
        $canAdd = UserManager::verifyPermission($this->db, 'zone_master_add');
        $perm_edit = Permission::getEditPermission($this->db);
        $this->checkCondition(
            !$canAdd && $perm_edit === 'none',
            _('You do not have permission to import zones.')
        );
    }

    private function showForm(array $extra = []): void
    {
        $maxFileSize = $this->getConfig()->get('modules', 'zone_import_export.max_file_size', 1048576);

        $targetZoneId = 0;
        $targetZoneName = '';

        if (isset($_GET['zone_id']) && (int)$_GET['zone_id'] > 0) {
            $zoneRepository = new DbZoneRepository($this->db, $this->getConfig());
            $zoneName = $zoneRepository->getDomainNameById((int)$_GET['zone_id']);
            if ($zoneName) {
                $userId = $this->userContextService->getLoggedInUserId();
                $userRepository = new DbUserRepository($this->db, $this->getConfig());
                $permissionService = new PermissionService($userRepository);
                $permEdit = $permissionService->getEditPermissionLevelForZone($this->db, $userId, (int)$_GET['zone_id']);
                if ($permEdit !== 'none') {
                    $targetZoneId = (int)$_GET['zone_id'];
                    $targetZoneName = $zoneName;
                }
            }
        }

        $vars = array_merge([
            'max_file_size' => $maxFileSize,
            'max_file_size_human' => $this->formatBytes($maxFileSize),
            'csrf_token' => $this->getCsrfToken(),
            'target_zone_id' => $targetZoneId,
            'target_zone_name' => $targetZoneName,
        ], $extra);

        $this->render('@zone_import_export/import.html', $vars);
    }

    private function handleUpload(): void
    {
        $this->validateCsrfToken();

        if (!isset($_FILES['zone_file']) || $_FILES['zone_file']['error'] !== UPLOAD_ERR_OK) {
            $this->showError(_('Please select a valid zone file to upload.'));
            return;
        }

        $maxFileSize = $this->getConfig()->get('modules', 'zone_import_export.max_file_size', 1048576);
        if ($_FILES['zone_file']['size'] > $maxFileSize) {
            $this->showError(sprintf(_('File size exceeds the maximum allowed size of %s.'), $this->formatBytes($maxFileSize)));
            return;
        }

        $content = file_get_contents($_FILES['zone_file']['tmp_name']);
        if ($content === false || trim($content) === '') {
            $this->showError(_('The uploaded file is empty or could not be read.'));
            return;
        }

        $autoTtlValue = $this->getConfig()->get('modules', 'zone_import_export.auto_ttl_value', 300);
        $parser = new BindZoneFileParser($autoTtlValue);
        $parsed = $parser->parse($content);

        if ($parsed->getRecordCount() === 0) {
            $this->showError(_('No valid records found in the uploaded file.'));
            return;
        }

        // Convert IDN names
        $records = [];
        foreach ($parsed->getRecords() as $record) {
            if (DnsIdnService::isIdn($record->name)) {
                $record->name = DnsIdnService::toPunycode($record->name);
            }
            $records[] = $record;
        }

        $origin = $parsed->getOrigin();
        if ($origin !== null && DnsIdnService::isIdn($origin)) {
            $origin = DnsIdnService::toPunycode($origin);
        }

        // Filter out SOA records (Poweradmin creates its own)
        $filteredRecords = array_values(array_filter($records, fn($r) => $r->type !== 'SOA'));

        $importMode = $_POST['import_mode'] ?? 'new';
        $existingZoneId = isset($_POST['existing_zone_id']) ? (int)$_POST['existing_zone_id'] : 0;

        $userId = $this->userContextService->getLoggedInUserId();
        $userRepository = new DbUserRepository($this->db, $this->getConfig());
        $permissionService = new PermissionService($userRepository);

        // Verify permission when importing into an existing zone via POST
        if ($importMode === 'existing' && $existingZoneId > 0) {
            $permEdit = $permissionService->getEditPermissionLevelForZone($this->db, $userId, $existingZoneId);
            if ($permEdit === 'none') {
                $this->showError(_('You do not have permission to modify this zone.'));
                return;
            }
        }

        // Auto-detect existing zone when importing from the menu
        $dnsRecord = new DnsRecord($this->db, $this->getConfig());
        if ($importMode === 'new' && $origin !== null && $dnsRecord->domainExists($origin)) {
            $existingZoneId = $dnsRecord->getZoneIdFromName($origin) ?? 0;
            if ($existingZoneId > 0) {
                $permEdit = $permissionService->getEditPermissionLevelForZone($this->db, $userId, $existingZoneId);
                if ($permEdit !== 'none') {
                    $importMode = 'existing';
                }
            }
        }

        // Store parsed data in session for the execute step
        $_SESSION['zone_import_data'] = [
            'origin' => $origin,
            'records' => serialize($filteredRecords),
            'warnings' => $parsed->getWarnings(),
            'filename' => $_FILES['zone_file']['name'],
        ];

        // Build preview data
        $previewRecords = [];
        foreach ($filteredRecords as $record) {
            $previewRecords[] = [
                'name' => $record->name,
                'type' => $record->type,
                'content' => $record->content,
                'ttl' => $record->ttl,
                'priority' => $record->priority,
            ];
        }

        $previewVars = [
            'preview' => true,
            'records' => $previewRecords,
            'record_count' => count($filteredRecords),
            'warnings' => $parsed->getWarnings(),
            'origin' => $origin,
            'filename' => $_FILES['zone_file']['name'],
            'import_mode' => $importMode,
            'existing_zone_id' => $existingZoneId,
        ];

        if ($importMode === 'existing' && $existingZoneId > 0) {
            $zoneRepository = new DbZoneRepository($this->db, $this->getConfig());
            $existingZoneName = $zoneRepository->getDomainNameById($existingZoneId);
            $previewVars['existing_zone_name'] = $existingZoneName ?: '';
        }

        $this->showForm($previewVars);
    }

    private function handleExecute(): void
    {
        if (!isset($_SESSION['zone_import_data'])) {
            $this->showError(_('Import session expired. Please upload the file again.'));
            return;
        }

        $importData = $_SESSION['zone_import_data'];
        $records = unserialize($importData['records']);
        $origin = $importData['origin'];

        $importMode = $_POST['import_mode'] ?? 'new';
        $existingZoneId = isset($_POST['existing_zone_id']) ? (int)$_POST['existing_zone_id'] : 0;
        $conflictStrategy = $_POST['conflict_strategy'] ?? 'skip';
        $zoneName = $_POST['zone_name'] ?? $origin;

        // Handle IDN zone name
        if ($zoneName && DnsIdnService::isIdn($zoneName)) {
            $zoneName = DnsIdnService::toPunycode($zoneName);
        }

        $userId = $this->userContextService->getLoggedInUserId();
        $userLogin = $this->userContextService->getLoggedInUsername();
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';

        $dnsRecord = new DnsRecord($this->db, $this->getConfig());

        if ($importMode === 'existing' && $existingZoneId > 0) {
            // Verify the zone exists
            $zoneRepository = new DbZoneRepository($this->db, $this->getConfig());
            $existingZoneName = $zoneRepository->getDomainNameById($existingZoneId);
            if (!$existingZoneName) {
                $this->showError(_('The selected zone does not exist.'));
                return;
            }

            // Verify user has permission to edit this zone
            $userRepository = new DbUserRepository($this->db, $this->getConfig());
            $permissionService = new PermissionService($userRepository);
            $permEdit = $permissionService->getEditPermissionLevelForZone($this->db, $userId, $existingZoneId);

            if ($permEdit === 'none') {
                $this->showError(_('You do not have permission to modify this zone.'));
                return;
            }

            $zone_id = $existingZoneId;
            $zoneName = $existingZoneName;

            $logger = new LegacyLogger($this->db);
            $logger->logInfo(sprintf(
                'client_ip:%s user:%s operation:zone_import_records zone_name:%s',
                $clientIp,
                $userLogin,
                $zoneName
            ), $zone_id);
        } else {
            if (!UserManager::verifyPermission($this->db, 'zone_master_add')) {
                $this->showError(_('You do not have permission to add zones.'));
                return;
            }

            if (empty($zoneName)) {
                $this->showError(_('Zone name is required.'));
                return;
            }

            if ($dnsRecord->domainExists($zoneName)) {
                $this->showError(_('A zone with this name already exists.'));
                return;
            }

            $zoneType = $_POST['zone_type'] ?? 'MASTER';
            if (!$dnsRecord->addDomain($this->db, $zoneName, $userId, $zoneType, '', 'none')) {
                $this->showError(_('Failed to create zone.'));
                return;
            }

            $zone_id = $dnsRecord->getZoneIdFromName($zoneName);
            if (!$zone_id) {
                $this->showError(_('Failed to retrieve created zone.'));
                return;
            }

            $logger = new LegacyLogger($this->db);
            $logger->logInfo(sprintf(
                'client_ip:%s user:%s operation:zone_import zone_name:%s',
                $clientIp,
                $userLogin,
                $zoneName
            ), $zone_id);

            // New zone: no conflicts possible
            $conflictStrategy = 'add_all';
        }

        // Import records
        $recordCommentRepository = new DbRecordCommentRepository($this->db, $this->getConfig());
        $recordCommentService = new RecordCommentService($recordCommentRepository);
        $recordRepository = new RecordRepository($this->db, $this->getConfig());
        $commentSyncService = new RecordCommentSyncService($recordCommentService, $recordRepository);
        $logger = new LegacyLogger($this->db);
        $recordManager = new RecordManagerService(
            $this->db,
            $dnsRecord,
            $recordCommentService,
            $commentSyncService,
            $logger,
            $this->getConfig()
        );

        $successCount = 0;
        $failCount = 0;
        $skipCount = 0;
        $replacedRRSets = [];

        foreach ($records as $record) {
            if ($importMode === 'existing' && $conflictStrategy !== 'add_all') {
                $exists = $recordRepository->recordExists($zone_id, $record->name, $record->type, $record->content);

                if ($exists && $conflictStrategy === 'skip') {
                    $skipCount++;
                    continue;
                }

                if ($conflictStrategy === 'replace') {
                    $rrsetKey = $record->name . '|' . $record->type;
                    if (!isset($replacedRRSets[$rrsetKey])) {
                        $rrsetRecords = $recordRepository->getRRSetRecords($zone_id, $record->name, $record->type);
                        foreach ($rrsetRecords as $existing) {
                            $dnsRecord->deleteRecord((int)$existing['id']);
                        }
                        $replacedRRSets[$rrsetKey] = true;
                    }
                }
            }

            $result = $recordManager->createRecord(
                $zone_id,
                $record->name,
                $record->type,
                $record->content,
                $record->ttl,
                $record->priority,
                '',
                $userLogin,
                $clientIp
            );

            if ($result) {
                $successCount++;
            } else {
                $failCount++;
            }
        }

        // Clean up session data
        unset($_SESSION['zone_import_data']);

        $this->showForm([
            'result' => true,
            'success_count' => $successCount,
            'fail_count' => $failCount,
            'skip_count' => $skipCount,
            'zone_id' => $zone_id,
            'zone_name' => $zoneName ?: $origin,
        ]);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        return round($bytes / 1024, 1) . ' KB';
    }

    private function getCsrfToken(): string
    {
        return $_SESSION['csrf_token'] ?? '';
    }
}
