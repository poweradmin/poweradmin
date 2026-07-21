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

/**
 * Script that handles zone deletion
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2026 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Poweradmin\Application\Http\Request;
use Poweradmin\Application\Service\DnssecProviderFactory;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\DnssecAlgorithm;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Service\DnsIdnService;
use Poweradmin\Domain\Service\Validator;
use Poweradmin\Domain\Utility\DnsHelper;

class DnssecEditKeyController extends BaseController
{
    private Request $request;

    public function __construct(array $request)
    {
        parent::__construct($request);
        $this->request = new Request();
    }

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
        $key_id = (int)$key_id;

        $confirm = "-1";
        $confirmParam = $this->request->getQueryParam('confirm');
        if ($confirmParam !== null && Validator::isNumber($confirmParam)) {
            $confirm = $confirmParam;
        }

        // Early permission check - this page is the confirmation entry for toggling a key,
        // so it requires the dedicated DNSSEC management permission.
        $perm_view = Permission::getViewPermission($this->db);
        $user_is_zone_owner = $this->isZoneOwner($zone_id);

        if ($perm_view == "none" || ($perm_view == "own" && !$user_is_zone_owner)) {
            $this->showError(_("You do not have permission to view this zone."));
            return;
        }

        // Validate zone existence
        $domainRepository = $this->createDomainRepository();
        if (!$domainRepository->zoneIdExists($zone_id)) {
            $this->showError(_('There is no zone with this ID.'));
            return;
        }

        if (!$this->createPermissionService()->canManageDnssecForZone($this->db, $this->getCurrentUserId(), $zone_id)) {
            $this->showError(_("You do not have permission to manage DNSSEC for this zone."));
            return;
        }

        $domain_name = $domainRepository->getDomainNameById($zone_id);
        $dnssecProvider = DnssecProviderFactory::create($this->db, $this->getConfig());

        if ($dnssecProvider->isZonePresigned($domain_name)) {
            $this->setMessage('dnssec', 'error', _('This zone is presigned; DNSSEC keys are managed at the primary server.'));
            $this->redirect('/zones/' . $zone_id . '/dnssec');
            return;
        }

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

        if (str_starts_with($domain_name, "xn--")) {
            $idn_zone_name = DnsIdnService::toUtf8($domain_name);
        } else {
            $idn_zone_name = "";
        }

        $this->render('dnssec_edit_key.html', [
            'domain_name' => $domain_name,
            'idn_zone_name' => $idn_zone_name,
            'key_id' => $key_id,
            'key_info' => $dnssecProvider->getZoneKey($domain_name, $key_id),
            'algorithms' => DnssecAlgorithm::ALGORITHMS,
            'user_is_zone_owner' => $user_is_zone_owner,
            'zone_id' => $zone_id,
            'is_reverse_zone' => DnsHelper::isReverseZoneName($domain_name),
        ]);
    }
}
