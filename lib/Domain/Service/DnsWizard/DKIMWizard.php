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
 * DKIM Wizard
 *
 * Wizard for creating DKIM (DomainKeys Identified Mail) DNS records.
 * DKIM records are TXT records published at <selector>._domainkey.<domain>
 * that contain the public key for email signature verification.
 *
 * NOTE: This wizard manages DNS records only. DKIM keys are generated
 * on mail servers (Postfix, Exim, etc.), not in PowerDNS.
 *
 * @package Poweradmin\Domain\Service\DnsWizard
 */
class DKIMWizard extends AbstractDnsWizard
{
    public function __construct(ConfigurationInterface $config)
    {
        parent::__construct($config);
        $this->recordType = 'TXT';
        $this->wizardType = 'DKIM';
        $this->displayName = _('DKIM Record');
        $this->description = _('Email signature verification public key');
    }

    /**
     * {@inheritdoc}
     */
    public function getFormSchema(): array
    {
        return [
            'sections' => [
                [
                    'title' => _('Information'),
                    'type' => 'info',
                    'content' => _('DKIM proves emails are from your domain using cryptographic signatures. Generate the DKIM key pair on your MAIL SERVER (Postfix, Exim, etc.). Only the PUBLIC KEY goes in this DNS record. Keep the PRIVATE KEY secure on your mail server (never share it).')
                ],
                [
                    'title' => _('DKIM Configuration'),
                    'fields' => [
                        [
                            'name' => 'selector',
                            'label' => _('Selector'),
                            'type' => 'text',
                            'required' => true,
                            'placeholder' => 'default',
                            'pattern' => '[a-zA-Z0-9_-]+',
                            'help' => _('Selector to identify the DKIM key (commonly "default", timestamp like "202501", or service name like "google"). Only alphanumeric characters, hyphens, and underscores allowed.')
                        ],
                        [
                            'name' => 'public_key',
                            'label' => _('Public Key'),
                            'type' => 'textarea',
                            'required' => true,
                            'rows' => 6,
                            'placeholder' => 'MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQC...',
                            'help' => _('Paste the base64-encoded public key from your mail server. Spaces and newlines will be automatically removed.')
                        ],
                        [
                            'name' => 'key_type',
                            'label' => _('Key Type'),
                            'type' => 'radio',
                            'required' => false,
                            'default' => 'rsa',
                            'options' => [
                                ['value' => 'rsa', 'label' => 'RSA', 'description' => _('Widely supported by all mail servers')],
                                ['value' => 'ed25519', 'label' => 'Ed25519', 'description' => _('Newer algorithm - not universally supported yet')],
                            ],
                            'help' => _('Encryption algorithm for the DKIM public key')
                        ],
                    ]
                ],
                [
                    'title' => _('Advanced Options'),
                    'collapsible' => true,
                    'collapsed' => true,
                    'fields' => [
                        [
                            'name' => 'testing_mode',
                            'label' => _('Testing Mode'),
                            'type' => 'checkbox',
                            'default' => false,
                            'help' => _('Enable testing mode (t=y). DKIM signatures will be present but not enforced. Use during initial deployment.')
                        ],
                        [
                            'name' => 'strict_subdomain',
                            'label' => _('Strict Subdomain Mode'),
                            'type' => 'checkbox',
                            'default' => false,
                            'help' => _('Enable strict subdomain mode (t=s). Rejects unsigned mail from subdomains.')
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
        $selector = $this->sanitizeInput($formData['selector'] ?? 'default');
        $publicKey = $this->cleanPublicKey($formData['public_key'] ?? '');
        $keyType = $formData['key_type'] ?? 'rsa';

        // Build DKIM record content
        $tags = [];

        // Version (optional, v=DKIM1 is default)
        $tags[] = 'v=DKIM1';

        // Key type (optional if RSA)
        if ($keyType === 'ed25519') {
            $tags[] = 'k=ed25519';
        }

        // Testing and strict subdomain flags (RFC 6376 allows only one t= tag with colon-separated values)
        $tFlags = [];
        if (!empty($formData['testing_mode'])) {
            $tFlags[] = 'y';
        }
        if (!empty($formData['strict_subdomain'])) {
            $tFlags[] = 's';
        }
        if (!empty($tFlags)) {
            $tags[] = 't=' . implode(':', $tFlags);
        }

        // Public key (required)
        $tags[] = "p={$publicKey}";

        // Build final content
        $content = implode('; ', $tags);

        return [
            'name' => "{$selector}._domainkey",
            'type' => 'TXT',
            'content' => '"' . $content . '"',
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

        // Validate selector (required)
        if (empty($formData['selector'])) {
            $errors[] = _('Selector is required');
        } elseif (!preg_match('/^[a-zA-Z0-9_-]+$/', $formData['selector'])) {
            $errors[] = _('Selector can only contain alphanumeric characters, hyphens, and underscores');
        }

        // Validate public key (required)
        if (empty($formData['public_key'])) {
            $errors[] = _('Public key is required');
        } else {
            $cleanKey = $this->cleanPublicKey($formData['public_key']);

            // Check if it's valid base64
            if (!$this->isValidBase64($cleanKey)) {
                $errors[] = _('Public key must be valid base64-encoded data');
            }

            // Check minimum length (RSA 2048-bit public key is ~350 chars base64)
            if (strlen($cleanKey) < 200) {
                $warnings[] = _('Public key seems unusually short. Ensure you copied the complete key from your mail server.');
            }

            // Check for common mistakes
            if (str_contains($formData['public_key'], 'PRIVATE KEY')) {
                $errors[] = _('You pasted a PRIVATE KEY. Only the PUBLIC KEY should be in DNS. Never share your private key!');
            }

            if (str_contains($formData['public_key'], 'BEGIN') || str_contains($formData['public_key'], 'END')) {
                $warnings[] = _('Public key appears to contain PEM headers (BEGIN/END). Only the base64 content between headers is needed.');
            }
        }

        // Validate TTL
        if (isset($formData['ttl'])) {
            $ttlValidation = $this->validateTTL($formData['ttl']);
            if (!$ttlValidation['valid']) {
                $errors = array_merge($errors, $ttlValidation['errors']);
            }
        }

        // Warn about testing mode
        if (!empty($formData['testing_mode'])) {
            $warnings[] = _('Testing mode is enabled (t=y). DKIM signatures will not be enforced. Disable this once testing is complete.');
        }

        return $this->createValidationResult(empty($errors), $errors, $warnings);
    }

    /**
     * {@inheritdoc}
     */
    public function parseExistingRecord(string $content, array $recordData = []): array
    {
        // Extract selector from record name (selector._domainkey.domain.com)
        $name = $recordData['name'] ?? '';
        $selector = 'default';
        if (str_contains($name, '._domainkey')) {
            $selector = explode('.', $name)[0];
        }

        // Remove quotes
        $content = trim($content, '"');

        // Parse DKIM tags
        $tags = $this->parseDKIMTags($content);

        return [
            'selector' => $selector,
            'public_key' => $tags['p'] ?? '',
            'key_type' => $tags['k'] ?? 'rsa',
            'testing_mode' => isset($tags['t']) && str_contains($tags['t'], 'y'),
            'strict_subdomain' => isset($tags['t']) && str_contains($tags['t'], 's'),
            'ttl' => $recordData['ttl'] ?? $this->getDefaultTTL(),
        ];
    }

    /**
     * Clean public key (remove whitespace, newlines, PEM headers)
     *
     * @param string $key Public key string
     * @return string Cleaned key
     */
    private function cleanPublicKey(string $key): string
    {
        // Remove PEM headers/footers if present
        $key = preg_replace('/-----(BEGIN|END) [A-Z ]+-----/', '', $key);

        // Remove all whitespace, newlines, tabs
        $key = preg_replace('/\s+/', '', $key);

        return trim($key);
    }

    /**
     * Validate base64 string
     *
     * @param string $str String to validate
     * @return bool True if valid base64
     */
    private function isValidBase64(string $str): bool
    {
        if (empty($str)) {
            return false;
        }

        // Check if it's valid base64
        return base64_encode(base64_decode($str, true)) === $str;
    }

    /**
     * Parse DKIM record into tag-value pairs
     *
     * @param string $content DKIM record content
     * @return array Associative array of tag-value pairs
     */
    private function parseDKIMTags(string $content): array
    {
        $tags = [];

        // Split by semicolons
        $parts = explode(';', $content);

        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) {
                continue;
            }

            // Split into tag=value
            if (preg_match('/^([a-zA-Z0-9]+)=(.*)$/', $part, $matches)) {
                $tag = strtolower($matches[1]);
                $value = trim($matches[2]);
                $tags[$tag] = $value;
            }
        }

        return $tags;
    }
}
