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
 * DMARC record validator
 *
 * Validates DMARC (Domain-based Message Authentication, Reporting, and Conformance)
 * records according to:
 * - RFC 7489: Domain-based Message Authentication, Reporting, and Conformance
 *
 * DMARC records are published as TXT records at _dmarc.<domain> and specify
 * policies for handling emails that fail SPF and/or DKIM authentication.
 * The record format begins with "v=DMARC1" followed by required and optional tags.
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class DMARCRecordValidator implements DnsRecordValidatorInterface
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
     * Validates DMARC record content
     *
     * @param string $content The content of the DMARC record
     * @param string $name The name of the record (should be _dmarc.<domain>)
     * @param mixed $prio The priority (unused for TXT/DMARC records)
     * @param int|string|null $ttl The TTL value
     * @param int $defaultTTL The default TTL to use if not specified
     *
     * @return ValidationResult ValidationResult containing validated data or error messages
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL): ValidationResult
    {
        // Validate hostname/name
        $hostnameResult = $this->hostnameValidator->validate($name, true);
        if (!$hostnameResult->isValid()) {
            return $hostnameResult;
        }
        $hostnameData = $hostnameResult->getData();
        $name = $hostnameData['hostname'];

        // Verify that name starts with _dmarc. prefix
        if (!str_starts_with(strtolower($name), '_dmarc.')) {
            return ValidationResult::failure(_('DMARC records must be placed at _dmarc.<domain>.'));
        }

        // Validate DMARC content and ensure proper quoting for TXT record
        $processedContent = $content;
        if (
            !isset($content[0]) || $content[0] !== '"' ||
            !isset($content[strlen($content) - 1]) || $content[strlen($content) - 1] !== '"'
        ) {
            $processedContent = '"' . $content . '"';
        }

        // Remove quotes for validation
        $unquotedContent = trim($processedContent, '"');
        $contentResult = $this->validateDMARCContent($unquotedContent);
        if (!$contentResult->isValid()) {
            return $contentResult;
        }

        // Extract warnings if any
        $warnings = [];
        if ($contentResult->hasWarnings()) {
            $warnings = $contentResult->getWarnings();
        }

        // Validate TTL
        $ttlResult = $this->ttlValidator->validate($ttl, $defaultTTL);
        if (!$ttlResult->isValid()) {
            return $ttlResult;
        }
        $ttlData = $ttlResult->getData();
        $validatedTtl = is_array($ttlData) && isset($ttlData['ttl']) ? $ttlData['ttl'] : $ttlData;

        // Validate priority (should be 0 for TXT/DMARC records)
        $prioResult = $this->validatePriority($prio);
        if (!$prioResult->isValid()) {
            return $prioResult;
        }
        $validatedPrio = $prioResult->getData();

        $resultData = [
            'content' => $processedContent,
            'name' => $name,
            'prio' => $validatedPrio,
            'ttl' => $validatedTtl
        ];

        return ValidationResult::success($resultData, $warnings);
    }

    /**
     * Validate priority for DMARC (TXT) records
     * DMARC records don't use priority, so it should be 0
     *
     * @param mixed $prio Priority value
     * @return ValidationResult ValidationResult containing validated priority or error message
     */
    private function validatePriority(mixed $prio): ValidationResult
    {
        // If priority is not provided or empty, set it to 0
        if (!isset($prio) || $prio === "") {
            return ValidationResult::success(0);
        }

        // If provided, ensure it's 0 for DMARC records
        if (is_numeric($prio) && intval($prio) === 0) {
            return ValidationResult::success(0);
        }

        return ValidationResult::failure(_('Invalid value for priority field. DMARC records must have a priority value of 0.'));
    }

    /**
     * Validate DMARC record content format according to RFC 7489
     *
     * @param string $content DMARC record content
     * @return ValidationResult ValidationResult containing validation status or error message
     */
    private function validateDMARCContent(string $content): ValidationResult
    {
        // Record must start with "v=DMARC1" (RFC 7489 Section 6.4)
        if (!preg_match('/^v=DMARC1\b/i', $content)) {
            return ValidationResult::failure(_('DMARC record must start with "v=DMARC1"'));
        }

        // Check record length - RFC 7489 uses TXT records which are limited to 255 bytes
        if (strlen($content) > 255) {
            return ValidationResult::failure(_('DMARC record exceeds 255 character limit. For complex policies, consider simplifying or using URI references.'));
        }

        // Parse and validate the record
        $errors = [];
        $warnings = [];
        $tags = $this->parseDMARCTags($content);

        // Required tags per RFC 7489 Section 6.3
        $requiredTags = ['p'];
        $seenTags = [];

        foreach ($tags as $tag => $value) {
            $seenTags[$tag] = true;

            // Validate each tag according to RFC 7489
            switch ($tag) {
                case 'v':
                    // Version - must be DMARC1
                    if (strtoupper($value) !== 'DMARC1') {
                        $errors[] = _('DMARC version tag (v) must be DMARC1');
                    }
                    break;

                case 'p':
                    // Policy - must be one of: none, quarantine, reject
                    $allowedPolicies = ['none', 'quarantine', 'reject'];
                    if (!in_array(strtolower($value), $allowedPolicies)) {
                        $errors[] = _('DMARC policy tag (p) must be one of: none, quarantine, reject');
                    }

                    // Warn about overly permissive policy
                    if (strtolower($value) === 'none') {
                        $warnings[] = _('Using "p=none" provides visibility into email authentication without affecting delivery. Consider a stricter policy once you\'ve reviewed the reports.');
                    }
                    break;

                case 'sp':
                    // Subdomain policy - must be one of: none, quarantine, reject
                    $allowedPolicies = ['none', 'quarantine', 'reject'];
                    if (!in_array(strtolower($value), $allowedPolicies)) {
                        $errors[] = _('DMARC subdomain policy tag (sp) must be one of: none, quarantine, reject');
                    }
                    break;

                case 'adkim':
                    // DKIM alignment mode - must be r (relaxed) or s (strict)
                    if ($value !== 'r' && $value !== 's') {
                        $errors[] = _('DMARC DKIM alignment tag (adkim) must be r (relaxed) or s (strict)');
                    }
                    break;

                case 'aspf':
                    // SPF alignment mode - must be r (relaxed) or s (strict)
                    if ($value !== 'r' && $value !== 's') {
                        $errors[] = _('DMARC SPF alignment tag (aspf) must be r (relaxed) or s (strict)');
                    }
                    break;

                case 'pct':
                    // Percentage - must be between 0 and 100
                    if (!is_numeric($value) || (int)$value < 0 || (int)$value > 100) {
                        $errors[] = _('DMARC percentage tag (pct) must be between 0 and 100');
                    }

                    // Warn about partial deployment
                    if (is_numeric($value) && (int)$value < 100) {
                        $warnings[] = sprintf(_('DMARC policy is applied to only %d%% of messages. This should only be used during policy rollout.'), (int)$value);
                    }
                    break;

                case 'ri':
                    // Reporting interval - must be a positive integer
                    if (!is_numeric($value) || (int)$value <= 0) {
                        $errors[] = _('DMARC reporting interval tag (ri) must be a positive integer');
                    }
                    break;

                case 'fo':
                    // Failure reporting options - must be 0, 1, d, s, or combination
                    if (!preg_match('/^[01ds](:[01ds])*$/', $value)) {
                        $errors[] = _('DMARC failure reporting options tag (fo) must contain only 0, 1, d, s, or colon-separated combinations');
                    }
                    break;

                case 'rua':
                case 'ruf':
                    // URI tags - must contain valid mailto or https URIs
                    $uris = explode(',', $value);
                    foreach ($uris as $uri) {
                        if (!preg_match('/^mailto:|^https:/', trim($uri))) {
                            $errors[] = sprintf(_('DMARC %s tag must contain valid mailto: or https: URIs'), $tag);
                            break;
                        }

                        // For mailto URIs, validate the email part
                        if (preg_match('/^mailto:(.+)$/', trim($uri), $matches)) {
                            $email = $matches[1];
                            // Extract email address part (ignoring any parameters)
                            $email = preg_replace('/!.*$/', '', $email);

                            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                                $errors[] = sprintf(_('Invalid email address in DMARC %s tag: %s'), $tag, $email);
                            }
                        }
                    }
                    break;

                case 'rf':
                    // Report format - must be afrf or iodef
                    $allowedFormats = ['afrf', 'iodef'];
                    if (!in_array(strtolower($value), $allowedFormats)) {
                        $errors[] = _('DMARC report format tag (rf) must be afrf or iodef');
                    }
                    break;

                default:
                    // RFC 7489 Section 6.3 - unknown tags should be ignored
                    $warnings[] = sprintf(_('Unknown DMARC tag: %s'), $tag);
                    break;
            }
        }

        // Check for required tags
        foreach ($requiredTags as $requiredTag) {
            if (!isset($seenTags[$requiredTag])) {
                $errors[] = sprintf(_('Required DMARC tag missing: %s'), $requiredTag);
            }
        }

        // Check for missing aggregate (rua) or forensic (ruf) reporting URIs
        if (!isset($seenTags['rua']) && !isset($seenTags['ruf'])) {
            $warnings[] = _('DMARC record does not specify any reporting URIs (rua or ruf). This may limit your ability to monitor authentication results.');
        }

        // If there are errors, return failure
        if (!empty($errors)) {
            return ValidationResult::errors($errors);
        }

        // Return success with any warnings
        return ValidationResult::success(true, $warnings);
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

        // Remove the version tag first to simplify parsing
        $content = preg_replace('/^v=DMARC1\s*;?/', '', $content);

        // Split the content by semicolons
        $parts = explode(';', $content);

        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) {
                continue;
            }

            // Split each part into a tag-value pair
            if (preg_match('/^([a-zA-Z0-9]+)=(.*)$/', $part, $matches)) {
                $tag = strtolower($matches[1]);
                $value = trim($matches[2]);
                $tags[$tag] = $value;
            }
        }

        // Add back the version tag
        $tags['v'] = 'DMARC1';

        return $tags;
    }
}
