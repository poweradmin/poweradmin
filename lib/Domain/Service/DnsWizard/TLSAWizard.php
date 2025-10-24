<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2025 Poweradmin Development Team
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

namespace Poweradmin\Domain\Service\DnsWizard;

use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;

/**
 * TLSA Wizard
 *
 * Wizard for creating TLSA (TLS Authentication) records for DANE.
 * TLSA records are published at _port._protocol.hostname and specify
 * certificate or public key data for TLS validation.
 *
 * IMPORTANT: TLSA requires DNSSEC to be enabled on the zone.
 *
 * @package Poweradmin\Domain\Service\DnsWizard
 */
class TLSAWizard extends AbstractDnsWizard
{
    public function __construct(ConfigurationInterface $config)
    {
        parent::__construct($config);
        $this->recordType = 'TLSA';
        $this->wizardType = 'TLSA';
        $this->displayName = _('TLSA Record');
        $this->description = _('TLS certificate/key authentication (DANE)');
        $this->supportsTwoModes = true;
    }

    /**
     * {@inheritdoc}
     */
    public function getFormSchema(): array
    {
        return [
            'sections' => [
                [
                    'title' => _('DNSSEC Requirement'),
                    'type' => 'warning',
                    'content' => _('⚠️ TLSA records require DNSSEC to be enabled for this zone. TLSA records without DNSSEC will not provide security benefits and may be ignored by clients.')
                ],
                [
                    'title' => _('Service Configuration'),
                    'fields' => [
                        [
                            'name' => 'port',
                            'label' => _('Port Number'),
                            'type' => 'number',
                            'required' => true,
                            'default' => 443,
                            'min' => 1,
                            'max' => 65535,
                            'help' => _('TCP/UDP port number (443 for HTTPS, 25 for SMTP, 853 for DNS-over-TLS, etc.)')
                        ],
                        [
                            'name' => 'protocol',
                            'label' => _('Protocol'),
                            'type' => 'select',
                            'required' => true,
                            'default' => '_tcp',
                            'options' => [
                                ['value' => '_tcp', 'label' => 'TCP'],
                                ['value' => '_udp', 'label' => 'UDP'],
                                ['value' => '_sctp', 'label' => 'SCTP'],
                            ],
                            'help' => _('Transport protocol (usually TCP)')
                        ],
                        [
                            'name' => 'hostname',
                            'label' => _('Hostname'),
                            'type' => 'text',
                            'required' => false,
                            'placeholder' => 'mail.example.com',
                            'help' => _('Hostname for the service (leave empty for zone apex @). Record will be created at _port._protocol.hostname')
                        ],
                    ]
                ],
                [
                    'title' => _('Certificate Usage'),
                    'fields' => [
                        [
                            'name' => 'usage',
                            'label' => _('Usage'),
                            'type' => 'radio',
                            'required' => true,
                            'default' => 3,
                            'options' => [
                                ['value' => 0, 'label' => _('0 - CA Constraint'), 'description' => _('Certificate must chain to specified CA (PKIX-TA)')],
                                ['value' => 1, 'label' => _('1 - Service Certificate'), 'description' => _('Certificate must match exactly (PKIX-EE)')],
                                ['value' => 2, 'label' => _('2 - Trust Anchor Assertion'), 'description' => _('Certificate must chain to specified anchor (DANE-TA)')],
                                ['value' => 3, 'label' => _('3 - Domain-Issued Certificate [RECOMMENDED]'), 'description' => _('Certificate must match exactly, bypassing PKI (DANE-EE)')],
                            ],
                            'help' => _('How the certificate should be validated. Type 3 is most common for self-signed or custom CAs.')
                        ],
                    ]
                ],
                [
                    'title' => _('Selector'),
                    'fields' => [
                        [
                            'name' => 'selector',
                            'label' => _('Selector'),
                            'type' => 'radio',
                            'required' => true,
                            'default' => 1,
                            'options' => [
                                ['value' => 0, 'label' => _('0 - Full Certificate'), 'description' => _('Use the entire certificate')],
                                ['value' => 1, 'label' => _('1 - Public Key [RECOMMENDED]'), 'description' => _('Use only the Subject Public Key Info (SPKI)')],
                            ],
                            'help' => _('What part of the certificate to match. Type 1 (public key) is recommended as it survives certificate renewal.')
                        ],
                    ]
                ],
                [
                    'title' => _('Matching Type'),
                    'fields' => [
                        [
                            'name' => 'matching_type',
                            'label' => _('Matching Type'),
                            'type' => 'radio',
                            'required' => true,
                            'default' => 1,
                            'options' => [
                                ['value' => 0, 'label' => _('0 - Exact Match'), 'description' => _('Full certificate/key data (not recommended - very long record)')],
                                ['value' => 1, 'label' => _('1 - SHA-256 Hash [RECOMMENDED]'), 'description' => _('SHA-256 hash of certificate/key')],
                                ['value' => 2, 'label' => _('2 - SHA-512 Hash'), 'description' => _('SHA-512 hash of certificate/key (more secure but longer)')],
                            ],
                            'help' => _('How to encode the certificate data. SHA-256 (type 1) is recommended.')
                        ],
                    ]
                ],
                [
                    'title' => _('Certificate Data'),
                    'fields' => [
                        [
                            'name' => 'cert_data',
                            'label' => _('Certificate Association Data'),
                            'type' => 'textarea',
                            'required' => true,
                            'rows' => 4,
                            'placeholder' => 'd2abde240d7cd3ee6b4b28c54df034b97983a1d16e8a410e4561cb106618e971',
                            'help' => _('Hexadecimal hash or full certificate data (depending on matching type). For SHA-256: use "openssl x509 -in cert.pem -pubkey -noout | openssl pkey -pubin -outform DER | openssl dgst -sha256"')
                        ],
                    ]
                ],
                [
                    'title' => _('Record Settings'),
                    'fields' => [
                        [
                            'name' => 'ttl',
                            'label' => _('TTL (seconds)'),
                            'type' => 'number',
                            'required' => false,
                            'default' => 3600,
                            'min' => 0,
                            'help' => _('Time to live - how long DNS resolvers should cache this record')
                        ],
                    ]
                ],
            ]
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function generateRecord(array $formData): array
    {
        $port = (int) ($formData['port'] ?? 443);
        $protocol = $formData['protocol'] ?? '_tcp';
        $hostname = $this->sanitizeInput($formData['hostname'] ?? '');

        $usage = (int) ($formData['usage'] ?? 3);
        $selector = (int) ($formData['selector'] ?? 1);
        $matchingType = (int) ($formData['matching_type'] ?? 1);
        $certData = $this->cleanHexData($formData['cert_data'] ?? '');

        // Build record name: _port._protocol.hostname
        if (empty($hostname) || $hostname === '@') {
            $recordName = "_{$port}.{$protocol}";
        } else {
            $recordName = "_{$port}.{$protocol}.{$hostname}";
        }

        // Build TLSA record content: usage selector matching-type certificate-data
        $content = sprintf('%d %d %d %s', $usage, $selector, $matchingType, $certData);

        return [
            'name' => $recordName,
            'type' => 'TLSA',
            'content' => $content,
            'ttl' => (int) ($formData['ttl'] ?? $this->getDefaultTTL()),
            'prio' => 0
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function validate(array $formData): array
    {
        $errors = [];
        $warnings = [];

        // Validate port
        $port = (int) ($formData['port'] ?? 0);
        if ($port < 1 || $port > 65535) {
            $errors[] = _('Port must be between 1 and 65535');
        }

        // Validate usage, selector, matching type
        $usage = (int) ($formData['usage'] ?? -1);
        if (!in_array($usage, [0, 1, 2, 3])) {
            $errors[] = _('Usage must be 0, 1, 2, or 3');
        }

        $selector = (int) ($formData['selector'] ?? -1);
        if (!in_array($selector, [0, 1])) {
            $errors[] = _('Selector must be 0 or 1');
        }

        $matchingType = (int) ($formData['matching_type'] ?? -1);
        if (!in_array($matchingType, [0, 1, 2])) {
            $errors[] = _('Matching type must be 0, 1, or 2');
        }

        // Validate certificate data
        if (empty($formData['cert_data'])) {
            $errors[] = _('Certificate association data is required');
        } else {
            $certData = $this->cleanHexData($formData['cert_data']);

            if (!ctype_xdigit($certData)) {
                $errors[] = _('Certificate data must be hexadecimal (0-9, a-f)');
            }

            // Validate expected length based on matching type
            if ($matchingType === 1) { // SHA-256
                if (strlen($certData) !== 64) {
                    $warnings[] = sprintf(_('SHA-256 hash should be 64 hexadecimal characters, got %d. Verify the hash is correct.'), strlen($certData));
                }
            } elseif ($matchingType === 2) { // SHA-512
                if (strlen($certData) !== 128) {
                    $warnings[] = sprintf(_('SHA-512 hash should be 128 hexadecimal characters, got %d. Verify the hash is correct.'), strlen($certData));
                }
            } elseif ($matchingType === 0) { // Full cert
                if (strlen($certData) < 100) {
                    $warnings[] = _('Full certificate data seems too short. Ensure you provided the complete certificate.');
                }
            }
        }

        // Validate hostname if provided
        if (!empty($formData['hostname']) && $formData['hostname'] !== '@' && !$this->isValidDomain($formData['hostname'])) {
            $errors[] = _('Invalid hostname format');
        }

        // Critical DNSSEC warning
        $warnings[] = _('⚠️ CRITICAL: TLSA records require DNSSEC to be enabled on this zone. Without DNSSEC, TLSA records provide NO security benefit.');

        // Validate TTL
        if (isset($formData['ttl'])) {
            $ttlValidation = $this->validateTTL($formData['ttl']);
            if (!$ttlValidation['valid']) {
                $errors = array_merge($errors, $ttlValidation['errors']);
            }
        }

        return $this->createValidationResult(empty($errors), $errors, $warnings);
    }

    /**
     * {@inheritdoc}
     */
    public function parseExistingRecord(string $content, array $recordData = []): array
    {
        // Parse TLSA format: usage selector matching-type certificate-data
        $parts = preg_split('/\s+/', trim($content), 4);

        // Extract port and protocol from record name (_443._tcp.mail.example.com)
        $name = $recordData['name'] ?? '';
        $port = 443;
        $protocol = '_tcp';
        $hostname = '';

        if (preg_match('/^_(\d+)\.(_[a-z]+)(?:\.(.+))?$/i', $name, $matches)) {
            $port = (int) $matches[1];
            $protocol = $matches[2];
            $hostname = $matches[3] ?? '';
        }

        return [
            'port' => $port,
            'protocol' => $protocol,
            'hostname' => $hostname,
            'usage' => isset($parts[0]) ? (int) $parts[0] : 3,
            'selector' => isset($parts[1]) ? (int) $parts[1] : 1,
            'matching_type' => isset($parts[2]) ? (int) $parts[2] : 1,
            'cert_data' => $parts[3] ?? '',
            'ttl' => $recordData['ttl'] ?? $this->getDefaultTTL(),
        ];
    }

    /**
     * Clean hexadecimal data (remove whitespace, colons, etc.)
     *
     * @param string $hex Hexadecimal string
     * @return string Cleaned hex string
     */
    private function cleanHexData(string $hex): string
    {
        // Remove all non-hex characters
        return preg_replace('/[^0-9a-fA-F]/', '', $hex);
    }
}
