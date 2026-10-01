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

namespace Poweradmin\Application\Controller\Dnssec;

use Exception;
use Poweradmin\Domain\Model\DnssecAlgorithmName;
use Poweradmin\Domain\Enum\DnssecKeyType;
use Poweradmin\Domain\Service\Zone\DnssecKeyOutcome;
use Poweradmin\Domain\Service\Zone\DnssecPrivateKeyConverter;

/**
 * Imports a DNSSEC private key into a zone via the PowerDNS API. Takes ISC
 * (BIND) text or an RSA, ECDSA or EdDSA PEM key, which is converted to ISC.
 */
class DnssecKeyImportController extends DnssecKeyController
{
    public function run(): void
    {
        $zoneIdInt = $this->requireNumericParam('id');
        $zoneId = (string)$zoneIdInt;
        [$domainName] = $this->requireManagedDnssecZone($zoneIdInt);

        $keyType = $this->getSafeRequestValue('key_type');
        $algorithm = $this->getSafeRequestValue('algorithm');

        if (!DnssecKeyType::isValid($keyType)) {
            $this->refuse($zoneId, _('Invalid or unexpected input given.'));
            return;
        }

        if (!$this->getPdnsCapabilities()->supportsPrivateKeyImport()) {
            $this->refuse($zoneId, _('Importing keys requires PowerDNS 4.1 or newer.'));
            return;
        }

        $validAlgorithms = DnssecAlgorithmName::getSupportedAlgorithmsForCapabilities($this->getPdnsCapabilities());
        if (!in_array($algorithm, $validAlgorithms, true)) {
            $this->refuse($zoneId, _('Invalid or unexpected input given.'));
            return;
        }

        // Read raw: the sanitised accessor would alter the key text
        $isc = DnssecPrivateKeyConverter::toIsc((string)$this->httpRequest->getPostParam('private_key', ''), $algorithm);
        if ($isc instanceof DnssecKeyOutcome) {
            $this->refuse($zoneId, $isc === DnssecKeyOutcome::KEY_ALGORITHM_MISMATCH
                ? _('The private key does not match the selected algorithm.')
                : _('The private key could not be read. Paste a BIND private key or an unencrypted PEM key.'));
            return;
        }

        try {
            $result = $this->services()->dnssecKeyService()->importKey($zoneIdInt, $domainName, $keyType, $isc, false);
        } catch (Exception $e) {
            $this->logger->error('Exception importing DNSSEC key: {error}', ['error' => $e->getMessage()]);
            $this->refuse($zoneId, _('An error occurred while importing the key: ') . $e->getMessage());
            return;
        }

        $this->endOnUnavailableKeys($result, $zoneIdInt);

        if ($result->outcome === DnssecKeyOutcome::ADDED) {
            $this->setMessage('dnssec', 'success', _('Key imported successfully.'));
        } else {
            $this->logger->error(
                'Failed to import DNSSEC key: domain={domain}, key_type={key_type}, algorithm={algorithm}, outcome={outcome}',
                ['domain' => $domainName, 'key_type' => $keyType, 'algorithm' => $algorithm, 'outcome' => $result->outcome->value]
            );
            $this->setMessage('dnssec', 'error', $result->outcome === DnssecKeyOutcome::KEY_REJECTED
                ? _('Failed to import the key. PowerDNS rejected it.')
                : _('Failed to import the key.'));
        }

        $this->redirect('/zones/' . $zoneId . '/dnssec');
    }

    private function refuse(string $zoneId, string $message): void
    {
        $this->setMessage('dnssec', 'error', $message);
        $this->redirect('/zones/' . $zoneId . '/dnssec');
    }
}
