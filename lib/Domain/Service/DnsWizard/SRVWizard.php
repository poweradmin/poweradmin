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
 * SRV Wizard
 *
 * Wizard for creating SRV (Service) records.
 * SRV records define the location (hostname and port) of servers for
 * specified services.
 *
 * @package Poweradmin\Domain\Service\DnsWizard
 */
class SRVWizard extends AbstractDnsWizard
{
    /**
     * Common service definitions with default ports
     */
    private const COMMON_SERVICES = [
        '_http' => ['port' => 80, 'label' => 'HTTP', 'protocol' => '_tcp'],
        '_https' => ['port' => 443, 'label' => 'HTTPS', 'protocol' => '_tcp'],
        '_smtp' => ['port' => 25, 'label' => 'SMTP (Email)', 'protocol' => '_tcp'],
        '_submission' => ['port' => 587, 'label' => 'SMTP Submission', 'protocol' => '_tcp'],
        '_imaps' => ['port' => 993, 'label' => 'IMAP over SSL', 'protocol' => '_tcp'],
        '_pop3s' => ['port' => 995, 'label' => 'POP3 over SSL', 'protocol' => '_tcp'],
        '_imap' => ['port' => 143, 'label' => 'IMAP', 'protocol' => '_tcp'],
        '_pop3' => ['port' => 110, 'label' => 'POP3', 'protocol' => '_tcp'],
        '_ldap' => ['port' => 389, 'label' => 'LDAP', 'protocol' => '_tcp'],
        '_ldaps' => ['port' => 636, 'label' => 'LDAP over SSL', 'protocol' => '_tcp'],
        '_xmpp-client' => ['port' => 5222, 'label' => 'XMPP Client', 'protocol' => '_tcp'],
        '_xmpp-server' => ['port' => 5269, 'label' => 'XMPP Server', 'protocol' => '_tcp'],
        '_sip' => ['port' => 5060, 'label' => 'SIP (VoIP)', 'protocol' => '_udp'],
        '_sips' => ['port' => 5061, 'label' => 'SIP over TLS', 'protocol' => '_tcp'],
        '_jabber' => ['port' => 5269, 'label' => 'Jabber', 'protocol' => '_tcp'],
        '_minecraft' => ['port' => 25565, 'label' => 'Minecraft', 'protocol' => '_tcp'],
        '_teamspeak' => ['port' => 9987, 'label' => 'TeamSpeak', 'protocol' => '_udp'],
        '_mumble' => ['port' => 64738, 'label' => 'Mumble', 'protocol' => '_tcp'],
        'custom' => ['port' => 0, 'label' => 'Custom Service', 'protocol' => '_tcp'],
    ];

    public function __construct(ConfigurationInterface $config)
    {
        parent::__construct($config);
        $this->recordType = 'SRV';
        $this->wizardType = 'SRV';
        $this->displayName = _('SRV Record');
        $this->description = _('Service location (hostname and port)');
        $this->supportsTwoModes = true;
    }

