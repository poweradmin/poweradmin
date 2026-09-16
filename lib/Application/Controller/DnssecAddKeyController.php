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

        $algorithmBits = DnssecAlgorithmName::getAlgorithmBitsForCapabilities($this->getPdnsCapabilities());
        $offeredBits = array_values(array_unique(array_merge(...array_values($algorithmBits))));
        rsort($offeredBits);

        $bits = "";
        if ($this->httpRequest->getPostParam('bits') !== null) {
            $bits = $this->httpRequest->getPostParam('bits');

            if (!in_array($bits, array_map('strval', $offeredBits), true)) {
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

        // The accepted sizes come from the algorithm map; only the wording is per algorithm.
        $validateAlgorithmBitCombination = function (string $algorithm, string $bits) use ($algorithmBits): array {
            $allowed = $algorithmBits[$algorithm] ?? [];
            if ($allowed === [] || in_array((int)$bits, $allowed, true)) {
                return ['valid' => true, 'message' => ''];
            }
            $message = match ($algorithm) {
                DnssecAlgorithmName::ECDSA256 => _('ECDSA P-256 algorithm must use 256 bits'),
                DnssecAlgorithmName::ECDSA384 => _('ECDSA P-384 algorithm must use 384 bits'),
                DnssecAlgorithmName::ED25519 => _('ED25519 algorithm must use 256 bits'),
                DnssecAlgorithmName::ED448 => _('ED448 algorithm must use 456 bits (unsupported in this UI)'),
                default => _('RSA algorithms should use 1024 or 2048 bits for adequate security'),
            };
            return ['valid' => false, 'message' => $message];
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
            'algorithm_bits' => $algorithmBits,
            'bits_algorithms' => $this->algorithmsByBits($algorithmBits, $offeredBits),
        ]);
    }

    /**
     * Invert the algorithm map for the bit-size dropdown: size => algorithms that accept it.
     *
     * @param array<string, array<int, int>> $algorithmBits
     * @param array<int, int> $offeredBits Sizes in display order
     * @return array<int, array<int, string>>
     */
    private function algorithmsByBits(array $algorithmBits, array $offeredBits): array
    {
        $out = [];
        foreach ($offeredBits as $size) {
            $out[$size] = array_keys(array_filter($algorithmBits, static fn(array $sizes): bool => in_array($size, $sizes, true)));
        }
        return $out;
    }
}
