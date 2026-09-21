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
 *
 */

namespace Poweradmin\Application\Controller\Dnssec;

use Poweradmin\Application\Controller\BaseController;
use Poweradmin\Application\Service\ZoneSigningMessages;
use Poweradmin\Domain\Model\DnssecAlgorithm;
use Poweradmin\Domain\Model\DnssecAlgorithmName;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Utility\DnsIdnService;
use Poweradmin\Domain\Service\Zone\ZoneSigningOutcome;
use Poweradmin\Domain\Utility\DnsHelper;

/**
 * Renders the DNSSEC key overview for a zone and handles the unsign-zone action.
 */
class DnssecController extends BaseController
{

    public function run(): void
    {
        $zone_id = $this->requireNumericParam('id');

        // Early permission check - validate zone visibility before any operations.
        // The DNSSEC page itself only requires view; per-action gates apply for mutations.
        $this->requireZoneView($zone_id);

        // Validate zone existence
        $domainRepository = $this->services()->domainRepository();
        if (!$domainRepository->zoneIdExists($zone_id)) {
            $this->showError(_('There is no zone with this ID.'));
            return;
        }

        ($this->hasPermission(Permission::PERM_USER_VIEW_OTHERS)) ? $perm_view_others = "1" : $perm_view_others = "0";

        // Handle unsign zone action - requires dedicated DNSSEC management permission.
        if ($this->httpRequest->getPostParam('unsign_zone') !== null) {
            if (!$this->services()->permissionService()->canManageDnssecForZone($this->getCurrentUserId(), $zone_id)) {
                $this->setMessage('dnssec', 'error', _("You do not have permission to manage DNSSEC for this zone."));
                $this->showDnsSecKeys($zone_id);
                return;
            }

            $zone_name = $domainRepository->getDomainNameById($zone_id);
            if ($zone_name === null) {
                $this->setMessage('dnssec', 'info', _('Zone is not currently signed with DNSSEC.'));
            } else {
                $unsigned = $this->services()->zoneSigningService()->unsign($zone_id, $zone_name);
                [$type, $message] = ZoneSigningMessages::forUnsign($unsigned);
                $this->setMessage('dnssec', $type, $message);
                if ($unsigned->outcome === ZoneSigningOutcome::UNSIGNED) {
                    // Redirect to edit page since DNSSEC is no longer relevant
                    $this->redirect('/zones/' . $zone_id . '/edit');
                    return;
                }
            }
        }

        $this->showDnsSecKeys($zone_id);
    }

    public function showDnsSecKeys(int $zone_id): void
    {
        $domainRepository = $this->services()->domainRepository();
        $domain_name = $domainRepository->getDomainNameById($zone_id);
        $idn_zone_name = DnsIdnService::toIdnAlias($domain_name);

        $dnssecProvider = $this->services()->dnssecProvider();
        $zone_templates = $this->services()->zoneTemplateService();
        $permissionService = $this->services()->permissionService();
        $can_manage_dnssec = $permissionService->canManageDnssecForZone($this->getCurrentUserId(), $zone_id);
        // Kept for 4.4.0 theme forks that still gate the page on perm_edit
        $perm_edit = $permissionService->getEditPermissionLevelForZone($this->getCurrentUserId(), $zone_id);

        $this->render('dnssec.html', [
            'domain_name' => $domain_name,
            'idn_zone_name' => $idn_zone_name,
            'zone_display_name' => DnsIdnService::toDisplay($domain_name),
            'domain_type' => $domainRepository->getDomainType($zone_id),
            'keys' => $dnssecProvider->getKeys($domain_name),
            'pdnssec_use' => $this->config->get('dnssec', 'enabled', false),
            'record_count' => $this->services()->recordRepository()->countZoneRecords($zone_id),
            'zone_id' => $zone_id,
            'zone_template_id' => $this->services()->zoneTemplateRepository()->getTemplateIdForZone($zone_id),
            'zone_templates' => $zone_templates->getListZoneTempl((int)$this->getCurrentUserId()),
            'algorithms' => DnssecAlgorithm::ALGORITHMS,
            'algorithm_names' => DnssecAlgorithmName::getSupportedAlgorithmNamesForCapabilities($this->getPdnsCapabilities()),
            'can_manage_dnssec' => $can_manage_dnssec,
            'perm_edit' => $perm_edit,
            'is_presigned' => $dnssecProvider->isZonePresigned($domain_name),
            'signed_serial' => $dnssecProvider->getEditedSerial($domain_name),
            'is_reverse_zone' => DnsHelper::isReverseZoneName($domain_name),
        ]);
    }
}