    /**
     * {@inheritdoc}
     */
    public function getFormSchema(): array
    {
        // Build service options
        $serviceOptions = [];
        foreach (self::COMMON_SERVICES as $service => $info) {
            $serviceOptions[] = [
                'value' => $service,
                'label' => $info['label'] . ' (' . $service . ')',
                'data-port' => $info['port'],
                'data-protocol' => $info['protocol']
            ];
        }

        return [
            'sections' => [
                [
                    'title' => _('Service Configuration'),
                    'fields' => [
                        [
                            'name' => 'service',
                            'label' => _('Service'),
                            'type' => 'select',
                            'required' => true,
                            'default' => '_https',
                            'options' => $serviceOptions,
                            'help' => _('Select a common service or choose "Custom" to enter manually')
                        ],
                        [
                            'name' => 'custom_service',
                            'label' => _('Custom Service Name'),
                            'type' => 'text',
                            'required' => false,
                            'placeholder' => '_myservice',
                            'visible_when' => ['service', '=', 'custom'],
                            'help' => _('Service name must start with underscore (e.g., _myservice)')
                        ],
                        [
                            'name' => 'protocol',
                            'label' => _('Protocol'),
                            'type' => 'radio',
                            'required' => true,
                            'default' => '_tcp',
                            'options' => [
                                ['value' => '_tcp', 'label' => 'TCP', 'description' => _('Transmission Control Protocol - most common')],
                                ['value' => '_udp', 'label' => 'UDP', 'description' => _('User Datagram Protocol - connectionless')],
                                ['value' => '_tls', 'label' => 'TLS', 'description' => _('Transport Layer Security')],
                                ['value' => '_sctp', 'label' => 'SCTP', 'description' => _('Stream Control Transmission Protocol')],
                            ],
                            'help' => _('Transport protocol for the service')
                        ],
                        [
                            'name' => 'domain',
                            'label' => _('Domain'),
                            'type' => 'text',
                            'required' => false,
                            'placeholder' => 'example.com',
                            'help' => _('Domain name (leave empty for zone apex @). Full record name will be: _service._protocol.domain')
                        ],
                    ]
                ],
                [
                    'title' => _('Target Server'),
                    'fields' => [
                        [
                            'name' => 'target',
                            'label' => _('Target Hostname'),
                            'type' => 'text',
                            'required' => true,
                            'placeholder' => 'server.example.com',
                            'help' => _('Hostname of the server providing this service (must be a hostname with an A/AAAA record, not an IP address)')
                        ],
                        [
                            'name' => 'port',
                            'label' => _('Port Number'),
                            'type' => 'number',
                            'required' => true,
                            'default' => 443,
                            'min' => 1,
                            'max' => 65535,
                            'help' => _('Port number where the service is listening (automatically filled for common services)')
                        ],
                    ]
                ],
                [
                    'title' => _('Priority & Load Balancing'),
                    'fields' => [
                        [
                            'name' => 'priority',
                            'label' => _('Priority'),
                            'type' => 'number',
                            'required' => true,
                            'default' => 10,
                            'min' => 0,
                            'max' => 65535,
                            'help' => _('Lower values have higher priority (10 is a good default). Clients try servers in priority order.')
                        ],
                        [
                            'name' => 'weight',
                            'label' => _('Weight'),
                            'type' => 'number',
                            'required' => true,
                            'default' => 5,
                            'min' => 0,
                            'max' => 65535,
                            'help' => _('For load balancing among servers with same priority. Higher weight = more traffic. 0 = no load balancing.')
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
        // Determine service name
        $service = $formData['service'] ?? '_https';
        if ($service === 'custom') {
            $serviceName = $formData['custom_service'] ?? '_custom';
            // Ensure it starts with underscore
            if (!str_starts_with($serviceName, '_')) {
                $serviceName = '_' . $serviceName;
            }
        } else {
            $serviceName = $service;
        }

        $protocol = $formData['protocol'] ?? '_tcp';
        $domain = $this->sanitizeInput($formData['domain'] ?? '');

        // Build record name: _service._protocol.domain
        if (empty($domain) || $domain === '@') {
            $recordName = "{$serviceName}.{$protocol}";
        } else {
            $recordName = "{$serviceName}.{$protocol}.{$domain}";
        }

        $priority = (int) ($formData['priority'] ?? 10);
        $weight = (int) ($formData['weight'] ?? 5);
        $port = (int) ($formData['port'] ?? 443);
        $target = $this->sanitizeInput($formData['target'] ?? '');

        // Ensure target ends with a dot (FQDN)
        if (!empty($target) && !str_ends_with($target, '.')) {
            $target .= '.';
        }

        // SRV record content: weight port target
        $content = sprintf('%d %d %s', $weight, $port, $target);

        return [
            'name' => $recordName,
            'type' => 'SRV',
            'content' => $content,
            'ttl' => (int) ($formData['ttl'] ?? $this->getDefaultTTL()),
            'prio' => $priority
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function validate(array $formData): array
    {
        $errors = [];
        $warnings = [];

        // Validate service
        $service = $formData['service'] ?? '';
        if (empty($service)) {
            $errors[] = _('Service is required');
        } elseif ($service === 'custom') {
            $customService = $formData['custom_service'] ?? '';
            if (empty($customService)) {
                $errors[] = _('Custom service name is required');
            } elseif (!preg_match('/^_?[a-z0-9-]+$/i', $customService)) {
                $errors[] = _('Service name must contain only alphanumeric characters and hyphens');
            }
        }

        // Validate target (required)
        if (empty($formData['target'])) {
            $errors[] = _('Target hostname is required');
        } else {
            $target = $formData['target'];
            if (!$this->isValidDomain(rtrim($target, '.'))) {
                $errors[] = _('Invalid target hostname format');
            }

            // Warn if target looks like IP address
            if (filter_var($target, FILTER_VALIDATE_IP)) {
                $errors[] = _('Target must be a hostname, not an IP address. Create an A or AAAA record for the hostname first.');
            }
        }

        // Validate port
        $port = (int) ($formData['port'] ?? 0);
        if ($port < 1 || $port > 65535) {
            $errors[] = _('Port must be between 1 and 65535');
        }

        // Validate priority
        $priority = (int) ($formData['priority'] ?? 0);
        if ($priority < 0 || $priority > 65535) {
            $errors[] = _('Priority must be between 0 and 65535');
        }

        if ($priority === 0) {
            $warnings[] = _('Priority 0 is valid but unusual. Most services use priority 10 or higher.');
        }

        // Validate weight
        $weight = (int) ($formData['weight'] ?? 0);
        if ($weight < 0 || $weight > 65535) {
            $errors[] = _('Weight must be between 0 and 65535');
        }

        // Validate domain if provided
        if (!empty($formData['domain']) && $formData['domain'] !== '@' && !$this->isValidDomain($formData['domain'])) {
            $errors[] = _('Invalid domain format');
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
        // Parse SRV content: weight port target
        $parts = preg_split('/\s+/', trim($content), 3);

        // Parse record name: _service._protocol.domain
        $name = $recordData['name'] ?? '';
        $service = '_https';
        $protocol = '_tcp';
        $domain = '';

        if (preg_match('/^(_[^.]+)\.(_[^.]+)(?:\.(.+))?$/i', $name, $matches)) {
            $service = $matches[1];
            $protocol = $matches[2];
            $domain = $matches[3] ?? '';
        }

        // Check if service is a known common service
        if (!isset(self::COMMON_SERVICES[$service])) {
            // Custom service
            $formService = 'custom';
            $customService = $service;
        } else {
            $formService = $service;
            $customService = '';
        }

        return [
            'service' => $formService,
            'custom_service' => $customService,
            'protocol' => $protocol,
            'domain' => $domain,
            'weight' => isset($parts[0]) ? (int) $parts[0] : 5,
            'port' => isset($parts[1]) ? (int) $parts[1] : 443,
            'target' => isset($parts[2]) ? rtrim($parts[2], '.') : '',
            'priority' => $recordData['prio'] ?? 10,
            'ttl' => $recordData['ttl'] ?? $this->getDefaultTTL(),
        ];
    }
}
