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

use Poweradmin\Domain\Service\DnsValidation\DMARCRecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;

/**
 * DMARC Wizard
 *
 * Wizard for creating DMARC (Domain-based Message Authentication, Reporting, and Conformance) records.
 * DMARC records are TXT records published at _dmarc.<domain> that specify policies for handling
 * emails that fail SPF and/or DKIM authentication.
 *
 * @package Poweradmin\Domain\Service\DnsWizard
 */
class DMARCWizard extends AbstractDnsWizard
{
    private DMARCRecordValidator $validator;

    public function __construct(ConfigurationInterface $config)
    {
        parent::__construct($config);
        $this->recordType = 'TXT';
        $this->wizardType = 'DMARC';
        $this->displayName = _('DMARC Record');
        $this->description = _('Email authentication policy and reporting configuration');
        $this->validator = new DMARCRecordValidator($config);
    }

    /**
     * {@inheritdoc}
     */
    public function getFormSchema(): array
    {
        return [
            'sections' => [
                [
                    'title' => _('Policy Settings'),
                    'fields' => [
                        [
                            'name' => 'policy',
                            'label' => _('Policy'),
                            'type' => 'radio',
                            'required' => true,
                            'default' => 'none',
                            'options' => [
                                ['value' => 'none', 'label' => _('None (monitor only)'), 'description' => _('No action taken, just collect reports for analysis')],
                                ['value' => 'quarantine', 'label' => _('Quarantine'), 'description' => _('Treat suspicious emails as spam/junk')],
                                ['value' => 'reject', 'label' => _('Reject'), 'description' => _('Block delivery of suspicious emails entirely')],
                            ],
                            'help' => _('Start with "none" to collect data before enforcing a stricter policy.')
                        ],
                        [
                            'name' => 'subdomain_policy',
                            'label' => _('Subdomain Policy'),
                            'type' => 'radio',
                            'required' => false,
                            'default' => '',
                            'options' => [
                                ['value' => '', 'label' => _('Same as domain policy'), 'description' => _('Use the same policy as the main domain')],
                                ['value' => 'none', 'label' => _('None'), 'description' => _('Monitor only for subdomains')],
                                ['value' => 'quarantine', 'label' => _('Quarantine'), 'description' => _('Quarantine suspicious subdomain emails')],
                                ['value' => 'reject', 'label' => _('Reject'), 'description' => _('Reject suspicious subdomain emails')],
                            ],
                            'help' => _('Policy for emails from subdomains (e.g., mail.example.com)')
                        ],
                        [
                            'name' => 'percentage',
                            'label' => _('Policy Application Percentage'),
                            'type' => 'number',
                            'required' => false,
                            'default' => 100,
                            'min' => 0,
                            'max' => 100,
                            'help' => _('Percentage of messages to which the policy should apply (100 = all messages). Use lower values during policy rollout.')
                        ],
                    ]
                ],
                [
                    'title' => _('Reporting Settings'),
                    'fields' => [
                        [
                            'name' => 'rua',
                            'label' => _('Aggregate Report Email'),
                            'type' => 'text',
                            'required' => false,
                            'placeholder' => 'dmarc-reports@example.com',
                            'help' => _('Email address to receive daily aggregate reports')
                        ],
                        [
                            'name' => 'ruf',
                            'label' => _('Forensic Report Email'),
                            'type' => 'text',
                            'required' => false,
                            'placeholder' => 'dmarc-forensic@example.com',
                            'help' => _('Email address to receive detailed failure reports (optional, can be high volume)')
                        ],
                        [
                            'name' => 'fo',
                            'label' => _('Forensic Reporting Options'),
                            'type' => 'checkbox_group',
                            'required' => false,
                            'options' => [
                                ['value' => '0', 'label' => _('Generate reports if all authentication mechanisms fail')],
                                ['value' => '1', 'label' => _('Generate reports if any authentication mechanism fails')],
                                ['value' => 'd', 'label' => _('Generate reports if DKIM check fails')],
                                ['value' => 's', 'label' => _('Generate reports if SPF check fails')],
                            ],
                            'help' => _('When to generate forensic reports (only applies if forensic email is set)')
                        ],
                    ]
                ],
                [
                    'title' => _('Alignment Settings'),
                    'description' => _('Advanced settings for SPF and DKIM alignment modes'),
                    'fields' => [
                        [
                            'name' => 'adkim',
                            'label' => _('DKIM Alignment Mode'),
                            'type' => 'radio',
                            'required' => false,
                            'default' => 'r',
                            'options' => [
                                ['value' => 'r', 'label' => _('Relaxed'), 'description' => _('DKIM domain can be subdomain of From: domain')],
                                ['value' => 's', 'label' => _('Strict'), 'description' => _('DKIM domain must exactly match From: domain')],
                            ],
                        ],
                        [
                            'name' => 'aspf',
                            'label' => _('SPF Alignment Mode'),
                            'type' => 'radio',
                            'required' => false,
                            'default' => 'r',
                            'options' => [
                                ['value' => 'r', 'label' => _('Relaxed'), 'description' => _('SPF domain can be subdomain of From: domain')],
                                ['value' => 's', 'label' => _('Strict'), 'description' => _('SPF domain must exactly match From: domain')],
                            ],
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
        // Build DMARC record content
        $tags = ['v=DMARC1'];

        // Policy (required)
        $policy = $formData['policy'] ?? 'none';
        $tags[] = "p={$policy}";

        // Subdomain policy (optional)
        if (!empty($formData['subdomain_policy'])) {
            $tags[] = "sp={$formData['subdomain_policy']}";
        }

        // Percentage (optional, default 100)
        $percentage = $formData['percentage'] ?? 100;
        if ($percentage < 100) {
            $tags[] = "pct={$percentage}";
        }

        // Aggregate reporting (optional)
        if (!empty($formData['rua'])) {
            $rua = $this->sanitizeInput($formData['rua']);
            if (!str_starts_with($rua, 'mailto:')) {
                $rua = "mailto:{$rua}";
            }
            $tags[] = "rua={$rua}";
        }

        // Forensic reporting (optional)
        if (!empty($formData['ruf'])) {
            $ruf = $this->sanitizeInput($formData['ruf']);
            if (!str_starts_with($ruf, 'mailto:')) {
                $ruf = "mailto:{$ruf}";
            }
            $tags[] = "ruf={$ruf}";
        }

        // Forensic reporting options (optional)
        if (!empty($formData['fo'])) {
            $fo = is_array($formData['fo']) ? implode(':', $formData['fo']) : $formData['fo'];
            $tags[] = "fo={$fo}";
        }

        // DKIM alignment (optional, default relaxed)
        $adkim = $formData['adkim'] ?? 'r';
        if ($adkim === 's') {
            $tags[] = "adkim=s";
        }

        // SPF alignment (optional, default relaxed)
        $aspf = $formData['aspf'] ?? 'r';
        if ($aspf === 's') {
            $tags[] = "aspf=s";
        }

        // Build final content
        $content = implode('; ', $tags);

        return [
            'name' => '_dmarc',
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

        // Validate policy (required)
        if (empty($formData['policy'])) {
            $errors[] = _('Policy is required');
        } elseif (!in_array($formData['policy'], ['none', 'quarantine', 'reject'])) {
            $errors[] = _('Invalid policy value');
        }

        // Validate subdomain policy if provided
        if (!empty($formData['subdomain_policy']) && !in_array($formData['subdomain_policy'], ['none', 'quarantine', 'reject'])) {
            $errors[] = _('Invalid subdomain policy value');
        }

        // Validate percentage
        if (isset($formData['percentage'])) {
            $pct = (int) $formData['percentage'];
            if ($pct < 0 || $pct > 100) {
                $errors[] = _('Percentage must be between 0 and 100');
            } elseif ($pct < 100) {
                $warnings[] = sprintf(_('Policy will only apply to %d%% of messages. Use 100%% once testing is complete.'), $pct);
            }
        }

        // Validate email addresses
        if (!empty($formData['rua']) && !$this->isValidEmail($formData['rua'])) {
            $errors[] = _('Invalid aggregate report email address');
        }

        if (!empty($formData['ruf']) && !$this->isValidEmail($formData['ruf'])) {
            $errors[] = _('Invalid forensic report email address');
        }

        // Warn if no reporting configured
        if (empty($formData['rua']) && empty($formData['ruf'])) {
            $warnings[] = _('No reporting email addresses configured. You will not receive DMARC reports.');
        }

        // Validate TTL
        if (isset($formData['ttl'])) {
            $ttlValidation = $this->validateTTL($formData['ttl']);
            if (!$ttlValidation['valid']) {
                $errors = array_merge($errors, $ttlValidation['errors']);
            }
        }

        // Warn about policy=none
        if (($formData['policy'] ?? 'none') === 'none') {
            $warnings[] = _('Using "p=none" provides monitoring only. Consider stricter policy (quarantine/reject) once you\'ve reviewed reports.');
        }

        return $this->createValidationResult(empty($errors), $errors, $warnings);
    }

    /**
     * {@inheritdoc}
     */
    public function parseExistingRecord(string $content, array $recordData = []): array
    {
        // Remove quotes if present
        $content = trim($content, '"');

        // Parse DMARC tags
        $tags = $this->parseDMARCTags($content);

        return [
            'policy' => $tags['p'] ?? 'none',
            'subdomain_policy' => $tags['sp'] ?? '',
            'percentage' => isset($tags['pct']) ? (int) $tags['pct'] : 100,
            'rua' => isset($tags['rua']) ? str_replace('mailto:', '', $tags['rua']) : '',
            'ruf' => isset($tags['ruf']) ? str_replace('mailto:', '', $tags['ruf']) : '',
            'fo' => isset($tags['fo']) ? explode(':', $tags['fo']) : [],
            'adkim' => $tags['adkim'] ?? 'r',
            'aspf' => $tags['aspf'] ?? 'r',
            'ttl' => $recordData['ttl'] ?? $this->getDefaultTTL(),
        ];
    }

    /**
     * Parse DMARC record into tag-value pairs
     *
     * @param string $content DMARC record content
     * @return array Associative array of tag-value pairs
     */
    private function parseDMARCTags(string $content): array
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
