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
 * Script that handles requests to add new supermaster servers
 *
 * @package     Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2026 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */

namespace Poweradmin\Application\Controller;

use Exception;
use Poweradmin\Application\Http\Request;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\DnssecAlgorithmName;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Service\DnsIdnService;
use Poweradmin\Domain\Service\Validator;
use Poweradmin\Application\Service\AuditService;
use Poweradmin\Application\Service\DnssecProviderFactory;
use Poweradmin\Domain\Enum\DnssecKeyType;
use Poweradmin\Domain\Service\DnssecKeySpecValidator;

class DnssecAddKeyController extends BaseController
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

        // Early permission check - validate DNSSEC access before any operations
        $perm_view = Permission::getViewPermission($this->db);
        $user_is_zone_owner = $this->isZoneOwner($zone_id);

        // Check view permission first
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

        $key_type = "";
        if ($this->request->getPostParam('key_type') !== null) {
            $key_type = $this->request->getPostParam('key_type');

            if (!is_string($key_type) || !DnssecKeyType::isValid($key_type)) {
                $this->showError(_('Invalid or unexpected input given.'));
            }
        }

        $bits = "";
        if ($this->request->getPostParam('bits') !== null) {
            $bits = $this->request->getPostParam('bits');

            if (!is_string($bits) || !DnssecKeySpecValidator::isValidBits($bits)) {
                $this->showError(_('Invalid or unexpected input given.'));
            }
        }

        $algorithm = "";
        if ($this->request->getPostParam('algorithm') !== null) {
            $algorithm = $this->request->getPostParam('algorithm');

            // The dropdown is filtered against the connected server's
            // capabilities; validate against the same list so the form and
            // the backend never disagree.
            $valid_algorithm = DnssecAlgorithmName::getSupportedAlgorithmsForCapabilities($this->getPdnsCapabilities());
            if (!in_array($algorithm, $valid_algorithm, true)) {
                $this->logger->warning('Invalid DNSSEC algorithm selected: {algorithm}', ['algorithm' => $algorithm]);
                $this->showError(_('Invalid or unexpected input given.'));
            }
        }

        if ($this->request->getPostParam('submit') !== null) {
            $this->validateCsrfToken();

            // Validate combination of algorithm and bits before attempting to add the key
            if (!empty($algorithm) && !empty($bits)) {
                $validationError = DnssecKeySpecValidator::validateAlgorithmBits($algorithm, $bits);
                if ($validationError !== null) {
                    $this->logger->warning('Invalid DNSSEC algorithm/bits combination: algorithm={algorithm}, bits={bits} - {message}', ['algorithm' => $algorithm, 'bits' => $bits, 'message' => $validationError]);
                    $this->setMessage('dnssec_add_key', 'error', $validationError);
                    // Don't redirect, let the form display again with the error message
                } else {
                    try {
                        if ($dnssecProvider->addZoneKey($domain_name, $key_type, (int)$bits, $algorithm)) {
                            $auditService = new AuditService($this->db);
                            $auditService->logDnssecAddKey($zone_id, $domain_name, $key_type, (string)$bits, $algorithm);
                            $this->setMessage('dnssec', 'success', _('Zone key has been added successfully.'));
                            $this->redirect('/zones/' . $zone_id . '/dnssec');
                        } else {
                            $this->logger->error('Failed to add DNSSEC key: domain={domain}, key_type={key_type}, bits={bits}, algorithm={algorithm}', ['domain' => $domain_name, 'key_type' => $key_type, 'bits' => $bits, 'algorithm' => $algorithm]);
                            $this->setMessage('dnssec_add_key', 'error', _('Failed to add new DNSSEC key.'));
                        }
                    } catch (Exception $e) {
                        $this->logger->error('Exception adding DNSSEC key: {error}', ['error' => $e->getMessage()]);
                        $this->setMessage('dnssec_add_key', 'error', _('An error occurred while adding the DNSSEC key: ') . $e->getMessage());
                    }
                }
            } else {
                $this->setMessage('dnssec_add_key', 'error', _('Please select both algorithm and bits'));
            }
        }

        $idn_zone_name = DnsIdnService::toIdnAlias($domain_name);

        // Check PowerDNS version to determine if CSK should be the default
        $pdnsVersion = DnssecProviderFactory::getPowerDnsVersion($this->getConfig());
        $supportsCsk = DnssecProviderFactory::supportsDefaultCsk($pdnsVersion);

        // If no key type is selected yet and PowerDNS 4.0+ is detected, default to CSK
        if (empty($key_type) && $supportsCsk) {
            $key_type = 'csk';
        }

        // Set default values for algorithm and bits if not already set
        if (empty($algorithm)) {
            $algorithm = DnssecAlgorithmName::ECDSA256; // Default to ECDSA P-256
        }

        if (empty($bits)) {
            $bits = '256'; // Default to 256 bits
        }

        $this->render('dnssec_add_key.html', [
            'zone_id' => $zone_id,
            'domain_name' => $domain_name,
            'idn_zone_name' => $idn_zone_name,
            'zone_display_name' => DnsIdnService::toDisplay($domain_name),
            'key_type' => $key_type,
            'bits' => $bits,
            'algorithm' => $algorithm,
            'algorithm_names' => DnssecAlgorithmName::getSupportedAlgorithmNamesForCapabilities($this->getPdnsCapabilities()),
        ]);
    }
}
