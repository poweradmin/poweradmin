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

/**
 * Script that handles editing of zone records
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2026 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Poweradmin\Application\Http\Request;
use Poweradmin\Application\Service\AuditService;
use Poweradmin\Application\Service\DnssecProviderFactory;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\DnssecAlgorithm;
use Poweradmin\Domain\Model\DnssecAlgorithmName;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Model\UserManager;
use Poweradmin\Domain\Model\ZoneTemplate;
use Poweradmin\Domain\Service\DnsIdnService;
use Poweradmin\Domain\Service\Dns\DomainManager;
use Poweradmin\Infrastructure\Service\DnsServiceFactory;
use Poweradmin\Domain\Service\Validator;
use Poweradmin\Domain\Utility\DnsHelper;
use Poweradmin\Domain\Service\SessionKeys;

class DnssecController extends BaseController
{
    private Request $request;

    public function __construct(array $request)
    {
        parent::__construct($request);
        $this->request = new Request();
    }

    public function run(): void
    {
        $zone_id = $this->getSafeRequestValue('id');
        if (!$zone_id || !Validator::isNumber($zone_id)) {
            $this->showError(_('Invalid or unexpected input given.'));
            return;
        }

        $zone_id = (int) $zone_id;

        // Early permission check - validate zone visibility before any operations.
        // The DNSSEC page itself only requires view; per-action gates apply for mutations.
        $perm_view = Permission::getViewPermission($this->db);
        $perm_dnssec = Permission::getDnssecPermission($this->db);
        $user_is_zone_owner = UserManager::verifyUserIsOwnerZoneId($this->db, $zone_id);

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

        ($this->hasPermission('user_view_others')) ? $perm_view_others = "1" : $perm_view_others = "0";

        // Handle unsign zone action - requires dedicated DNSSEC management permission.
        if ($this->request->getPostParam('unsign_zone') !== null) {
            if ($perm_dnssec !== "all" && !($perm_dnssec === "own" && $user_is_zone_owner)) {
                $this->setMessage('dnssec', 'error', _("You do not have permission to manage DNSSEC for this zone."));
                $this->showDnsSecKeys($zone_id);
                return;
            }
            $this->validateCsrfToken();

            $zone_name = $domainRepository->getDomainNameById($zone_id);
            $dnssecProvider = DnssecProviderFactory::create($this->db, $this->getConfig());

            // Check if zone is secured before attempting to unsecure
            if ($zone_name === false) {
                $this->setMessage('dnssec', 'info', _('Zone is not currently signed with DNSSEC.'));
            } elseif ($dnssecProvider->isZonePresigned($zone_name)) {
                $this->setMessage('dnssec', 'error', _('This zone is presigned; DNSSEC keys are managed at the primary server.'));
            } elseif (!$dnssecProvider->isZoneSecured($zone_name, $this->getConfig())) {
                $this->setMessage('dnssec', 'info', _('Zone is not currently signed with DNSSEC.'));
            } else {
                // Try to unsecure the zone
                $result = $dnssecProvider->unsecureZone((string)$zone_name);

                if ($result) {
                    // Verify the zone is now unsecured
                    if (!$dnssecProvider->isZoneSecured((string)$zone_name, $this->getConfig())) {
                        // Update SOA serial after unsigning
                        DnsServiceFactory::createSOARecordManager($this->db, $this->getConfig())->updateSOASerial($zone_id);
                        $auditService = new AuditService($this->db);
                        $auditService->logDnssecUnsignZone($zone_id, (string)$zone_name);
                        $this->setMessage('dnssec', 'success', _('Zone has been unsigned successfully.'));
                        // Redirect to edit page since DNSSEC is no longer relevant
                        $this->redirect('/zones/' . $zone_id . '/edit');
                        return;
                    } else {
                        $this->setMessage('dnssec', 'warning', _('Zone unsigning requested successfully, but verification failed.'));
                        $this->logger->warning('DNSSEC unsigning verification failed for zone: {zone} - API returned success but zone still secured', ['zone' => $zone_name]);
                    }
                } else {
                    $this->setMessage('dnssec', 'error', _('Failed to unsign zone. Check PowerDNS logs for details.'));
                    $this->logger->error('DNSSEC unsigning failed for zone: {zone}', ['zone' => $zone_name]);
                }
            }
        }

        $this->showDnsSecKeys($zone_id);
    }

    public function showDnsSecKeys(int $zone_id): void
    {
        $domainRepository = $this->createDomainRepository();
        $domain_name = $domainRepository->getDomainNameById($zone_id);
        if (str_starts_with($domain_name, "xn--")) {
            $idn_zone_name = DnsIdnService::toUtf8($domain_name);
        } else {
            $idn_zone_name = "";
        }

        $dnssecProvider = DnssecProviderFactory::create($this->db, $this->getConfig());
        $zone_templates = new ZoneTemplate($this->db, $this->getConfig());
        $perm_dnssec = Permission::getDnssecPermission($this->db);
        $user_is_zone_owner = UserManager::verifyUserIsOwnerZoneId($this->db, $zone_id);
        $can_manage_dnssec = $perm_dnssec === 'all' || ($perm_dnssec === 'own' && $user_is_zone_owner);

        $this->render('dnssec.html', [
            'domain_name' => $domain_name,
            'idn_zone_name' => $idn_zone_name,
            'domain_type' => $domainRepository->getDomainType($zone_id),
            'keys' => $dnssecProvider->getKeys($domain_name),
            'pdnssec_use' => $this->config->get('dnssec', 'enabled', false),
            'record_count' => $this->createRecordRepository()->countZoneRecords($zone_id),
            'zone_id' => $zone_id,
            'zone_template_id' => DomainManager::getZoneTemplate($this->db, $zone_id),
            'zone_templates' => $zone_templates->getListZoneTempl($_SESSION[SessionKeys::USERID]),
            'algorithms' => DnssecAlgorithm::ALGORITHMS,
            'algorithm_names' => DnssecAlgorithmName::getSupportedAlgorithmNamesForCapabilities($this->getPdnsCapabilities()),
            'can_manage_dnssec' => $can_manage_dnssec,
            'is_presigned' => $dnssecProvider->isZonePresigned($domain_name),
            'signed_serial' => $dnssecProvider->getEditedSerial($domain_name),
            'is_reverse_zone' => DnsHelper::isReverseZoneName($domain_name),
        ]);
    }
}
