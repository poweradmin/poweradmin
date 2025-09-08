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

namespace Poweradmin\Domain\Service\DnsValidation;

use Poweradmin\Domain\Service\Validation\ValidationResult;
use Poweradmin\Infrastructure\Configuration\ConfigurationManager;

/**
 * SRV record validator
 *
 * Validates SRV (Service) records according to:
 * - RFC 2782: A DNS RR for specifying the location of services (SRV)
 *
 * SRV records specify the location of services on the network.
 *
 * The format is:
 * _service._protocol.name. TTL class SRV priority weight port target.
 *
 * Where:
 * - _service: the symbolic name of the service, starting with underscore
 * - _protocol: the transport protocol (typically _tcp or _udp), starting with underscore
 * - name: the domain name this record refers to
 * - priority: the priority of this target (lower values are preferred)
 * - weight: relative weight for records with the same priority
 * - port: the TCP or UDP port where the service is provided
 * - target: the canonical hostname of the machine providing the service
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class SRVRecordValidator implements DnsRecordValidatorInterface
{
    private ConfigurationManager $config;
    private HostnameValidator $hostnameValidator;
    private TTLValidator $ttlValidator;

    public function __construct(ConfigurationManager $config)
    {
        $this->config = $config;
        $this->hostnameValidator = new HostnameValidator($config);
        $this->ttlValidator = new TTLValidator();
    }

    /**
     * Validates SRV record content
     *
     * @param string $content The content of the SRV record
     * @param string $name The name of the record
     * @param mixed $prio The priority (used for SRV records)
     * @param int|string|null $ttl The TTL value
     * @param int $defaultTTL The default TTL to use if not specified
     *
     * @return ValidationResult ValidationResult containing validated data or error messages
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL, ...$args): ValidationResult
    {
        $warnings = [];

        // Validate SRV name
        $nameResult = $this->validateSrvName($name);
        if (!$nameResult->isValid()) {
            // Modify the error message if needed
            $error = $nameResult->getFirstError();
            if (strpos($error, 'Invalid service value in SRV record') !== false) {
                return ValidationResult::failure("Invalid service value in name field of SRV record");
            }
            return $nameResult;
        }
        $nameData = $nameResult->getData();
        $name = $nameData['name'];

        // Collect warnings from name validation
        if ($nameResult->hasWarnings()) {
            $warnings = array_merge($warnings, $nameResult->getWarnings());
        }

        // Validate SRV content
        $contentResult = $this->validateSrvContent($content, $name);
        if (!$contentResult->isValid()) {
            return $contentResult;
        }
        $contentData = $contentResult->getData();
        $content = $contentData['content'];

        // Collect warnings from content validation
        if ($contentResult->hasWarnings()) {
            $warnings = array_merge($warnings, $contentResult->getWarnings());
        }

        // Validate priority (SRV records use priority)
        $prioResult = $this->validatePriority($prio);
        if (!$prioResult->isValid()) {
            return $prioResult;
        }
        $validatedPrio = $prioResult->getData();

        // Add priority warning if it's 0 (unusual but valid)
        if ($validatedPrio === 0) {
            $warnings[] = _('Priority 0 is valid but unusual. Typically SRV records start at priority 10 or higher.');
        }

        // Validate TTL
        $ttlResult = $this->ttlValidator->validate($ttl, $defaultTTL);
        if (!$ttlResult->isValid()) {
            return $ttlResult;
        }
        $ttlData = $ttlResult->getData();
        $validatedTtl = is_array($ttlData) && isset($ttlData['ttl']) ? $ttlData['ttl'] : $ttlData;

        $resultData = [
            'content' => $content,
            'name' => $name,
            'prio' => $validatedPrio,
            'ttl' => $validatedTtl,
            'nameData' => $nameData,
            'contentData' => $contentData
        ];

        return ValidationResult::success($resultData, $warnings);
    }

    /**
     * Well-known service names and default ports according to RFC 2782 and IANA registrations
     */
    private const WELL_KNOWN_SERVICES = [
        '_acap' => 674,         // Application Configuration Access Protocol
        '_afp' => 548,          // Apple Filing Protocol
        '_auth' => 113,         // Authentication Service
        '_caldav' => 8443,      // Calendaring Extensions to WebDAV
        '_carddav' => 8443,     // vCard Extensions to WebDAV
        '_csip' => 5060,        // SIP for Cisco devices
        '_dhcp' => 67,          // Dynamic Host Configuration Protocol
        '_dialog' => 5060,      // Dialog
        '_domain' => 53,        // Domain Name System
        '_finger' => 79,        // Finger protocol
        '_ftp' => 21,           // File Transfer Protocol
        '_h323' => 1720,        // H.323 Host call setup
        '_http' => 80,          // Hypertext Transfer Protocol
        '_https' => 443,        // HTTP Secure
        '_iax' => 4569,         // Inter-Asterisk eXchange
        '_imap' => 143,         // Internet Message Access Protocol
        '_imaps' => 993,        // IMAP over TLS/SSL
        '_irc' => 194,          // Internet Relay Chat
        '_jabber' => 5269,      // Jabber/XMPP Server-to-Server
        '_kerberos' => 88,      // Kerberos
        '_ldap' => 389,         // Lightweight Directory Access Protocol
        '_ldaps' => 636,        // LDAP over TLS/SSL
        '_matrix' => 8448,      // Matrix federated chat
        '_minecraft' => 25565,  // Minecraft game server
        '_mqtt' => 1883,        // Message Queuing Telemetry Transport
        '_mta-amavis' => 10024, // Mail Transfer Agent - AMAVIS
        '_mysql' => 3306,       // MySQL Database
        '_nfs' => 2049,         // Network File System
        '_ntp' => 123,          // Network Time Protocol
        '_pgpkeys' => 11371,    // PGP Key Server
        '_pgprevokations' => 11371, // PGP Revocation Server
        '_pop3' => 110,         // Post Office Protocol v3
        '_pop3s' => 995,        // POP3 over TLS/SSL
        '_postgresql' => 5432,  // PostgreSQL Database
        '_presence' => 5298,    // XMPP/Jabber Client Presence
        '_printer' => 515,      // Line Printer Daemon
        '_redis' => 6379,       // Redis Database
        '_rje' => 5,            // Remote Job Entry
        '_rsync' => 873,        // Remote Sync
        '_sieve' => 4190,       // Sieve Mail Filtering
        '_sip' => 5060,         // Session Initiation Protocol
        '_sips' => 5061,        // SIP-TLS
        '_smb' => 445,          // Server Message Block
        '_smtp' => 25,          // Simple Mail Transfer Protocol
        '_smtps' => 465,        // SMTP over TLS/SSL
        '_ssh' => 22,           // Secure Shell
        '_stun' => 3478,        // Session Traversal Utilities for NAT
        '_submission' => 587,   // Mail Message Submission
        '_svn' => 3690,         // Subversion
        '_telnet' => 23,        // Telnet protocol
        '_tftp' => 69,          // Trivial File Transfer Protocol
        '_tls' => 443,          // Transport Layer Security
        '_turn' => 3478,        // Traversal Using Relays around NAT
        '_ventrilo' => 3784,    // Ventrilo VoIP server
        '_webdav' => 80,        // Web Distributed Authoring and Versioning
        '_webdavs' => 443,      // WebDAV over TLS/SSL
        '_websocket' => 80,     // WebSocket protocol
        '_wss' => 443,          // WebSocket protocol over TLS/SSL
        '_www' => 80,           // World Wide Web
        '_x-puppet' => 8140,    // Puppet configuration management
        '_xmpp-client' => 5222, // XMPP Client Connection
        '_xmpp-server' => 5269, // XMPP Server Connection
        '_xmpp-bosh' => 5280,   // XMPP BOSH (HTTP Binding)
    ];

    /**
     * Well-known protocols for SRV records according to RFC 2782
     */
    private const WELL_KNOWN_PROTOCOLS = [
        '_tcp',    // Transmission Control Protocol
        '_udp',    // User Datagram Protocol
        '_tls',    // Transport Layer Security
        '_sctp',   // Stream Control Transmission Protocol
        '_quic',   // QUIC (Quick UDP Internet Connections)
        '_ws',     // WebSockets
        '_wss',    // Secure WebSockets
    ];

    /**
     * Validate SRV record name format according to RFC 2782
     *
     * @param string $name SRV record name
     *
     * @return ValidationResult ValidationResult containing name data or error message
     */
    private function validateSrvName(string $name): ValidationResult
    {
        // Check overall length limit
        if (strlen($name) > 255) {
            return ValidationResult::failure(_('The hostname is too long.'));
        }

        // Split into service, protocol, and domain parts
        $fields = explode('.', $name, 3);

        // Check if we have all three parts required for an SRV record
        if (count($fields) < 3) {
            return ValidationResult::failure(_('SRV record name must be in format _service._protocol.domain'));
        }

        // Get the service and protocol parts
        $service = strtolower($fields[0]);
        $protocol = strtolower($fields[1]);

        // Validate service name according to RFC 2782
        if (!preg_match('/^_[\w\-]+$/i', $service)) {
            return ValidationResult::failure(_('Invalid service value in SRV record. Service name must start with an underscore followed by alphanumeric characters.'));
        }

        // Validate protocol according to RFC 2782
        if (!preg_match('/^_[\w]+$/i', $protocol)) {
            return ValidationResult::failure(_('Invalid protocol value in SRV record. Protocol must start with an underscore followed by alphanumeric characters.'));
        }

        // Check against well-known service names and protocols
        $warnings = [];

        // Warning for non-standard service name
        if (!array_key_exists($service, self::WELL_KNOWN_SERVICES)) {
            $warnings[] = sprintf(
                _('Service name "%s" is not in the list of well-known services. This is allowed but unusual.'),
                $service
            );
        }

        // Warning for non-standard protocol
        if (!in_array($protocol, self::WELL_KNOWN_PROTOCOLS)) {
            $warnings[] = sprintf(
                _('Protocol "%s" is not in the list of well-known protocols. Common protocols are _tcp and _udp.'),
                $protocol
            );
        }

        // Validate the domain part
        $domainResult = $this->hostnameValidator->validate($fields[2], false);
        if (!$domainResult->isValid()) {
            return ValidationResult::failure(_('Invalid domain name in SRV record.'));
        }

        // Return success with optional warnings
        return ValidationResult::success(['name' => join('.', $fields),
            'service' => $service,
            'protocol' => $protocol,
            'domain' => $fields[2]], $warnings);
    }

    /**
     * Validate SRV record content format according to RFC 2782
     *
     * @param string $content SRV record content
     * @param string $name SRV record name
     *
     * @return ValidationResult ValidationResult containing content data or error message
     */
    private function validateSrvContent(string $content, string $name): ValidationResult
    {
        $fields = preg_split("/\s+/", trim($content));

        // Check if we have exactly 3 fields for SRV record content
        // Format should be: <weight> <port> <target> (priority is in separate field)
        if (count($fields) != 3) {
            return ValidationResult::failure(_('SRV record content must have weight, port and target (3 fields). Priority should be in the priority field.'));
        }

        // Extract the fields (no priority in content for PowerDNS)
        [$weight, $port, $target] = $fields;

        // Priority will be validated separately in the main validate() method
        $priority = 0; // Placeholder for validation logic

        // Extract service and protocol from the name
        $nameParts = explode('.', $name, 3);
        $service = '';
        $protocol = '';

        if (count($nameParts) >= 2) {
            $service = strtolower($nameParts[0]);
            $protocol = strtolower($nameParts[1]);
        }


        // Validate weight (0-65535)
        if (!is_numeric($weight) || (int)$weight < 0 || (int)$weight > 65535) {
            return ValidationResult::failure(_('Invalid value for the weight field of the SRV record. Must be 0-65535.'));
        }

        // Validate port (1-65535)
        if (!is_numeric($port) || (int)$port < 1 || (int)$port > 65535) {
            return ValidationResult::failure(_('Invalid value for the port field of the SRV record. Must be 1-65535.'));
        }

        // Check port against well-known service
        $warnings = [];
        if (array_key_exists($service, self::WELL_KNOWN_SERVICES)) {
            $expectedPort = self::WELL_KNOWN_SERVICES[$service];
            if ((int)$port !== $expectedPort) {
                $warnings[] = sprintf(
                    _('Port %d differs from the standard port %d for %s service. This is allowed but unusual.'),
                    (int)$port,
                    $expectedPort,
                    $service
                );
            }
        }

        // Validate target (must not be empty)
        if ($target === "") {
            return ValidationResult::failure(_('SRV target cannot be empty.'));
        }

        // Special case: "." means root/no target or empty hostname
        if ($target !== ".") {
            $targetResult = $this->validateTarget($target);
            if (!$targetResult->isValid()) {
                return $targetResult;
            }

            // RFC 2782 recommends that the target name MUST NOT be an alias
            // but this can't be enforced in the validator since we don't have this context
            // TODO: Consider checking for CNAME records with this target, but this requires DB access
        }

        // Additional RFC 2782 validation rules
        // - Weight of 0 means no server selection preference (after priority)

        // Target of "." has special meaning - "no service is available at this domain"
        if ($target === "." && ((int)$port !== 0 || (int)$weight !== 0)) {
            $warnings[] = _('When target is "." (no service), port and weight should both be 0.');
        }

        return ValidationResult::success(['content' => join(' ', $fields),
            'weight' => (int)$weight,
            'port' => (int)$port,
            'target' => $target], $warnings);
    }

    /**
     * Validate the SRV target hostname
     *
     * @param string $target The target hostname
     *
     * @return ValidationResult ValidationResult containing validation result
     */
    private function validateTarget(string $target): ValidationResult
    {
        // Special case: '.' is valid per RFC 2782 to indicate "no service available"
        if ($target === '.') {
            return ValidationResult::success(true);
        }

        $targetResult = $this->hostnameValidator->validate($target, false);
        if (!$targetResult->isValid()) {
            return ValidationResult::failure(_('Invalid SRV target.'));
        }
        return ValidationResult::success(true);
    }

    /**
     * Validate the priority field for SRV records
     *
     * @param mixed $prio The priority value to validate
     * @return ValidationResult ValidationResult containing the validated priority value or error
     */
    private function validatePriority(mixed $prio): ValidationResult
    {
        // If priority is not provided or empty, use default of 10
        if (!isset($prio) || $prio === "") {
            return ValidationResult::success(10);
        }

        // Priority must be a number between 0 and 65535
        if (is_numeric($prio) && $prio >= 0 && $prio <= 65535) {
            return ValidationResult::success((int)$prio);
        }

        return ValidationResult::failure(_('Invalid value for the priority field of the SRV record.'));
    }
}
