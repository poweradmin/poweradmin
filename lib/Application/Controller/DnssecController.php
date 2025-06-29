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
 *
 */

/**
 * Script that handles editing of zone records
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Poweradmin\Application\Service\DnssecProviderFactory;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\DnssecAlgorithm;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Model\ZoneTemplate;
use Poweradmin\Domain\Service\DnsIdnService;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Domain\Service\Validator;

class DnssecController extends BaseController
{

    public function run(): void
    {
        if (!isset($_GET['id']) || !Validator::isNumber($_GET['id'])) {
            $this->showError(_('Invalid or unexpected input given.'));
            return;
        }

        $zone_id = (int) $_GET['id'];

        // Early permission check - validate DNSSEC access before any operations
        $perm_view = Permission::getViewPermission($this->db);
        $perm_edit = Permission::getEditPermission($this->db);
        $user_is_zone_owner = UserManager::verifyUserIsOwnerZoneId($this->db, $zone_id);

        // Check view permission first
        if ($perm_view == "none" || ($perm_view == "own" && !$user_is_zone_owner)) {
            $this->showError(_("You do not have permission to view this zone."));
            return;
        }

        // Validate zone existence
        $dnsRecord = new DnsRecord($this->db, $this->getConfig());
        if (!$dnsRecord->zoneIdExists($zone_id)) {
            $this->showError(_('There is no zone with this ID.'));
            return;
        }

        // Check DNSSEC management permission (requires edit access)
        if ($perm_edit == "none" || ($perm_edit == "own" && !$user_is_zone_owner)) {
            $this->showError(_("You do not have permission to manage DNSSEC for this zone."));
            return;
        }

        (UserManager::verifyPermission($this->db, 'user_view_others')) ? $perm_view_others = "1" : $perm_view_others = "0";

        // Handle unsign zone action
        if (isset($_POST['unsign_zone']) && $perm_edit != "none") {
            $this->validateCsrfToken();

            $zone_name = $dnsRecord->getDomainNameById($zone_id);
            $dnssecProvider = DnssecProviderFactory::create($this->db, $this->getConfig());

            // Check if zone is secured before attempting to unsecure
            if ($zone_name === false || !$dnssecProvider->isZoneSecured((string)$zone_name, $this->getConfig())) {
                $this->setMessage('dnssec', 'info', _('Zone is not currently signed with DNSSEC.'));
            } else {
                // Try to unsecure the zone
                $result = $dnssecProvider->unsecureZone((string)$zone_name);

                if ($result) {
                    // Verify the zone is now unsecured
                    if (!$dnssecProvider->isZoneSecured((string)$zone_name, $this->getConfig())) {
                        // Update SOA serial after unsigning
                        $dnsRecord->updateSOASerial($zone_id);
                        $this->setMessage('dnssec', 'success', _('Zone has been unsigned successfully.'));
                        // Redirect to edit page since DNSSEC is no longer relevant
                        $this->redirect('index.php?page=edit&id=' . $zone_id);
                        return;
                    } else {
                        $this->setMessage('dnssec', 'warning', _('Zone unsigning requested successfully, but verification failed.'));
                        error_log("DNSSEC unsigning verification failed for zone: $zone_name - API returned success but zone still secured");
                    }
                } else {
                    $this->setMessage('dnssec', 'error', _('Failed to unsign zone. Check PowerDNS logs for details.'));
                    error_log("DNSSEC unsigning failed for zone: $zone_name");
                }
            }
        }

        $this->showDnsSecKeys($zone_id);
    }

    public function showDnsSecKeys(int $zone_id): void
    {
        $dnsRecord = new DnsRecord($this->db, $this->getConfig());
        $domain_name = $dnsRecord->getDomainNameById($zone_id);
        if (str_starts_with($domain_name, "xn--")) {
            $idn_zone_name = DnsIdnService::toUtf8($domain_name);
        } else {
            $idn_zone_name = "";
        }

        $dnssecProvider = DnssecProviderFactory::create($this->db, $this->getConfig());
        $dnsRecord = new DnsRecord($this->db, $this->getConfig());
        $zone_templates = new ZoneTemplate($this->db, $this->getConfig());
        $perm_edit = Permission::getEditPermission($this->db);

        $this->render('dnssec.html', [
            'domain_name' => $domain_name,
            'idn_zone_name' => $idn_zone_name,
            'domain_type' => $dnsRecord->getDomainType($zone_id),
            'keys' => $dnssecProvider->getKeys($domain_name),
            'pdnssec_use' => $this->config->get('dnssec', 'enabled', false),
            'record_count' => $dnsRecord->countZoneRecords($zone_id),
            'zone_id' => $zone_id,
            'zone_template_id' => DnsRecord::getZoneTemplate($this->db, $zone_id),
            'zone_templates' => $zone_templates->getListZoneTempl($_SESSION['userid']),
            'algorithms' => DnssecAlgorithm::ALGORITHMS,
            'perm_edit' => $perm_edit,
        ]);
    }
}
