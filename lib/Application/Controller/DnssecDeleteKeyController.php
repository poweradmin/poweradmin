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
 * Script that handles zone deletion
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Exception;
use Poweradmin\Application\Service\DnssecProviderFactory;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\DnssecAlgorithm;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Service\DnsIdnService;
use Poweradmin\Domain\Service\DnsRecord;
use Poweradmin\Domain\Service\Validator;
use Poweradmin\Domain\Utility\DnsHelper;

class DnssecDeleteKeyController extends BaseController
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

        $confirm = "-1";
        if (isset($_GET['confirm']) && Validator::isNumber($_GET['confirm'])) {
            $confirm = (string)$_GET['confirm']; // Convert to string for consistent comparison
        }

        $user_is_zone_owner = UserManager::verifyUserIsOwnerZoneId($this->db, $zone_id);

        $dnsRecord = new DnsRecord($this->db, $this->getConfig());
        $domain_name = $dnsRecord->getDomainNameById($zone_id);

        $dnssecProvider = DnssecProviderFactory::create($this->db, $this->getConfig());

        if (!$dnssecProvider->keyExists($domain_name, $key_id)) {
            $this->showError(_('Invalid or unexpected input given.'));
        }

        if ($user_is_zone_owner != "1") {
            $this->showError(_('Failed to delete DNSSEC key.'));
        }

        if ($confirm == '1') {
            try {
                $result = $dnssecProvider->removeZoneKey($domain_name, $key_id);

                // Check if key still exists to verify deletion
                $keyStillExists = $domain_name !== null && $dnssecProvider->keyExists($domain_name, $key_id);

                if ($result && !$keyStillExists) {
                    $this->setMessage('dnssec', 'success', _('Zone key has been deleted successfully.'));
                } else {
                    error_log("DNSSEC key deletion verification failed: domain=$domain_name, key_id=$key_id, api_result=$result, key_exists=$keyStillExists");
                    $this->setMessage('dnssec', 'error', _('Failed to delete the zone key.'));
                }

                // Redirect back to DNSSEC page in either case
                $this->redirect('/zones/' . $zone_id . '/dnssec');
            } catch (Exception $e) {
                error_log("DNSSEC key deletion exception: " . $e->getMessage());
                $this->setMessage('dnssec', 'error', _('An error occurred while deleting the DNSSEC key: ') . $e->getMessage());
                $this->redirect('/zones/' . $zone_id . '/dnssec');
            }
        }

        $this->showKeyInfo($domain_name, $key_id, $zone_id);
    }

    public function showKeyInfo($domain_name, $key_id, int $zone_id): void
    {
        $dnssecProvider = DnssecProviderFactory::create($this->db, $this->getConfig());
        $key_info = $dnssecProvider->getZoneKey($domain_name, $key_id);

        if (str_starts_with($domain_name, "xn--")) {
            $idn_zone_name = DnsIdnService::toUtf8($domain_name);
        } else {
            $idn_zone_name = "";
        }

        $this->render('dnssec_delete_key.html', [
            'domain_name' => $domain_name,
            'idn_zone_name' => $idn_zone_name,
            'key_id' => $key_id,
            'key_info' => $key_info,
            'algorithms' => DnssecAlgorithm::ALGORITHMS,
            'zone_id' => $zone_id,
            'is_reverse_zone' => DnsHelper::isReverseZone($domain_name),
        ]);
    }
}
