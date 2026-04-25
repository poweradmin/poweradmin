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

namespace Poweradmin\Application\Controller;

use Poweradmin\Application\Service\DnsBackendProviderFactory;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\UserManager;

/**
 * Lists PowerDNS views (5.0+) and the zones they contain. Also exposes inline
 * add/remove actions. The whole feature is admin-only and hidden when the
 * connected server pre-dates 5.0 (capability gate).
 */
class ListViewsController extends BaseController
{
    public function run(): void
    {
        if (!UserManager::verifyPermission($this->db, 'user_is_ueberuser')) {
            $this->showError(_('You do not have permission to manage PowerDNS views.'));
            return;
        }

        if (!$this->getPdnsCapabilities()->supportsViews()) {
            $this->showError(_('Views require PowerDNS 5.0 or newer.'));
            return;
        }

        $apiClient = DnsBackendProviderFactory::createApiClient($this->getConfig(), $this->logger);
        if ($apiClient === null) {
            $this->showError(_('Views are only available with the PowerDNS API backend.'));
            return;
        }

        if (!empty($_POST)) {
            $this->validateCsrfToken();
            $action = $this->getSafeRequestValue('action');
            $view = trim($this->getSafeRequestValue('view'));
            $zone = trim($this->getSafeRequestValue('zone'));

            if ($view === '' || $zone === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $view)) {
                $this->setMessage('list_views', 'error', _('Provide a valid view name and zone identifier.'));
            } elseif ($action === 'add') {
                $ok = $apiClient->addZoneToView($view, $zone);
                $this->setMessage(
                    'list_views',
                    $ok ? 'success' : 'error',
                    $ok ? _('Zone assigned to view.') : _('Failed to assign zone to view.')
                );
            } elseif ($action === 'remove') {
                $ok = $apiClient->removeZoneFromView($view, $zone);
                $this->setMessage(
                    'list_views',
                    $ok ? 'success' : 'error',
                    $ok ? _('Zone removed from view.') : _('Failed to remove zone from view.')
                );
            } else {
                $this->setMessage('list_views', 'error', _('Unknown action.'));
            }

            $this->redirect('/views');
            return;
        }

        $views = $apiClient->listViews();
        sort($views);

        $viewsWithZones = [];
        foreach ($views as $name) {
            $viewsWithZones[] = [
                'name' => $name,
                'zones' => $apiClient->listViewZones($name),
            ];
        }

        $this->setCurrentPage('views');
        $this->setPageTitle(_('Views'));
        $this->render('list_views.html', [
            'views' => $viewsWithZones,
        ]);
    }
}
