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
 * URI (Uniform Resource Identifier) Record Validator
 *
 * Validates URI DNS records according to RFC 7553 (The URI DNS Resource Record).
 *
 * URI records provide a way to publish URIs in the DNS system to identify services
 * and resources associated with a domain name. The URI record can contain any URI that
 * conforms to the URI syntax specified in RFC 3986.
 *
 * Format: <priority> <weight> "<target URI>"
 *
 * Components:
 * - Priority: 16-bit unsigned integer (0-65535) - Lower values have higher priority
 * - Weight: 16-bit unsigned integer (0-65535) - Used for load balancing among records of equal priority
 * - Target URI: Quoted string containing a URI that follows RFC 3986 format
 *
 * URI Scheme Requirements:
 * - Must start with a letter followed by letters, digits, plus, period, or hyphen
 * - Standard protocols (http, https, ftp, etc.) require "://" after the scheme
 * - Special protocols (mailto, tel, sms, etc.) don't require the "//" separator
 * - The URI can contain any valid characters allowed by RFC 3986
 *
 * Security Considerations:
 * - URI records should be used with DNSSEC to prevent manipulation
 * - Care should be taken when resolving URIs from untrusted DNS sources
 * - Applications should validate URIs before using them
 *
 * Common Uses:
 * - Web service discovery
 * - Email service configuration
 * - Application-specific resource location
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class URIRecordValidator implements DnsRecordValidatorInterface
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
     * Validates URI record content according to RFC 7553 and RFC 3986
     *
     * URI records have the format: <priority> <weight> "<target URI>"
     * Example: 10 1 "https://example.com/"
     *
     * @param string $content The content of the URI record
     * @param string $name The name of the record
     * @param mixed $prio The priority value
     * @param int|string|null $ttl The TTL value
     * @param int $defaultTTL The default TTL to use if not specified
     *
     * @return ValidationResult ValidationResult containing validated data or error messages
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL, ...$args): ValidationResult
    {
        $warnings = [];

        // Validate hostname/name
        $hostnameResult = $this->hostnameValidator->validate($name, true);
        if (!$hostnameResult->isValid()) {
            return $hostnameResult;
        }
        $nameData = $hostnameResult->getData();
        $validatedName = $nameData['hostname'];

        // Validate content
        $contentResult = StringValidator::validatePrintable($content);
        if (!$contentResult->isValid()) {
            return ValidationResult::failure(_('Invalid characters in content field.'));
        }

        // Parse URI record parts: <priority> <weight> "<target URI>"
        $validationResult = $this->isValidURIRecordFormat($content);
        if (!$validationResult['isValid']) {
            return ValidationResult::errors($validationResult['errors']);
        }

        // If the validation includes warnings, extract them
        if (isset($validationResult['warnings'])) {
            $warnings = array_merge($warnings, $validationResult['warnings']);
        }

        // Extract URI details for further validation
        $uriDetails = $this->parseURIContent($content);
        if ($uriDetails) {
            // Add URI-scheme specific warnings
            $schemeWarnings = $this->getSchemeSpecificWarnings($uriDetails['scheme']);
            if (!empty($schemeWarnings)) {
                $warnings = array_merge($warnings, $schemeWarnings);
            }
        }

        // Add general security warning about DNSSEC
        $warnings[] = _('URI records should be protected with DNSSEC to prevent manipulation by attackers.');

        // Validate TTL
        $ttlResult = $this->ttlValidator->validate($ttl, $defaultTTL);
        if (!$ttlResult->isValid()) {
            return $ttlResult;
        }
        $ttlData = $ttlResult->getData();
        $validatedTtl = is_array($ttlData) && isset($ttlData['ttl']) ? $ttlData['ttl'] : $ttlData;

        // Use the provided priority if available, otherwise the priority from the content
        $priority = ($prio !== '' && $prio !== null) ? (int)$prio : $this->extractPriorityFromContent($content);

        return ValidationResult::success([
            'content' => $content,
            'name' => $validatedName,
            'prio' => $priority,
            'ttl' => $validatedTtl,
            'warnings' => $warnings,
            'uri_components' => $uriDetails
        ]);
    }

    /**
     * Extract URI details from the content
     *
     * @param string $content The URI record content
     * @return array|null Array with URI details or null if parsing fails
     */
    private function parseURIContent(string $content): ?array
    {
        if (preg_match('/^(\d+)\s+(\d+)\s+"(.*)"$/', $content, $matches)) {
            $priority = (int)$matches[1];
            $weight = (int)$matches[2];
            $uri = $matches[3];

            // Extract the scheme
            if (preg_match('/^([a-zA-Z][a-zA-Z0-9+.-]*):/i', $uri, $schemeMatches)) {
                $scheme = strtolower($schemeMatches[1]);

                return [
                    'priority' => $priority,
                    'weight' => $weight,
                    'uri' => $uri,
                    'scheme' => $scheme
                ];
            }
        }

        return null;
    }

    /**
     * Get scheme-specific warnings
     *
     * @param string $scheme The URI scheme
     * @return array Array of warnings
     */
    private function getSchemeSpecificWarnings(string $scheme): array
    {
        $warnings = [];

        switch ($scheme) {
            case 'http':
                $warnings[] = _('Consider using HTTPS instead of HTTP for better security.');
                break;

            case 'ftp':
                $warnings[] = _('FTP is considered less secure than alternatives like SFTP or HTTPS.');
                break;

            case 'ldap':
                $warnings[] = _('Consider using LDAPS (LDAP over SSL/TLS) for securing LDAP connections.');
                break;

            case 'tel':
            case 'sms':
                $warnings[] = _('Phone-related URI schemes may contain sensitive personal information.');
                break;

            case 'file':
                $warnings[] = _('The "file" URI scheme may pose security risks and is not recommended for public DNS records.');
                break;
        }

        return $warnings;
    }

    /**
     * Check if content follows URI record format: <priority> <weight> "<target URI>"
     * Validates according to RFC 7553 (URI record) and RFC 3986 (URI syntax)
     *
     * @param string $content The content to validate
     * @return array Array with 'isValid' (bool), 'errors' (array), and optional 'warnings' (array) keys
     */
    private function isValidURIRecordFormat(string $content): array
    {
        $errors = [];
        $warnings = [];

        // Simple regex to match URI record format
        if (!preg_match('/^(\d+)\s+(\d+)\s+"(.*)"$/', $content, $matches)) {
            $errors[] = _('URI record must be in the format: <priority> <weight> "<target URI>"');
            return ['isValid' => false, 'errors' => $errors];
        }

        $priority = (int)$matches[1];
        $weight = (int)$matches[2];
        $uri = $matches[3];

        // Validate priority (0-65535)
        if ($priority < 0 || $priority > 65535) {
            $errors[] = _('URI priority must be between 0 and 65535 (16-bit unsigned integer).');
            return ['isValid' => false, 'errors' => $errors];
        }

        // Validate weight (0-65535)
        if ($weight < 0 || $weight > 65535) {
            $errors[] = _('URI weight must be between 0 and 65535 (16-bit unsigned integer).');
            return ['isValid' => false, 'errors' => $errors];
        }

        // Check for empty URI
        if (empty(trim($uri))) {
            $errors[] = _('URI must not be empty.');
            return ['isValid' => false, 'errors' => $errors];
        }

        // Validate URI scheme (RFC 3986)
        if (!preg_match('/^([a-zA-Z][a-zA-Z0-9+.-]*):/i', $uri, $schemeMatches)) {
            $errors[] = _('URI must start with a valid scheme that begins with a letter followed by letters, digits, plus, period, or hyphen.');
            return ['isValid' => false, 'errors' => $errors];
        }

        $scheme = strtolower($schemeMatches[1]);

        // Validate known protocols (suggest to use official schemes)
        $knownProtocols = ['http', 'https', 'ftp', 'ftps', 'ldap', 'ldaps', 'mailto', 'tel', 'sms', 'bitcoin', 'urn', 'sip', 'sips', 'xmpp', 'ws', 'wss', 'sftp', 'git', 'file'];
        if (!in_array($scheme, $knownProtocols)) {
            $warnings[] = _('URI uses an uncommon protocol. Consider using a standard URI scheme for better compatibility.');
        }

        // Special protocols that don't require // after scheme (per RFC 3986)
        $specialProtocols = ['mailto', 'tel', 'sms', 'bitcoin', 'urn'];
        $requiresSlashes = !in_array($scheme, $specialProtocols);

        // Validate format for standard protocols
        if ($requiresSlashes && !preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:\/\//i', $uri)) {
            $errors[] = _('URI with this protocol must include "://" after the protocol name.');
            return ['isValid' => false, 'errors' => $errors];
        }

        // For http/https URIs, validate they have a hostname
        if (
            ($scheme === 'http' || $scheme === 'https') &&
            !preg_match('/^https?:\/\/[^\/\s]+/i', $uri)
        ) {
            $errors[] = _('HTTP/HTTPS URIs must include a hostname.');
            return ['isValid' => false, 'errors' => $errors];
        }

        // Additional security check: URI must not contain control characters
        if (preg_match('/[\x00-\x1F\x7F]/', $uri)) {
            $errors[] = _('URI must not contain control characters.');
            return ['isValid' => false, 'errors' => $errors];
        }

        // Check for potentially dangerous URI components
        if (strpos($uri, '\\') !== false) {
            $warnings[] = _('URI contains backslash characters, which may be interpreted differently across systems.');
        }

        if (strpos($uri, '..') !== false) {
            $warnings[] = _('URI contains ".." sequences, which may be used for directory traversal attacks.');
        }

        // Check for percent encoding
        if (strpos($uri, '%') !== false) {
            // Validate that percent encodings are well-formed: %XX where XX are hex digits
            if (!preg_match('/(%[0-9A-Fa-f]{2})/', $uri)) {
                $warnings[] = _('URI contains percent characters not used for properly formatted percent-encoding.');
            }
        }

        // If host contains IDN characters (international domains), add a warning
        if (preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:\/\/[^\/\s]+/i', $uri, $hostMatches)) {
            $host = $hostMatches[0];
            if (preg_match('/[^\x00-\x7F]/', $host)) {
                $warnings[] = _('URI contains non-ASCII characters in hostname. Consider using Punycode for international domain names.');
            }
        }

        return ['isValid' => true, 'errors' => [], 'warnings' => $warnings];
    }

    /**
     * Extract priority value from URI record content
     *
     * @param string $content The URI record content
     * @return int The priority value
     */
    private function extractPriorityFromContent(string $content): int
    {
        preg_match('/^(\d+)\s+/', $content, $matches);
        return isset($matches[1]) ? (int)$matches[1] : 0;
    }
}
