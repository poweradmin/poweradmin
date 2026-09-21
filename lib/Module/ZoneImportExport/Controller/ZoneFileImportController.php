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
use Poweradmin\Domain\Model\ZoneType;
use Poweradmin\Domain\Service\DnsIdnService;
use Poweradmin\Domain\Service\UserContextService;
use Poweradmin\Domain\Service\ZoneOwnershipModeService;
use Poweradmin\Domain\Service\ZoneOwnershipResolution;
use Poweradmin\Application\Service\ZoneCreateFormMessages;
use Poweradmin\Application\Service\ZoneOwnershipFormResolver;
use Poweradmin\Module\ZoneImportExport\Service\BindZoneFileParser;
use Poweradmin\Domain\Service\SessionKeys;
use Poweradmin\Domain\Enum\ZoneKind;
use Poweradmin\Application\Service\ChangeRequestMessages;
use Poweradmin\Domain\Service\ChangeApprovalPolicy;

/**
 * The /tools/zone-import page: uploads a BIND zone file, previews the parsed records and creates the zone.
 */
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
        $canAdd = $this->hasPermission(Permission::PERM_ZONE_MASTER_ADD);
        $perm_edit = $this->services()->permissionService()->getEditPermissionLevel((int)$this->getCurrentUserId());
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
            $zoneName = $this->services()->domainRepository()->getDomainNameById((int)$_GET['zone_id']);
            if ($zoneName) {
                $userId = $this->userContextService->getLoggedInUserId();
                $permissionService = $this->services()->permissionService();
                $permEdit = $permissionService->getEditPermissionLevelForZone($userId, (int)$_GET['zone_id']);
                if ($permEdit !== 'none') {
                    $targetZoneId = (int)$_GET['zone_id'];
                    $targetZoneName = $zoneName;
                }
            }
        }

        $vars = array_merge([
            'max_file_size' => $maxFileSize,
            'max_file_size_human' => $this->formatBytes($maxFileSize),
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

        // Convert IDN names. isIdn() is only true for names that are already
        // punycode, so gating on it skipped exactly the UTF-8 names needing conversion.
        // toPunycode() is a no-op for ASCII and already-encoded input.
        $records = [];
        foreach ($parsed->getRecords() as $record) {
            $record->name = DnsIdnService::toPunycode($record->name);
            $records[] = $record;
        }

        $origin = $parsed->getOrigin();
        if ($origin !== null) {
            $origin = DnsIdnService::toPunycode($origin);
        }

        // Filter out SOA records (Poweradmin creates its own)
        $filteredRecords = array_values(array_filter($records, fn($r) => $r->type !== 'SOA'));

        $importMode = $_POST['import_mode'] ?? 'new';
        $existingZoneId = isset($_POST['existing_zone_id']) ? (int)$_POST['existing_zone_id'] : 0;

        $userId = $this->userContextService->getLoggedInUserId();
        $permissionService = $this->services()->permissionService();

        // Verify permission when importing into an existing zone via POST
        if ($importMode === 'existing' && $existingZoneId > 0) {
            $permEdit = $permissionService->getEditPermissionLevelForZone($userId, $existingZoneId);
            if ($permEdit === 'none') {
                $this->showError(_('You do not have permission to modify this zone.'));
                return;
            }
            if ($this->changeApprovalModeForZone($existingZoneId) === ChangeApprovalPolicy::MODE_REQUEST) {
                $this->showError(ChangeRequestMessages::requiresApproval());
                return;
            }
        }

        // Auto-detect existing zone when importing from the menu
        $domainRepository = $this->services()->domainRepository();
        if ($importMode === 'new' && $origin !== null && $domainRepository->domainExists($origin)) {
            $existingZoneId = $domainRepository->getDomainIdByName($origin) ?? 0;
            if ($existingZoneId > 0) {
                $permEdit = $permissionService->getEditPermissionLevelForZone($userId, $existingZoneId);
                if ($permEdit !== 'none') {
                    $importMode = 'existing';
                }
            }
        }

        // Store parsed data in session for the execute step
        $_SESSION[SessionKeys::ZONE_IMPORT_DATA] = [
            'origin' => $origin,
            'records' => json_encode(array_map(fn($r) => [
                'name' => $r->name,
                'type' => $r->type,
                'content' => $r->content,
                'ttl' => $r->ttl,
                'priority' => $r->priority,
            ], $filteredRecords)),
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
            $existingZoneName = $domainRepository->getDomainNameById($existingZoneId);
            $previewVars['existing_zone_name'] = $existingZoneName ?: '';
        } else {
            // For new-zone imports, expose the ownership-mode flags + group list
            // so the preview step can offer a group picker without inferring all
            // memberships at execute time.
            $ownershipMode = new ZoneOwnershipModeService($this->config);
            $userId = $this->userContextService->getLoggedInUserId();
            $userGroupRepo = $this->services()->userGroupRepository();
            $isAdmin = $this->hasPermission(Permission::PERM_USER_IS_UEBERUSER);
            $availableGroups = $isAdmin ? $userGroupRepo->findAll() : $userGroupRepo->findByUserId($userId);

            // Short-circuit a dead-end preview: in groups_only with no assignable
            // groups, there is no way to confirm the import. Surface a clear
            // error now instead of letting the user reach a form with no
            // ownership controls.
            if (!$ownershipMode->isUserOwnerAllowed() && empty($availableGroups)) {
                if ($isAdmin) {
                    $this->showError(_('Cannot import a new zone: zone_ownership_mode is groups_only but no groups exist. Create a group first.'));
                } else {
                    $this->showError(_('Cannot import a new zone: zone_ownership_mode is groups_only and you are not a member of any group. Ask an administrator to add you to a group first.'));
                }
                return;
            }

            $previewVars['user_owner_allowed'] = $ownershipMode->isUserOwnerAllowed();
            $previewVars['group_owner_allowed'] = $ownershipMode->isGroupOwnerAllowed();
            $previewVars['available_groups'] = $availableGroups;
        }

        $this->showForm($previewVars);
    }

    private function handleExecute(): void
    {
        if (!isset($_SESSION[SessionKeys::ZONE_IMPORT_DATA])) {
            $this->showError(_('Import session expired. Please upload the file again.'));
            return;
        }

        $importData = $_SESSION[SessionKeys::ZONE_IMPORT_DATA];
        $records = json_decode($importData['records']);
        if (!is_array($records)) {
            $this->showError(_('Invalid import data. Please upload the file again.'));
            return;
        }
        $origin = $importData['origin'];

        $importMode = $_POST['import_mode'] ?? 'new';
        $existingZoneId = isset($_POST['existing_zone_id']) ? (int)$_POST['existing_zone_id'] : 0;
        $conflictStrategy = $_POST['conflict_strategy'] ?? 'skip';
        $zoneName = $_POST['zone_name'] ?? $origin;

        // Handle IDN zone name
        if ($zoneName) {
            $zoneName = DnsIdnService::toPunycode($zoneName);
        }

        $userId = $this->userContextService->getLoggedInUserId();
        $userLogin = $this->userContextService->getLoggedInUsername();
        $audit = $this->services()->auditService();

        $domainRepository = $this->services()->domainRepository();

        if ($importMode === 'existing' && $existingZoneId > 0) {
            // Verify the zone exists
            $existingZoneName = $domainRepository->getDomainNameById($existingZoneId);
            if (!$existingZoneName) {
                $this->showError(_('The selected zone does not exist.'));
                return;
            }
            if ($this->changeApprovalModeForZone($existingZoneId) === ChangeApprovalPolicy::MODE_REQUEST) {
                $this->showError(ChangeRequestMessages::requiresApproval());
                return;
            }

            // Verify user has permission to edit this zone
            $permissionService = $this->services()->permissionService();
            $permEdit = $permissionService->getEditPermissionLevelForZone($userId, $existingZoneId);

            if ($permEdit === 'none') {
                $this->showError(_('You do not have permission to modify this zone.'));
                return;
            }

            // Secondary and Consumer zones replicate from a primary - records cannot be imported
            if (ZoneType::isReadOnly($domainRepository->getDomainType($existingZoneId))) {
                $this->showError(_('You cannot import records into a read-only zone.'));
                return;
            }

            $zone_id = $existingZoneId;
            $zoneName = $existingZoneName;

            $audit->logZoneImport($zone_id, (string)$zoneName, true);
        } else {
            if (!$this->hasPermission(Permission::PERM_ZONE_MASTER_ADD)) {
                $this->showError(_('You do not have permission to add zones.'));
                return;
            }

            if (empty($zoneName)) {
                $this->showError(_('Zone name is required.'));
                return;
            }

            $zoneType = strtoupper((string)($_POST['zone_type'] ?? 'MASTER'));
            if (!in_array($zoneType, ZoneKind::basicValues(), true)) {
                $this->showError(_('Invalid zone type.'));
                return;
            }
            $ownershipMode = new ZoneOwnershipModeService($this->config);
            $noUserOwnerRequested = !empty($_POST['no_user_owner']);
            if (!$ownershipMode->isUserOwnerAllowed()) {
                $ownerForCreate = null;
            } elseif ($ownershipMode->isGroupOwnerAllowed() && $noUserOwnerRequested) {
                $ownerForCreate = null;
            } else {
                $ownerForCreate = $userId;
            }
            $groupsForCreate = $ownershipMode->isGroupOwnerAllowed() && isset($_POST['groups']) && is_array($_POST['groups'])
                ? array_map('intval', $_POST['groups'])
                : [];
            $ownership = $this->services()->zoneCreateOwnershipResolver()->resolveOwnership($ownerForCreate, $groupsForCreate, $userId);
            if ($ownership->code === ZoneOwnershipResolution::NO_OWNER) {
                $this->showError(_('Cannot create a new zone via import: select at least one group, or leave "No user owner" unchecked.'));
                return;
            }
            if ($ownership->hasError()) {
                $this->showError(ZoneOwnershipFormResolver::errorMessage($ownership));
                return;
            }
            $created = $this->createZoneManagementService()->createZone($zoneName, $zoneType, $ownerForCreate, '', 'none', false, $ownership->groupIds, $userId);
            if (!$created['success']) {
                $this->showError(ZoneCreateFormMessages::errorMessage($created));
                return;
            }
            $zone_id = $created['zone_id'];

            $audit->logZoneImport($zone_id, (string)$zoneName, false);

            // New zone: no conflicts possible
            $conflictStrategy = 'add_all';
        }

        // Import records
        $recordRepository = $this->services()->recordRepository();
        $dnsRecordManager = $this->services()->recordManager();
        $recordManager = $this->services()->recordManagerService();

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
                            $dnsRecordManager->deleteRecord((int)$existing['id'], false);
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
                $userLogin
            );

            if ($result->success) {
                $successCount++;
            } else {
                $failCount++;
            }
        }

        // Clean up session data
        unset($_SESSION[SessionKeys::ZONE_IMPORT_DATA]);

        $this->showForm([
            'result' => true,
            'success_count' => $successCount,
            'fail_count' => $failCount,
            'skip_count' => $skipCount,
            'zone_id' => $zone_id,
            'zone_name' => $zoneName,
        ]);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        return round($bytes / 1024, 1) . ' KB';
    }
}
