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

class DnssecDeleteKeyController extends BaseController
{

    public function run(): void
    {
        $zone_id = "-1";
        if (isset($_GET['id']) && Validator::isNumber($_GET['id'])) {
            $zone_id = htmlspecialchars($_GET['id']);
        }

        $key_id = -1;
        if (isset($_GET['key_id']) && Validator::isNumber($_GET['key_id'])) {
            $key_id = (int)$_GET['key_id'];
        }

        $confirm = "-1";
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm']) && Validator::isNumber($_POST['confirm'])) {
            $confirm = (string)$_POST['confirm']; // Convert to string for consistent comparison
        }

        $user_is_zone_owner = UserManager::verifyUserIsOwnerZoneId($this->db, $zone_id);

        if ($zone_id == "-1") {
            $this->showError(_('Invalid or unexpected input given.'));
        }

        $dnsRecord = new DnsRecord($this->db, $this->getConfig());
        $domain_name = $dnsRecord->getDomainNameById($zone_id);

        if ($key_id === -1) {
            $this->showError(_('Invalid or unexpected input given.'));
        }

        $dnssecProvider = DnssecProviderFactory::create($this->db, $this->getConfig());

        if (!$dnssecProvider->keyExists($domain_name, $key_id)) {
            $this->showError(_('Invalid or unexpected input given.'));
        }

        if ($user_is_zone_owner != "1") {
            $this->showError(_('Failed to delete DNSSEC key.'));
        }

        if ($confirm == '1') {
            $this->validateCsrfToken();
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
                $this->redirect('index.php', ['page' => 'dnssec', 'id' => $zone_id]);
            } catch (Exception $e) {
                error_log("DNSSEC key deletion exception: " . $e->getMessage());
                $this->setMessage('dnssec', 'error', _('An error occurred while deleting the DNSSEC key: ') . $e->getMessage());
                $this->redirect('index.php', ['page' => 'dnssec', 'id' => $zone_id]);
            }
        }

        $this->showKeyInfo($domain_name, $key_id, $zone_id);
    }

    public function showKeyInfo($domain_name, $key_id, string $zone_id): void
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
        ]);
    }
}
