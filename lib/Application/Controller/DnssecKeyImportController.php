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
use Poweradmin\Application\Http\Request;
use Poweradmin\Application\Service\AuditService;
use Poweradmin\Application\Service\DnssecProviderFactory;
use Poweradmin\BaseController;
use Poweradmin\Domain\Model\DnssecAlgorithmName;
use Poweradmin\Domain\Model\Permission;
use Poweradmin\Domain\Service\DnssecPrivateKeyConverter;
use Poweradmin\Domain\Service\DnssecPrivateKeyRefusal;
use Poweradmin\Domain\Service\Validator;
use Poweradmin\Domain\Enum\DnssecKeyType;

/**
 * Imports a DNSSEC private key into a zone via the PowerDNS API. Takes ISC
 * (BIND) text or an RSA, ECDSA or EdDSA PEM key, which is converted to ISC;
 * PowerDNS accepts private keys on POST /cryptokeys since 4.1.
 */
class DnssecKeyImportController extends BaseController
{
    private Request $request;

    public function __construct(array $request)
    {
        parent::__construct($request);
        $this->request = new Request();
    }

    public function run(): void
    {
        $zoneId = $this->getSafeRequestValue('id');
        if (!$zoneId || !Validator::isNumber($zoneId)) {
            $this->showError(_('Invalid or unexpected input given.'));
            return;
        }

        $zoneIdInt = (int) $zoneId;
        $permView = Permission::getViewPermission($this->db);
        $userIsZoneOwner = $this->isZoneOwner($zoneIdInt);

        if ($permView === 'none' || ($permView === 'own' && !$userIsZoneOwner)) {
            $this->showError(_('You do not have permission to view this zone.'));
            return;
        }

        $domainRepository = $this->createDomainRepository();
        if (!$domainRepository->zoneIdExists($zoneIdInt)) {
            $this->showError(_('There is no zone with this ID.'));
            return;
        }

        if (!$this->createPermissionService()->canManageDnssecForZone($this->db, $this->getCurrentUserId(), $zoneIdInt)) {
            $this->showError(_('You do not have permission to manage DNSSEC for this zone.'));
            return;
        }

        $caps = $this->getPdnsCapabilities();
        if (!$caps->supportsPrivateKeyImport()) {
            $this->setMessage('dnssec', 'error', _('Importing keys requires PowerDNS 4.1 or newer.'));
            $this->redirect('/zones/' . $zoneId . '/dnssec');
            return;
        }

        $domainName = $domainRepository->getDomainNameById($zoneIdInt);
        $dnssecProvider = DnssecProviderFactory::create($this->db, $this->getConfig(), null, $this->logger);

        if ($dnssecProvider->isZonePresigned($domainName)) {
            $this->setMessage('dnssec', 'error', _('This zone is presigned; DNSSEC keys are managed at the primary server.'));
            $this->redirect('/zones/' . $zoneId . '/dnssec');
            return;
        }

        $this->validateCsrfToken();

        $keyType = $this->getSafeRequestValue('key_type');
        $algorithm = $this->getSafeRequestValue('algorithm');
        $privateKeyPem = (string) $this->request->getPostParam('private_key_pem', '');

        if (!DnssecKeyType::isValid($keyType)) {
            $this->setMessage('dnssec', 'error', _('Invalid or unexpected input given.'));
            $this->redirect('/zones/' . $zoneId . '/dnssec');
            return;
        }

        $validAlgorithms = DnssecAlgorithmName::getSupportedAlgorithmsForCapabilities($caps);
        if (!in_array($algorithm, $validAlgorithms, true)) {
            $this->setMessage('dnssec', 'error', _('Invalid or unexpected input given.'));
            $this->redirect('/zones/' . $zoneId . '/dnssec');
            return;
        }

        // PowerDNS takes only ISC (BIND) keys over its API; a PEM key is converted first
        $iscKey = DnssecPrivateKeyConverter::toIsc($privateKeyPem, $algorithm);
        if ($iscKey instanceof DnssecPrivateKeyRefusal) {
            $this->setMessage('dnssec', 'error', $iscKey === DnssecPrivateKeyRefusal::ALGORITHM_MISMATCH
                ? _('The private key does not match the selected algorithm.')
                : _('The private key could not be read. Paste a BIND private key or an unencrypted PEM key.'));
            $this->redirect('/zones/' . $zoneId . '/dnssec');
            return;
        }

        try {
            if ($dnssecProvider->importZoneKey($domainName, $keyType, $algorithm, $iscKey)) {
                (new AuditService($this->db))->logDnssecAddKey($zoneIdInt, $domainName, $keyType, '0', $algorithm);
                $this->setMessage('dnssec', 'success', _('Key imported successfully.'));
            } else {
                $this->logger->error('Failed to import DNSSEC key: domain={domain}, key_type={key_type}, algorithm={algorithm}', ['domain' => $domainName, 'key_type' => $keyType, 'algorithm' => $algorithm]);
                $this->setMessage('dnssec', 'error', _('Failed to import the key. PowerDNS rejected it.'));
            }
        } catch (Exception $e) {
            $this->logger->error('Exception importing DNSSEC key: {error}', ['error' => $e->getMessage()]);
            $this->setMessage('dnssec', 'error', _('An error occurred while importing the key: ') . $e->getMessage());
        }

        $this->redirect('/zones/' . $zoneId . '/dnssec');
    }
}
