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

use Poweradmin\BaseController;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Domain\Service\DnsWizard\WizardRegistry;
use Poweradmin\Infrastructure\Repository\DbZoneRepository;

/**
 * DNS Wizard Selection Controller
 *
 * Displays the wizard selection page where users can choose
 * which DNS record wizard to use.
 */
class DnsWizardController extends BaseController
{
    private DnsRecord $dnsRecord;
    private WizardRegistry $wizardRegistry;
    private DbZoneRepository $zoneRepository;

    public function __construct(array $request)
    {
        parent::__construct($request);

        $this->dnsRecord = new DnsRecord($this->db, $this->getConfig());
        $this->wizardRegistry = new WizardRegistry($this->getConfig());
        $this->zoneRepository = new DbZoneRepository($this->db, $this->getConfig());
    }

    public function run(): void
    {
        // Check if wizards are enabled
        if (!$this->getConfig()->get('dns_wizards', 'enabled', false)) {
            $this->showError(_('DNS wizards are not enabled.'));
        }

        $zone_id = $this->getSafeRequestValue('id');

        if (!is_numeric($zone_id)) {
            $this->showError(_('Invalid zone ID.'));
        }

        $zone_id = (int)$zone_id;

        // Check if zone exists
        $zone_name = $this->zoneRepository->getDomainNameById($zone_id);
        if ($zone_name === null) {
            $this->showError(_('Zone not found.'));
        }

        // Check permissions
        $perm_edit = Permission::getEditPermission($this->db);
        $user_is_zone_owner = UserManager::verifyUserIsOwnerZoneId($this->db, $zone_id);
        $zone_type = $this->dnsRecord->getDomainType($zone_id);

        if ($zone_type == "SLAVE" || $perm_edit == "none" || (($perm_edit == "own" || $perm_edit == "own_as_client") && !$user_is_zone_owner)) {
            $this->showError(_('You do not have permission to add records to this zone.'));
        }

        // Check if zone is reverse zone
        $is_reverse_zone = preg_match('/\.in-addr\.arpa$/i', $zone_name) || preg_match('/\.ip6\.arpa$/i', $zone_name);

        // Get available wizards
        $wizards = $this->wizardRegistry->getWizardMetadata();

        // Render the wizard selection page
        $this->render('dns_wizard_select.html', [
            'zone_id' => $zone_id,
            'zone_name' => $zone_name,
            'is_reverse_zone' => $is_reverse_zone,
            'wizards' => $wizards,
        ]);
    }
}
