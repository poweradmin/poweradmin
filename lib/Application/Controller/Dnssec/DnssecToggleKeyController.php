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

namespace Poweradmin\Application\Controller\Dnssec;

use Exception;
use Poweradmin\Domain\Service\Zone\DnssecKeyOutcome;

/**
 * Handles the POST that activates or deactivates a DNSSEC key, then returns to the zone's DNSSEC page.
 */
class DnssecToggleKeyController extends DnssecKeyController
{
    public function run(): void
    {
        $zone_id = $this->requireNumericParam('zone_id', _('Invalid zone ID.'));
        $key_id = $this->requireNumericParam('key_id', _('Invalid key ID.'));

        if (!$this->isPost()) {
            $this->showError(_('This action requires a POST request.'));
            return;
        }

        [$domain_name] = $this->requireManagedDnssecZone($zone_id);

        try {
            $result = $this->services()->dnssecKeyService()->toggleKey($zone_id, $domain_name, $key_id);
        } catch (Exception $e) {
            $this->logger->error('DNSSEC key toggle failed for zone {domain}, key {key_id}: {error}', ['domain' => $domain_name, 'key_id' => $key_id, 'error' => $e->getMessage()]);
            $this->setMessage('dnssec', 'error', _('An error occurred while toggling the DNSSEC key. Please try again.'));
            $this->redirect('/zones/' . $zone_id . '/dnssec');
            return;
        }

        $this->endOnUnavailableKeys($result, $zone_id);
        if ($result->key === null) {
            $this->showError(_('DNSSEC key not found or no longer exists.'));
            return;
        }

        $isActive = $result->key->isActive();
        if ($result->outcome === DnssecKeyOutcome::UPDATED) {
            $this->setMessage('dnssec', 'success', $isActive ? _('Zone key has been successfully activated.') : _('Zone key has been successfully deactivated.'));
        } else {
            $this->setMessage('dnssec', 'error', $isActive ? _('Failed to deactivate zone key.') : _('Failed to activate zone key.'));
        }

        $this->redirect('/zones/' . $zone_id . '/dnssec');
    }
}
