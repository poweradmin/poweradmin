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

namespace Poweradmin\Application\Controller;

use Exception;
use Poweradmin\BaseController;
use Poweradmin\Domain\Service\DatabaseConsistencyService;

class DatabaseConsistencyController extends BaseController
{
    public function run(): void
    {
        if (!$this->getUserContextService()->isAuthenticated()) {
            $this->showError(_('Not available for anonymous users.'));
            return;
        }

        $this->checkPermission('user_is_ueberuser', _('You do not have the permission to view this page.'));

        // Check if consistency checks are enabled
        if (!$this->config->get('interface', 'enable_consistency_checks', false)) {
            $this->showError(_('Database consistency checks are disabled.'));
            return;
        }

        $consistencyService = new DatabaseConsistencyService($this->db, $this->config);

        // Handle fix actions
        if ($this->isPost() && isset($_POST['action']) && isset($_POST['check_type'])) {
            $this->validateCsrfToken();
            $this->handleFixAction($consistencyService);
            return;
        }

        // Run all checks
        $results = $consistencyService->runAllChecks();

        // Calculate summary statistics
        $totalIssues = 0;
        $errorCount = 0;
        $warningCount = 0;

        foreach ($results as $check => $result) {
            if ($result['status'] === 'error') {
                $errorCount++;
                $totalIssues += count($result['data']);
            } elseif ($result['status'] === 'warning') {
                $warningCount++;
                $totalIssues += count($result['data']);
            }
        }

        $this->render('database_consistency.html', [
            'results' => $results,
            'total_issues' => $totalIssues,
            'error_count' => $errorCount,
            'warning_count' => $warningCount,
            'page_title' => _('Database Consistency Check')
        ]);
    }

    private function handleFixAction(DatabaseConsistencyService $service): void
    {
        $checkType = $_POST['check_type'] ?? '';
        $action = $_POST['action'] ?? '';
        $itemId = $_POST['item_id'] ?? null;

        try {
            $result = false;
            $message = '';

            switch ($checkType) {
                case 'zones_without_owners':
                    if ($action === 'fix') {
                        $currentUserId = $this->getUserContextService()->getLoggedInUserId();
                        $result = $service->fixZoneWithoutOwner($itemId, $currentUserId);
                        $message = $result ? _('Zone owner assigned successfully') : _('Failed to assign zone owner');
                    }
                    break;

                case 'slave_zones_without_masters':
                    if ($action === 'delete') {
                        $result = $service->deleteSlaveZone($itemId);
                        $message = $result ? _('Slave zone deleted successfully') : _('Failed to delete slave zone');
                    }
                    break;

                case 'orphaned_records':
                    if ($action === 'delete') {
                        $result = $service->deleteOrphanedRecord($itemId);
                        $message = $result ? _('Orphaned record deleted successfully') : _('Failed to delete orphaned record');
                    }
                    break;

                case 'duplicate_soa':
                    if ($action === 'fix') {
                        $result = $service->fixDuplicateSOA($itemId);
                        $message = $result ? _('Duplicate SOA records fixed successfully') : _('Failed to fix duplicate SOA records');
                    }
                    break;

                case 'zones_without_soa':
                    if ($action === 'fix') {
                        $result = $service->createDefaultSOA($itemId);
                        $message = $result ? _('Default SOA record created successfully') : _('Failed to create default SOA record');
                    }
                    break;

                default:
                    $message = _('Invalid check type');
            }

            if ($result) {
                $this->setMessage('database_consistency', 'success', $message);
            } else {
                $this->setMessage('database_consistency', 'error', $message);
            }
        } catch (Exception $e) {
            $this->setMessage('database_consistency', 'error', $e->getMessage());
        }

        $this->redirect('/tools/database-consistency');
    }
}
