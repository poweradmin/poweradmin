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

use Poweradmin\Domain\Service\DnsValidation\SPFRecordValidator;
use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;

/**
 * SPF Wizard
 *
 * Wizard for creating SPF (Sender Policy Framework) records.
 * SPF records are TXT records that specify which hosts are authorized to send
 * email on behalf of a domain.
 *
 * Includes DNS lookup limit warning per RFC 7208 Section 4.6.4.
 *
 * @package Poweradmin\Domain\Service\DnsWizard
 */
class SPFWizard extends AbstractDnsWizard
{
    private SPFRecordValidator $validator;

    public function __construct(ConfigurationInterface $config)
    {
        parent::__construct($config);
        $this->recordType = 'TXT';
        $this->wizardType = 'SPF';
        $this->displayName = _('SPF Record');
        $this->description = _('Authorized mail server configuration');
        $this->supportsTwoModes = false;
        $this->validator = new SPFRecordValidator($config);
    }

    /**
     * {@inheritdoc}
     */
    public function getFormSchema(): array
    {
        return [
            'sections' => [
                [
                    'title' => _('Basic Mechanisms'),
                    'description' => _('Select common mechanisms to authorize email sending'),
                    'fields' => [
                        [
                            'name' => 'use_mx',
                            'label' => _('MX Records'),
                            'type' => 'checkbox',
                            'default' => true,
                            'help' => _('Authorize all servers listed in MX records for this domain')
                        ],
                        [
                            'name' => 'use_a',
                            'label' => _('A/AAAA Records'),
                            'type' => 'checkbox',
                            'default' => true,
                            'help' => _('Authorize the domain\'s A and AAAA records')
                        ],
                    ]
                ],
                [
                    'title' => _('IP Addresses'),
                    'description' => _('Authorized IP addresses and networks (CIDR notation supported)'),
                    'fields' => [
                        [
                            'name' => 'ip4',
                            'label' => _('IPv4 Addresses'),
                            'type' => 'textarea',
                            'rows' => 3,
                            'placeholder' => "192.0.2.1\n192.0.2.0/24",
                            'help' => _('One IP address or network per line (e.g., 192.0.2.1 or 192.0.2.0/24)')
                        ],
                        [
                            'name' => 'ip6',
                            'label' => _('IPv6 Addresses'),
                            'type' => 'textarea',
                            'rows' => 3,
                            'placeholder' => "2001:db8::1\n2001:db8::/32",
                            'help' => _('One IPv6 address or network per line')
                        ],
                    ]
                ],
                [
                    'title' => _('Include Mechanisms'),
                    'description' => _('Include SPF records from other domains'),
                    'fields' => [
                        [
                            'name' => 'includes',
                            'label' => _('Include Domains'),
                            'type' => 'textarea',
                            'rows' => 3,
                            'placeholder' => "_spf.example.com\n_spf.google.com",
                            'help' => _('Domains whose SPF records should be included (one per line). Common: _spf.google.com, spf.protection.outlook.com')
                        ],
                    ]
                ],
                [
                    'title' => _('Additional Mechanisms'),
                    'fields' => [
                        [
                            'name' => 'a_hosts',
                            'label' => _('Additional A/AAAA Hosts'),
                            'type' => 'textarea',
                            'rows' => 2,
                            'placeholder' => "mail.example.com\nsmtp.example.com",
                            'help' => _('Authorize specific hostnames\' A/AAAA records (one per line)')
                        ],
                        [
                            'name' => 'mx_hosts',
                            'label' => _('Additional MX Hosts'),
                            'type' => 'textarea',
                            'rows' => 2,
                            'placeholder' => "example.org",
                            'help' => _('Authorize MX records from other domains (one per line)')
                        ],
                    ]
                ],
                [
                    'title' => _('Policy'),
                    'fields' => [
                        [
                            'name' => 'all',
                            'label' => _('Default Action'),
                            'type' => 'radio',
                            'required' => true,
                            'default' => 'softfail',
                            'options' => [
                                ['value' => 'pass', 'label' => '+all', 'description' => _('Pass all - allows anyone to send (insecure)')],
                                ['value' => 'neutral', 'label' => '?all', 'description' => _('Neutral (no policy)')],
                                ['value' => 'softfail', 'label' => '~all', 'description' => _('Soft fail (mark as spam but accept)')],
                                ['value' => 'fail', 'label' => '-all', 'description' => _('Fail (reject unauthorized emails)')],
                            ],
                            'help' => _('Policy for emails not matching any mechanism. Start with ~all (softfail) for testing.')
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
        $mechanisms = [];

        // MX mechanism
        if (!empty($formData['use_mx'])) {
            $mechanisms[] = 'mx';
        }

        // A mechanism
        if (!empty($formData['use_a'])) {
            $mechanisms[] = 'a';
        }

        // IPv4 addresses
        if (!empty($formData['ip4'])) {
            $ips = array_filter(array_map('trim', explode("\n", $formData['ip4'])));
            foreach ($ips as $ip) {
                $mechanisms[] = "ip4:{$ip}";
            }
        }

        // IPv6 addresses
        if (!empty($formData['ip6'])) {
            $ips = array_filter(array_map('trim', explode("\n", $formData['ip6'])));
            foreach ($ips as $ip) {
                $mechanisms[] = "ip6:{$ip}";
            }
        }

        // Include mechanisms
        if (!empty($formData['includes'])) {
            $includes = array_filter(array_map('trim', explode("\n", $formData['includes'])));
            foreach ($includes as $include) {
                $mechanisms[] = "include:{$include}";
            }
        }

        // Additional A hosts
        if (!empty($formData['a_hosts'])) {
            $hosts = array_filter(array_map('trim', explode("\n", $formData['a_hosts'])));
            foreach ($hosts as $host) {
                $mechanisms[] = "a:{$host}";
            }
        }

        // Additional MX hosts
        if (!empty($formData['mx_hosts'])) {
            $hosts = array_filter(array_map('trim', explode("\n", $formData['mx_hosts'])));
            foreach ($hosts as $host) {
                $mechanisms[] = "mx:{$host}";
            }
        }

        // All mechanism with qualifier
        $allQualifier = $this->getQualifierPrefix($formData['all'] ?? 'softfail');
        $mechanisms[] = "{$allQualifier}all";

        // Build SPF record
        $content = 'v=spf1 ' . implode(' ', $mechanisms);

        return [
            'name' => '@',
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

        // Count DNS lookups (RFC 7208 Section 4.6.4 - max 10)
        $dnsLookupCount = 0;

        // MX mechanism causes 1 DNS lookup
        if (!empty($formData['use_mx'])) {
            $dnsLookupCount++;
        }

        // A mechanism causes 1 DNS lookup
        if (!empty($formData['use_a'])) {
            $dnsLookupCount++;
        }

        // Each include causes 1 DNS lookup
        if (!empty($formData['includes'])) {
            $includes = array_filter(array_map('trim', explode("\n", $formData['includes'])));
            $dnsLookupCount += count($includes);
        }

        // Each a:host causes 1 DNS lookup
        if (!empty($formData['a_hosts'])) {
            $hosts = array_filter(array_map('trim', explode("\n", $formData['a_hosts'])));
            $dnsLookupCount += count($hosts);
        }

        // Each mx:host causes 1 DNS lookup
        if (!empty($formData['mx_hosts'])) {
            $hosts = array_filter(array_map('trim', explode("\n", $formData['mx_hosts'])));
            $dnsLookupCount += count($hosts);
        }

        // **CRITICAL: DNS Lookup Limit Warning** (RFC 7208 Section 4.6.4)
        if ($dnsLookupCount > 10) {
            $errors[] = sprintf(
                _('SPF record exceeds the DNS lookup limit (%d/10). This will cause SPF validation to fail. Remove some include/a/mx mechanisms or use ip4/ip6 instead.'),
                $dnsLookupCount
            );
        } elseif ($dnsLookupCount > 7) {
            $warnings[] = sprintf(
                _('SPF record has %d DNS lookups (limit is 10). Consider using ip4/ip6 instead of include mechanisms, or consolidate includes to stay under the limit.'),
                $dnsLookupCount
            );
        }

        // Validate IP addresses
        if (!empty($formData['ip4'])) {
            $ips = array_filter(array_map('trim', explode("\n", $formData['ip4'])));
            foreach ($ips as $ip) {
                if (!$this->isValidIpv4($ip)) {
                    $errors[] = sprintf(_('Invalid IPv4 address or network: %s'), $ip);
                }
            }
        }

        if (!empty($formData['ip6'])) {
            $ips = array_filter(array_map('trim', explode("\n", $formData['ip6'])));
            foreach ($ips as $ip) {
                if (!$this->isValidIpv6($ip)) {
                    $errors[] = sprintf(_('Invalid IPv6 address or network: %s'), $ip);
                }
            }
        }

        // Validate domains
        if (!empty($formData['includes'])) {
            $includes = array_filter(array_map('trim', explode("\n", $formData['includes'])));
            foreach ($includes as $include) {
                if (!$this->isValidDomain($include)) {
                    $errors[] = sprintf(_('Invalid include domain: %s'), $include);
                }
            }
        }

        // Validate TTL
        if (isset($formData['ttl'])) {
            $ttlValidation = $this->validateTTL($formData['ttl']);
            if (!$ttlValidation['valid']) {
                $errors = array_merge($errors, $ttlValidation['errors']);
            }
        }

        // Warn if no mechanisms selected
        $hasMechanisms = !empty($formData['use_mx']) || !empty($formData['use_a']) ||
                        !empty($formData['ip4']) || !empty($formData['ip6']) ||
                        !empty($formData['includes']) || !empty($formData['a_hosts']) ||
                        !empty($formData['mx_hosts']);

        if (!$hasMechanisms) {
            $warnings[] = _('No mechanisms selected. This SPF record will only contain the default action.');
        }

        // Warn about +all
        if (($formData['all'] ?? '') === 'pass') {
            $warnings[] = _('Using "+all" allows anyone to send email as your domain. This is strongly discouraged.');
        }

        return $this->createValidationResult(empty($errors), $errors, $warnings);
    }

    /**
     * {@inheritdoc}
     */
    public function parseExistingRecord(string $content, array $recordData = []): array
    {
        // Remove quotes and v=spf1
        $content = trim($content, '"');
        $content = preg_replace('/^v=spf1\s+/i', '', $content);

        $formData = [
            'use_mx' => false,
            'use_a' => false,
            'ip4' => '',
            'ip6' => '',
            'includes' => '',
            'a_hosts' => '',
            'mx_hosts' => '',
            'all' => 'softfail',
            'ttl' => $recordData['ttl'] ?? $this->getDefaultTTL(),
        ];

        $ip4List = [];
        $ip6List = [];
        $includeList = [];
        $aHostList = [];
        $mxHostList = [];

        // Parse mechanisms
        $parts = preg_split('/\s+/', $content);
        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) {
                continue;
            }

            // Extract qualifier
            $qualifier = '+';
            if (in_array($part[0], ['+', '-', '~', '?'])) {
                $qualifier = $part[0];
                $part = substr($part, 1);
            }

            if ($part === 'mx') {
                $formData['use_mx'] = true;
            } elseif ($part === 'a') {
                $formData['use_a'] = true;
            } elseif (str_starts_with($part, 'ip4:')) {
                $ip4List[] = substr($part, 4);
            } elseif (str_starts_with($part, 'ip6:')) {
                $ip6List[] = substr($part, 4);
            } elseif (str_starts_with($part, 'include:')) {
                $includeList[] = substr($part, 8);
            } elseif (str_starts_with($part, 'a:')) {
                $aHostList[] = substr($part, 2);
            } elseif (str_starts_with($part, 'mx:')) {
                $mxHostList[] = substr($part, 3);
            } elseif ($part === 'all') {
                $formData['all'] = $this->getQualifierName($qualifier);
            }
        }

        $formData['ip4'] = implode("\n", $ip4List);
        $formData['ip6'] = implode("\n", $ip6List);
        $formData['includes'] = implode("\n", $includeList);
        $formData['a_hosts'] = implode("\n", $aHostList);
        $formData['mx_hosts'] = implode("\n", $mxHostList);

        return $formData;
    }

    /**
     * Get qualifier prefix for SPF mechanism
     *
     * @param string $qualifierName Qualifier name (pass, neutral, softfail, fail)
     * @return string Qualifier prefix (+, ?, ~, -)
     */
    private function getQualifierPrefix(string $qualifierName): string
    {
        return match ($qualifierName) {
            'pass' => '+',
            'neutral' => '?',
            'softfail' => '~',
            'fail' => '-',
            default => '~'
        };
    }

    /**
     * Get qualifier name from prefix
     *
     * @param string $prefix Qualifier prefix
     * @return string Qualifier name
     */
    private function getQualifierName(string $prefix): string
    {
        return match ($prefix) {
            '+' => 'pass',
            '?' => 'neutral',
            '~' => 'softfail',
            '-' => 'fail',
            default => 'softfail'
        };
    }

    /**
     * Validate IPv4 address or network
     *
     * @param string $ip IPv4 address or CIDR
     * @return bool True if valid
     */
    private function isValidIpv4(string $ip): bool
    {
        // Check if it includes CIDR notation
        if (str_contains($ip, '/')) {
            list($addr, $mask) = explode('/', $ip, 2);
            return filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false &&
                   is_numeric($mask) && $mask >= 0 && $mask <= 32;
        }
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    /**
     * Validate IPv6 address or network
     *
     * @param string $ip IPv6 address or CIDR
     * @return bool True if valid
     */
    private function isValidIpv6(string $ip): bool
    {
        // Check if it includes CIDR notation
        if (str_contains($ip, '/')) {
            list($addr, $mask) = explode('/', $ip, 2);
            return filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false &&
                   is_numeric($mask) && $mask >= 0 && $mask <= 128;
        }
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    }
}
