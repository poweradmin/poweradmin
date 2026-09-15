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
use Poweradmin\Domain\Model\DnssecAlgorithmName;
use Poweradmin\Domain\Enum\DnssecKeyType;

/**
 * Imports a PEM-encoded DNSSEC private key into a zone via the PowerDNS API.
 * Requires PowerDNS 4.7+ which accepts the `privatekey` field on
 * POST /cryptokeys; older servers reject the call and the UI hides the form.
 */
class DnssecKeyImportController extends DnssecKeyController
{

    public function __construct(array $request)
    {
        parent::__construct($request);
    }

    public function run(): void
    {
        $zoneIdInt = $this->requireNumericParam('id', _('Invalid or unexpected input given.'));
        $zoneId = (string)$zoneIdInt;
        [$domainName, $dnssecProvider] = $this->requireManagedDnssecZone($zoneIdInt);

        $caps = $this->getPdnsCapabilities();
        if (!$caps->supportsPemKeyImportExport()) {
            $this->setMessage('dnssec', 'error', _('PEM key import requires PowerDNS 4.7 or newer.'));
            $this->redirect('/zones/' . $zoneId . '/dnssec');
            return;
        }

        $this->validateCsrfToken();

        $keyType = $this->getSafeRequestValue('key_type');
        $algorithm = $this->getSafeRequestValue('algorithm');
        $privateKeyPem = (string) $this->httpRequest->getPostParam('private_key_pem', '');

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

        if (!str_contains($privateKeyPem, '-----BEGIN') || !str_contains($privateKeyPem, '-----END')) {
            $this->setMessage('dnssec', 'error', _('Provide a PEM-encoded private key (must contain BEGIN/END markers).'));
            $this->redirect('/zones/' . $zoneId . '/dnssec');
            return;
        }

        try {
            if ($dnssecProvider->importZoneKey($domainName, $keyType, $algorithm, $privateKeyPem)) {
                $this->createAuditService()->logDnssecAddKey($zoneIdInt, $domainName, $keyType, '0', $algorithm);
                $this->setMessage('dnssec', 'success', _('PEM key imported successfully.'));
            } else {
                $this->logger->error('Failed to import DNSSEC PEM key: domain={domain}, key_type={key_type}, algorithm={algorithm}', ['domain' => $domainName, 'key_type' => $keyType, 'algorithm' => $algorithm]);
                $this->setMessage('dnssec', 'error', _('Failed to import PEM key. The PowerDNS server may have rejected the format.'));
            }
        } catch (Exception $e) {
            $this->logger->error('Exception importing DNSSEC PEM key: {error}', ['error' => $e->getMessage()]);
            $this->setMessage('dnssec', 'error', _('An error occurred while importing the PEM key: ') . $e->getMessage());
        }

        $this->redirect('/zones/' . $zoneId . '/dnssec');
    }
}
