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

namespace Poweradmin\Application\Controller\System;

use Exception;
use Poweradmin\Application\Controller\BaseController;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Service\Consistency\ConsistencyCheckerInterface;

/**
 * Renders the database consistency page for admins and applies the fix actions it offers.
 */
class DatabaseConsistencyController extends BaseController
{

    public function run(): void
    {
        if (!$this->getUserContextService()->isAuthenticated()) {
            $this->showError(_('Not available for anonymous users.'));
            return;
        }

        $this->checkPermission(Permission::PERM_USER_IS_UEBERUSER, _('You do not have the permission to view this page.'));

        // Set the current page for navigation highlighting
        $this->setCurrentPage('database_consistency');
        $this->setPageTitle(_('Database Consistency'));

        // Check if consistency checks are enabled
        if (!$this->config->get('interface', 'enable_consistency_checks', false)) {
            $this->showError(_('Database consistency checks are disabled.'));
            return;
        }

        $consistencyService = $this->services()->consistencyChecker();

        // Handle fix actions before the outage check below: owner assignment touches
        // only the local zones table, so it must still work when the API is briefly
        // down. API-dependent fixes fail gracefully on their own.
        if ($this->isPost() && $this->httpRequest->getPostParam('action') !== null && $this->httpRequest->getPostParam('check_type') !== null) {
            $this->handleFixAction($consistencyService);
            return;
        }

        // Run all checks. Null means the API backend was unreachable; surface one
        // clear error instead of rendering empty "all clear" results.
        $results = $consistencyService->runAllChecks();
        if ($results === null) {
            $this->showError(_('Could not reach the PowerDNS API; consistency checks are unavailable.'));
            return;
        }

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

    // Single-item verb each check type accepts; anything else is refused before any repair runs
    private const SINGLE_ITEM_ACTIONS = [
        'zones_without_owners' => 'fix',
        'zones_without_canonical_ids' => 'fix',
        'slave_zones_without_masters' => 'delete',
        'orphaned_records' => 'delete',
        'duplicate_soa' => 'fix',
        'zones_without_soa' => 'fix',
    ];

    /**
     * The check type picks the repair; fix_all runs the bulk form, the type's own
     * verb the single-item form. The checker owns the mapping and the messages.
     */
    private function handleFixAction(ConsistencyCheckerInterface $service): void
    {
        $checkType = (string)$this->httpRequest->getPostParam('check_type', '');
        $action = (string)$this->httpRequest->getPostParam('action', '');
        $currentUserId = (int)$this->getUserContextService()->getLoggedInUserId();

        try {
            if ($action === 'fix_all') {
                $outcome = $service->fixAll($checkType, $currentUserId);
            } elseif ($action !== '' && $action === (self::SINGLE_ITEM_ACTIONS[$checkType] ?? null)) {
                $outcome = $service->fixOne($checkType, (int)$this->httpRequest->getPostParam('item_id', 0), $currentUserId);
            } else {
                $outcome = ['status' => 'error', 'message' => _('Invalid action')];
            }
        } catch (Exception $e) {
            $outcome = ['status' => 'error', 'message' => $e->getMessage()];
        }

        $this->setMessage('database_consistency', $outcome['status'], $outcome['message']);
        $this->redirect('/tools/database-consistency');
    }
}
