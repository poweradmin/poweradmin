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

use Exception;
use Poweradmin\Domain\Model\DnssecAlgorithm;
use Poweradmin\Domain\Service\DnsIdnService;
use Poweradmin\Domain\Utility\DnsHelper;

/**
 * Handles the delete confirmation for a DNSSEC key and removes it from the zone on confirmed POST.
 */
class DnssecDeleteKeyController extends DnssecKeyController
{

    public function run(): void
    {
        $zone_id = $this->requireNumericParam('zone_id', _('Invalid zone ID.'));
        $key_id = $this->requireNumericParam('key_id', _('Invalid key ID.'));
        [$domain_name, $dnssecProvider] = $this->requireManagedDnssecZone($zone_id);

        if (!$dnssecProvider->keyExists($domain_name, $key_id)) {
            $this->showError(_('Invalid or unexpected input given.'));
            return;
        }

        if ($this->isPost()) {
            try {
                $result = $dnssecProvider->removeZoneKey($domain_name, $key_id);

                // Check if key still exists to verify deletion
                $keyStillExists = $dnssecProvider->keyExists($domain_name, $key_id);

                if ($result && !$keyStillExists) {
                    $auditService = $this->createAuditService();
                    $auditService->logDnssecDeleteKey($zone_id, $domain_name, $key_id);
                    $this->setMessage('dnssec', 'success', _('Zone key has been deleted successfully.'));
                } else {
                    $this->logger->warning('DNSSEC key deletion verification failed: domain={domain}, key_id={key_id}, api_result={api_result}, key_exists={key_exists}', [
                        'domain' => $domain_name,
                        'key_id' => $key_id,
                        'api_result' => (int)$result,
                        'key_exists' => (int)$keyStillExists,
                    ]);
                    $this->setMessage('dnssec', 'error', _('Failed to delete the zone key.'));
                }

                // Redirect back to DNSSEC page in either case
                $this->redirect('/zones/' . $zone_id . '/dnssec');
            } catch (Exception $e) {
                $this->logger->error('DNSSEC key deletion exception: {error}', ['error' => $e->getMessage()]);
                $this->setMessage('dnssec', 'error', _('An error occurred while deleting the DNSSEC key: ') . $e->getMessage());
                $this->redirect('/zones/' . $zone_id . '/dnssec');
            }
        }

        $this->showKeyInfo($domain_name, $key_id, $zone_id);
    }

    public function showKeyInfo($domain_name, $key_id, int $zone_id): void
    {
        $dnssecProvider = $this->createDnssecProvider();
        $key_info = $dnssecProvider->getZoneKey($domain_name, $key_id);

        $idn_zone_name = DnsIdnService::toIdnAlias($domain_name);

        $this->render('dnssec_delete_key.html', [
            'domain_name' => $domain_name,
            'idn_zone_name' => $idn_zone_name,
            'zone_display_name' => DnsIdnService::toDisplay($domain_name),
            'key_id' => $key_id,
            'key_info' => $key_info,
            'algorithms' => DnssecAlgorithm::ALGORITHMS,
            'zone_id' => $zone_id,
            'is_reverse_zone' => DnsHelper::isReverseZoneName($domain_name),
        ]);
    }
}
