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
use Poweradmin\Domain\Service\DnsIdnService;
use Poweradmin\Application\Service\DnssecProviderFactory;
use Poweradmin\Domain\Enum\DnssecKeyType;

/**
 * Handles the add-DNSSEC-key form for a zone: validates key type, bits and algorithm, then creates the key.
 */
class DnssecAddKeyController extends DnssecKeyController
{

    public function __construct(array $request)
    {
        parent::__construct($request);
    }

    public function run(): void
    {
        $zone_id = $this->requireNumericParam('id');
        [$domain_name, $dnssecProvider] = $this->requireManagedDnssecZone($zone_id);

        $key_type = "";
        if ($this->httpRequest->getPostParam('key_type') !== null) {
            $key_type = $this->httpRequest->getPostParam('key_type');

            if (!is_string($key_type) || !DnssecKeyType::isValid($key_type)) {
                $this->showError(_('Invalid or unexpected input given.'));
            }
        }

        $bits = "";
        if ($this->httpRequest->getPostParam('bits') !== null) {
            $bits = $this->httpRequest->getPostParam('bits');

            $valid_values = array('2048', '1024', '384', '256');
            if (!in_array($bits, $valid_values)) {
                $this->showError(_('Invalid or unexpected input given.'));
            }
        }

        $algorithm = "";
        if ($this->httpRequest->getPostParam('algorithm') !== null) {
            $algorithm = $this->httpRequest->getPostParam('algorithm');

            // The dropdown is filtered against the connected server's
            // capabilities; validate against the same list so the form and
            // the backend never disagree.
            $valid_algorithm = DnssecAlgorithmName::getSupportedAlgorithmsForCapabilities($this->getPdnsCapabilities());
            if (!in_array($algorithm, $valid_algorithm, true)) {
                $this->logger->warning('Invalid DNSSEC algorithm selected: {algorithm}', ['algorithm' => $algorithm]);
                $this->showError(_('Invalid or unexpected input given.'));
            }
        }

        // Function to validate algorithm and bit combinations
        $validateAlgorithmBitCombination = function ($algorithm, $bits) {
            // ECDSA algorithms should only use 256 or 384 bits
            if ($algorithm === 'ecdsa256' && $bits !== '256') {
                return ['valid' => false, 'message' => _('ECDSA P-256 algorithm must use 256 bits')];
            }
            if ($algorithm === 'ecdsa384' && $bits !== '384') {
                return ['valid' => false, 'message' => _('ECDSA P-384 algorithm must use 384 bits')];
            }

            // EdDSA algorithms have fixed bit sizes
            if ($algorithm === 'ed25519') {
                if ($bits !== '256') {
                    return ['valid' => false, 'message' => _('ED25519 algorithm must use 256 bits')];
                }
            }
            if ($algorithm === 'ed448') {
                if ($bits !== '456') {
                    return ['valid' => false, 'message' => _('ED448 algorithm must use 456 bits (unsupported in this UI)')];
                }
            }

            // RSA algorithms should use appropriate bit lengths
            if (in_array($algorithm, ['rsasha1', 'rsasha1-nsec3-sha1', 'rsasha256', 'rsasha512'])) {
                if (!in_array($bits, ['1024', '2048'])) {
                    return ['valid' => false, 'message' => _('RSA algorithms should use 1024 or 2048 bits for adequate security')];
                }
            }

            return ['valid' => true, 'message' => ''];
        };

        if ($this->httpRequest->getPostParam('submit') !== null) {
            $this->validateCsrfToken();

            // Validate combination of algorithm and bits before attempting to add the key
            if (!empty($algorithm) && !empty($bits)) {
                $validation = $validateAlgorithmBitCombination($algorithm, $bits);
                if (!$validation['valid']) {
                    $this->logger->warning('Invalid DNSSEC algorithm/bits combination: algorithm={algorithm}, bits={bits} - {message}', ['algorithm' => $algorithm, 'bits' => $bits, 'message' => $validation['message']]);
                    $this->setMessage('dnssec_add_key', 'error', $validation['message']);
                    // Don't redirect, let the form display again with the error message
                } else {
                    try {
                        if ($dnssecProvider->addZoneKey($domain_name, $key_type, (int)$bits, $algorithm)) {
                            $auditService = $this->createAuditService();
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
