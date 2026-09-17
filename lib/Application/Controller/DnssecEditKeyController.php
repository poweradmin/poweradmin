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

use Poweradmin\Domain\Model\DnssecAlgorithm;
use Poweradmin\Domain\Service\DnsIdnService;
use Poweradmin\Domain\Service\Validator;
use Poweradmin\Domain\Utility\DnsHelper;

/**
 * Renders the DNSSEC key detail page that confirms activating or deactivating a key.
 */
class DnssecEditKeyController extends DnssecKeyController
{

    public function run(): void
    {
        $zone_id = $this->requireNumericParam('zone_id', _('Invalid zone ID.'));
        $key_id = $this->requireNumericParam('key_id', _('Invalid key ID.'));

        $confirm = "-1";
        $confirmParam = $this->httpRequest->getQueryParam('confirm');
        if ($confirmParam !== null && Validator::isNumber($confirmParam)) {
            $confirm = $confirmParam;
        }

        // Early permission check - this page is the confirmation entry for toggling a key,
        // so it requires the dedicated DNSSEC management permission.
        [$domain_name, $dnssecProvider] = $this->requireManagedDnssecZone($zone_id);

        if (!$dnssecProvider->keyExists($domain_name, $key_id)) {
            $this->showError(_('Invalid or unexpected input given.'));
            return;
        }

        $key_info = $dnssecProvider->getZoneKey($domain_name, $key_id);

        // Validate that we got valid key information
        if (empty($key_info) || !isset($key_info[5])) {
            $this->showError(_('DNSSEC key not found or no longer exists.'));
            return;
        }

        $idn_zone_name = DnsIdnService::toIdnAlias($domain_name);

        $this->render('dnssec_edit_key.html', [
            'domain_name' => $domain_name,
            'idn_zone_name' => $idn_zone_name,
            'zone_display_name' => DnsIdnService::toDisplay($domain_name),
            'key_id' => $key_id,
            'key_info' => $dnssecProvider->getZoneKey($domain_name, $key_id),
            'algorithms' => DnssecAlgorithm::ALGORITHMS,
            'user_is_zone_owner' => $this->isZoneOwner($zone_id),
            'zone_id' => $zone_id,
            'is_reverse_zone' => DnsHelper::isReverseZoneName($domain_name),
        ]);
    }
}
