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

/**
 * Controller that handles DNSSEC key toggle (activate/deactivate) operations
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Poweradmin\Application\Service\DnssecProviderFactory;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Domain\Service\Validator;

class DnssecToggleKeyController extends BaseController
{
    public function run(): void
    {
        $zone_id = $this->getSafeRequestValue('zone_id');
        if (!$zone_id || !Validator::isNumber($zone_id)) {
            $this->showError(_('Invalid zone ID.'));
            return;
        }
        $zone_id = (int) $zone_id;

        $key_id = $this->getSafeRequestValue('key_id');
        if (!$key_id || !Validator::isNumber($key_id)) {
            $this->showError(_('Invalid key ID.'));
            return;
        }
        $key_id = (int) $key_id;

        // Validate permissions
        $perm_edit = Permission::getEditPermission($this->db);
        $user_is_zone_owner = UserManager::verifyUserIsOwnerZoneId($this->db, $zone_id);

        if ($perm_edit == "none" || ($perm_edit == "own" && !$user_is_zone_owner)) {
            $this->showError(_("You do not have permission to manage DNSSEC for this zone."));
            return;
        }

        // Validate zone existence
        $dnsRecord = new DnsRecord($this->db, $this->getConfig());
        if (!$dnsRecord->zoneIdExists($zone_id)) {
            $this->showError(_('There is no zone with this ID.'));
            return;
        }

        $domain_name = $dnsRecord->getDomainNameById($zone_id);
        $dnssecProvider = DnssecProviderFactory::create($this->db, $this->getConfig());

        // Check if DNSSEC is available
        if (!$dnssecProvider->isDnssecEnabled()) {
            $this->showError(_('DNSSEC functionality is not available. Please check PowerDNS API configuration.'));
            return;
        }

        // Get current key information
        try {
            $key_info = $dnssecProvider->getZoneKey($domain_name, $key_id);

            if (empty($key_info) || !isset($key_info[5])) {
                $this->showError(_('DNSSEC key not found or no longer exists.'));
                return;
            }

            $is_active = $key_info[5];
            $action = $is_active ? 'deactivate' : 'activate';
            $result = false;

            // Perform the toggle operation
            if ($is_active) {
                $result = $dnssecProvider->deactivateZoneKey($domain_name, $key_id);
                $success_message = _('Zone key has been successfully deactivated.');
                $error_message = _('Failed to deactivate zone key.');
            } else {
                $result = $dnssecProvider->activateZoneKey($domain_name, $key_id);
                $success_message = _('Zone key has been successfully activated.');
                $error_message = _('Failed to activate zone key.');
            }

            // Set appropriate message and redirect
            if ($result) {
                $this->setMessage('dnssec', 'success', $success_message);
            } else {
                $this->setMessage('dnssec', 'error', $error_message);
            }
        } catch (\Exception $e) {
            error_log("DNSSEC key toggle failed for zone $domain_name, key $key_id: " . $e->getMessage());
            $this->setMessage('dnssec', 'error', _('An error occurred while toggling the DNSSEC key. Please try again.'));
        }

        $this->redirect('/zones/' . $zone_id . '/dnssec');
    }
}
