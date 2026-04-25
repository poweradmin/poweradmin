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
 * Lists network -> view mappings (PowerDNS 5.0+) and exposes inline
 * add/edit/remove actions. Admin-only; hidden behind the supportsViews()
 * capability gate.
 */
class ListNetworksController extends BaseController
{
    public function run(): void
    {
        if (!UserManager::verifyPermission($this->db, 'user_is_ueberuser')) {
            $this->showError(_('You do not have permission to manage network views.'));
            return;
        }

        if (!$this->getPdnsCapabilities()->supportsViews()) {
            $this->showError(_('Network views require PowerDNS 5.0 or newer.'));
            return;
        }

        $apiClient = DnsBackendProviderFactory::createApiClient($this->getConfig(), $this->logger);
        if ($apiClient === null) {
            $this->showError(_('Network views are only available with the PowerDNS API backend.'));
            return;
        }

        if (!empty($_POST)) {
            $this->validateCsrfToken();
            $action = $this->getSafeRequestValue('action');
            $cidr = trim((string) $this->getSafeRequestValue('cidr'));
            $view = trim((string) $this->getSafeRequestValue('view'));

            if (!$this->isValidCidr($cidr)) {
                $this->setMessage('list_networks', 'error', _('Provide a valid CIDR (e.g. 192.168.0.0/16).'));
            } elseif ($action === 'set') {
                if ($view === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $view)) {
                    $this->setMessage('list_networks', 'error', _('Provide a valid view name.'));
                } else {
                    $ok = $apiClient->setNetworkView($cidr, $view);
                    $this->setMessage(
                        'list_networks',
                        $ok ? 'success' : 'error',
                        $ok ? _('Network mapping saved.') : _('Failed to save network mapping.')
                    );
                }
            } elseif ($action === 'remove') {
                $ok = $apiClient->deleteNetwork($cidr);
                $this->setMessage(
                    'list_networks',
                    $ok ? 'success' : 'error',
                    $ok ? _('Network mapping removed.') : _('Failed to remove network mapping.')
                );
            } else {
                $this->setMessage('list_networks', 'error', _('Unknown action.'));
            }

            $this->redirect('/networks');
            return;
        }

        $networks = $apiClient->listNetworks();
        $views = $apiClient->listViews();
        sort($views);

        $this->setCurrentPage('networks');
        $this->setPageTitle(_('Network views'));
        $this->render('list_networks.html', [
            'networks' => $networks,
            'available_views' => $views,
        ]);
    }

    /**
     * Permissive CIDR check - accepts IPv4 and IPv6 CIDR notation. Final
     * validation happens server-side; this just guards against obvious
     * malformed input being passed through to the API.
     */
    private function isValidCidr(string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return false;
        }
        [$addr, $prefix] = explode('/', $cidr, 2);
        if (!ctype_digit($prefix)) {
            return false;
        }
        $prefixInt = (int) $prefix;
        if (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $prefixInt >= 0 && $prefixInt <= 32;
        }
        if (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return $prefixInt >= 0 && $prefixInt <= 128;
        }
        return false;
    }
}
