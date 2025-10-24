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
 * CAA Wizard
 *
 * Wizard for creating CAA (Certification Authority Authorization) records.
 * CAA records specify which certificate authorities are authorized to issue
 * SSL/TLS certificates for a domain.
 *
 * @package Poweradmin\Domain\Service\DnsWizard
 */
class CAAWizard extends AbstractDnsWizard
{
    public function __construct(ConfigurationInterface $config)
    {
        parent::__construct($config);
        $this->recordType = 'CAA';
        $this->wizardType = 'CAA';
        $this->displayName = _('CAA Record');
        $this->description = _('Certificate authority authorization');
        $this->supportsTwoModes = false;
    }

    /**
     * {@inheritdoc}
     */
    public function getFormSchema(): array
    {
        // Get CA providers from configuration
        $wizardConfig = $this->config->getGroup('dns_wizards');
        $caProviders = $wizardConfig['caa_providers'] ?? [];

        // Build provider options for dropdown
        $providerOptions = [];
        foreach ($caProviders as $domain => $name) {
            $providerOptions[] = [
                'value' => $domain,
                'label' => $name
            ];
        }

        // Add custom option
        $providerOptions[] = [
            'value' => 'custom',
            'label' => _('Custom (enter manually)')
        ];

        return [
            'sections' => [
                [
                    'title' => _('Certificate Authority'),
                    'fields' => [
                        [
                            'name' => 'ca_provider',
                            'label' => _('CA Provider'),
                            'type' => 'select',
                            'required' => true,
                            'default' => 'letsencrypt.org',
                            'options' => $providerOptions,
                            'help' => _('Select the certificate authority authorized to issue certificates for this domain')
                        ],
                        [
                            'name' => 'ca_domain',
                            'label' => _('CA Domain (Custom)'),
                            'type' => 'text',
                            'required' => false,
                            'placeholder' => 'ca.example.com',
                            'visible_when' => ['ca_provider', '=', 'custom'],
                            'help' => _('Enter the CA domain name manually (only shown when "Custom" is selected)')
                        ],
                    ]
                ],
                [
                    'title' => _('Certificate Type'),
                    'fields' => [
                        [
                            'name' => 'tag',
                            'label' => _('Tag'),
                            'type' => 'radio',
                            'required' => true,
                            'default' => 'issue',
                            'options' => [
                                ['value' => 'issue', 'label' => _('Issue'), 'description' => _('Authorize issuance of standard certificates')],
                                ['value' => 'issuewild', 'label' => _('Issue Wildcard'), 'description' => _('Authorize issuance of wildcard certificates (*.example.com)')],
                                ['value' => 'iodef', 'label' => _('Incident Reporting'), 'description' => _('URL to report certificate issuance violations')],
                            ],
                            'help' => _('Type of authorization or reporting')
                        ],
                        [
                            'name' => 'iodef_url',
                            'label' => _('Reporting URL'),
                            'type' => 'text',
                            'required' => false,
                            'placeholder' => 'mailto:security@example.com or https://example.com/ca-report',
                            'visible_when' => ['tag', '=', 'iodef'],
                            'help' => _('URL or email for incident reports (mailto: or https:)')
                        ],
                    ]
                ],
                [
                    'title' => _('Advanced Options'),
                    'collapsible' => true,
                    'collapsed' => true,
                    'fields' => [
                        [
                            'name' => 'flags',
                            'label' => _('Flags'),
                            'type' => 'select',
                            'required' => false,
                            'default' => 0,
                            'options' => [
                                ['value' => 0, 'label' => _('0 - Non-critical (recommended)')],
                                ['value' => 128, 'label' => _('128 - Critical (reject if not understood)')],
                            ],
                            'help' => _('0 = non-critical (recommended). 128 = critical flag (CAA record must be processed, reject if not understood)')
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
        $flags = (int) ($formData['flags'] ?? 0);
        $tag = $formData['tag'] ?? 'issue';

        // Determine CA domain
        $caProvider = $formData['ca_provider'] ?? 'letsencrypt.org';
        if ($caProvider === 'custom') {
            $value = $this->sanitizeInput($formData['ca_domain'] ?? '');
        } elseif ($tag === 'iodef') {
            $value = $this->sanitizeInput($formData['iodef_url'] ?? '');
        } else {
            $value = $caProvider;
        }

        // Build CAA record content
        // Format: <flags> <tag> "<value>"
        $content = sprintf('%d %s "%s"', $flags, $tag, $value);

        return [
            'name' => '@',
            'type' => 'CAA',
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

        $tag = $formData['tag'] ?? 'issue';
        $caProvider = $formData['ca_provider'] ?? '';

        // Validate tag
        if (!in_array($tag, ['issue', 'issuewild', 'iodef'])) {
            $errors[] = _('Invalid CAA tag. Must be issue, issuewild, or iodef');
        }

        // Validate CA provider/domain
        if ($tag !== 'iodef') {
            if (empty($caProvider)) {
                $errors[] = _('CA provider is required');
            } elseif ($caProvider === 'custom') {
                if (empty($formData['ca_domain'])) {
                    $errors[] = _('Custom CA domain is required when "Custom" is selected');
                } elseif (!$this->isValidDomain($formData['ca_domain'])) {
                    $errors[] = _('Invalid CA domain format');
                }
            }

            // Warn about "allow all" (;)
            if ($caProvider === ';') {
                $warnings[] = _('Using ";" allows ALL certificate authorities to issue certificates. This defeats the purpose of CAA records.');
            }
        } else {
            // Validate iodef URL
            $iodefUrl = $formData['iodef_url'] ?? '';
            if (empty($iodefUrl)) {
                $errors[] = _('Reporting URL is required for iodef tag');
            } elseif (!str_starts_with($iodefUrl, 'mailto:') && !str_starts_with($iodefUrl, 'https://') && !str_starts_with($iodefUrl, 'http://')) {
                $errors[] = _('Reporting URL must start with mailto:, https://, or http://');
            }
        }

        // Validate flags
        $flags = (int) ($formData['flags'] ?? 0);
        if (!in_array($flags, [0, 128])) {
            $errors[] = _('Flags must be 0 or 128');
        }

        if ($flags === 128) {
            $warnings[] = _('Critical flag (128) is set. Systems that do not understand CAA records will reject operations. Use 0 unless you have a specific requirement.');
        }

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
        // Parse CAA record format: <flags> <tag> "<value>"
        if (preg_match('/^(\d+)\s+(\w+)\s+"(.+)"$/', $content, $matches)) {
            $flags = (int) $matches[1];
            $tag = $matches[2];
            $value = $matches[3];

            // Determine if it's a known CA or custom
            $wizardConfig = $this->config->getGroup('dns_wizards');
            $caProviders = array_keys($wizardConfig['caa_providers'] ?? []);

            $formData = [
                'flags' => $flags,
                'tag' => $tag,
                'ttl' => $recordData['ttl'] ?? $this->getDefaultTTL(),
            ];

            if ($tag === 'iodef') {
                $formData['ca_provider'] = 'letsencrypt.org'; // Default for iodef
                $formData['iodef_url'] = $value;
            } elseif (in_array($value, $caProviders)) {
                $formData['ca_provider'] = $value;
                $formData['ca_domain'] = '';
            } else {
                $formData['ca_provider'] = 'custom';
                $formData['ca_domain'] = $value;
            }

            return $formData;
        }

        // Default values if parsing fails
        return [
            'ca_provider' => 'letsencrypt.org',
            'ca_domain' => '',
            'tag' => 'issue',
            'iodef_url' => '',
            'flags' => 0,
            'ttl' => $recordData['ttl'] ?? $this->getDefaultTTL(),
        ];
    }
}
