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
 * SPF record validator
 *
 * Validates Sender Policy Framework (SPF) records according to:
 * - RFC 7208: Sender Policy Framework (SPF) for Authorizing Use of Email (April 2014)
 * - RFC 4408: Sender Policy Framework (SPF) (obsoleted by RFC 7208)
 *
 * SPF records allow domain owners to specify which hosts are authorized to send
 * email on behalf of their domain. The record format is defined in RFC 7208,
 * starting with "v=spf1" followed by mechanisms and modifiers.
 *
 * IMPORTANT: As per RFC 7208 Section 14.1, the SPF record type is deprecated.
 * SPF records should be published as TXT records, not as SPF type records.
 *
 * @package Poweradmin
 * @copyright   2007-2010 Rejo Zenger <rejo@zenger.nl>
 * @copyright   2010-2025 Poweradmin Development Team
 * @license     https://opensource.org/licenses/GPL-3.0 GPL
 */
class SPFRecordValidator implements DnsRecordValidatorInterface
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
     * Validates SPF record content
     *
     * @param string $content The content of the SPF record
     * @param string $name The name of the record
     * @param mixed $prio The priority (unused for SPF records)
     * @param int|string|null $ttl The TTL value
     * @param int $defaultTTL The default TTL to use if not specified
     *
     * @return ValidationResult ValidationResult containing validated data or error messages
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL): ValidationResult
    {
        // Validate hostname
        $hostnameResult = $this->hostnameValidator->validate($name, true);
        if (!$hostnameResult->isValid()) {
            return $hostnameResult;
        }
        $hostnameData = $hostnameResult->getData();
        $name = $hostnameData['hostname'];

        // Validate SPF content and ensure proper quoting
        $processedContent = $content;
        if (
            !isset($content[0]) || $content[0] !== '"' ||
            !isset($content[strlen($content) - 1]) || $content[strlen($content) - 1] !== '"'
        ) {
            $processedContent = '"' . $content . '"';
        }

        // Remove quotes for validation
        $unquotedContent = trim($processedContent, '"');
        $contentResult = $this->validateSPFContent($unquotedContent);
        if (!$contentResult->isValid()) {
            return $contentResult;
        }

        // Extract warnings from SPF content validation if any
        $warnings = [];
        $contentData = $contentResult->getData();
        if (is_array($contentData) && isset($contentData['warnings'])) {
            $warnings = $contentData['warnings'];
        }

        // Validate TTL
        $ttlResult = $this->ttlValidator->validate($ttl, $defaultTTL);
        if (!$ttlResult->isValid()) {
            return $ttlResult;
        }
        $ttlData = $ttlResult->getData();
        $validatedTtl = is_array($ttlData) && isset($ttlData['ttl']) ? $ttlData['ttl'] : $ttlData;

        // Validate priority
        $priorityResult = $this->validatePriority($prio);
        if (!$priorityResult->isValid()) {
            return $priorityResult;
        }
        $validatedPrio = $priorityResult->getData();

        $resultData = [
            'content' => $processedContent,
            'name' => $name,
            'prio' => $validatedPrio,
            'ttl' => $validatedTtl
        ];

        // Add warnings if any
        if (!empty($warnings)) {
            $resultData['warnings'] = $warnings;
        }

        return ValidationResult::success($resultData);
    }

    /**
     * Validate priority for SPF records
     * SPF records don't use priority, so it should be 0
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

        // If provided, ensure it's 0 for SPF records
        if (is_numeric($prio) && intval($prio) === 0) {
            return ValidationResult::success(0);
        }

        return ValidationResult::failure(_('Priority field for SPF records must be 0 or empty'));
    }

    /**
     * Validate SPF record content format according to RFC 7208
     *
     * @param string $content SPF record content
     * @return ValidationResult ValidationResult containing validation status or error message
     */
    private function validateSPFContent(string $content): ValidationResult
    {
        // Record must start with "v=spf1" (RFC 7208 Section 4.5)
        if (!preg_match('/^v=spf1\b/i', $content)) {
            return ValidationResult::failure(_('SPF record must start with "v=spf1"'));
        }

        // Check record length - RFC 7208 Section 3.3 states max DNS TXT record is 255 chars
        // RFC 7208 also mentions splitting across multiple strings, but we validate that in the TXT validator
        if (strlen($content) > 255) {
            return ValidationResult::failure(_('SPF record exceeds 255 character limit. Split across multiple TXT records.'));
        }

        // Validate via detailed mechanism and modifier parsing
        $errors = [];
        $warnings = [];

        // Parse and validate each term in the SPF record
        $terms = $this->parseSpfTerms($content);

        // Track directives/mechanisms and modifiers to detect duplicates and invalid combinations
        $seenDirectives = [];
        $seenModifiers = [];
        $hasAll = false;
        $hasRedirect = false;

        foreach ($terms as $term) {
            $type = $term['type'];
            $qualifier = $term['qualifier'] ?? '+'; // Default qualifier is "+"
            $value = $term['value'];

            // Check for duplicate directives (RFC 7208 Section 4.6.1)
            if ($type !== 'modifier') {
                if (isset($seenDirectives[$type])) {
                    $errors[] = sprintf(_('Duplicate %s mechanism found. Each mechanism type should appear at most once.'), $type);
                }
                $seenDirectives[$type] = true;

                // Track 'all' mechanism
                if ($type === 'all') {
                    $hasAll = true;
                }
            } else {
                // Modifier validation
                $modifierName = substr($value, 0, strpos($value, '='));

                // Check for duplicate modifiers (RFC 7208 Section 6)
                if (isset($seenModifiers[$modifierName])) {
                    $errors[] = sprintf(_('Duplicate %s modifier found. Each modifier can appear at most once.'), $modifierName);
                }
                $seenModifiers[$modifierName] = true;

                // Track 'redirect' modifier
                if ($modifierName === 'redirect') {
                    $hasRedirect = true;
                }
            }

            // Check specific mechanisms
            switch ($type) {
                case 'ip4':
                    if (!$this->validateIp4Mechanism($value)) {
                        $errors[] = _('Invalid IPv4 address or network in ip4 mechanism.');
                    }
                    break;

                case 'ip6':
                    if (!$this->validateIp6Mechanism($value)) {
                        $errors[] = _('Invalid IPv6 address or network in ip6 mechanism.');
                    }
                    break;

                case 'a':
                case 'mx':
                case 'ptr':
                case 'exists':
                case 'include':
                    // Validate domain names (and optional CIDR network) in these mechanisms
                    $domainAndCidr = explode('/', $value, 2);
                    $domain = trim($domainAndCidr[0]);

                    if (!empty($domain) && !$this->hostnameValidator->isValid($domain)) {
                        $errors[] = sprintf(_('Invalid domain name in %s mechanism.'), $type);
                    }

                    // Validate CIDR if present
                    if (isset($domainAndCidr[1])) {
                        if ($type !== 'a' && $type !== 'mx') {
                            $errors[] = sprintf(_('CIDR notation is not valid with the %s mechanism.'), $type);
                        } else {
                            if (!$this->validateCidrLength($domainAndCidr[1])) {
                                $errors[] = _('Invalid CIDR length in mechanism.');
                            }
                        }
                    }

                    // RFC 7208 section 5.4 suggests PTR mechanism should not be used due to performance
                    if ($type === 'ptr') {
                        $warnings[] = _('The ptr mechanism is not recommended due to performance issues (RFC 7208 Section 5.5).');
                    }
                    break;

                case 'all':
                    // 'all' mechanism should be last (RFC 7208 section 5.1)
                    if (count($terms) > array_search($term, $terms) + 1) {
                        $warnings[] = _('The "all" mechanism should be the last mechanism in the record (RFC 7208 Section 5.1).');
                    }
                    break;

                case 'modifier':
                    // Validate specific modifiers
                    if (stripos($value, 'redirect=') === 0) {
                        $redirectDomain = trim(substr($value, 9));
                        if (!$this->hostnameValidator->isValid($redirectDomain)) {
                            $errors[] = _('Invalid domain in redirect modifier.');
                        }
                    } elseif (stripos($value, 'exp=') === 0) {
                        $expDomain = trim(substr($value, 4));
                        if (!$this->hostnameValidator->isValid($expDomain)) {
                            $errors[] = _('Invalid domain in exp modifier.');
                        }
                    } else {
                        // RFC 7208 allows for unknown modifiers
                        $warnings[] = sprintf(_('Unknown modifier: %s'), $value);
                    }
                    break;

                default:
                    // Unknown mechanisms are errors per RFC 7208 Section 5
                    $errors[] = sprintf(_('Unknown mechanism: %s'), $type);
                    break;
            }
        }

        // Validate that SPF record has either a terminating "all" mechanism or a "redirect" modifier (RFC 7208 Section 4.6.2)
        if (!$hasAll && !$hasRedirect) {
            $warnings[] = _('SPF record should have either a terminating "all" mechanism or a "redirect" modifier (RFC 7208 Section 4.6.2).');
        }

        // Having both "all" and "redirect" is inefficient and may lead to unexpected results
        if ($hasAll && $hasRedirect) {
            $warnings[] = _('SPF record has both "all" mechanism and "redirect" modifier. The "redirect" modifier will be ignored when "all" is present (RFC 7208 Section 6.1).');
        }

        // If there are errors, return failure
        if (!empty($errors)) {
            return ValidationResult::errors($errors);
        }

        // If there are only warnings, return success with warnings
        if (!empty($warnings)) {
            $result = ['isValid' => true, 'warnings' => $warnings];
            return ValidationResult::success($result);
        }

        return ValidationResult::success(true);
    }

    /**
     * Parse SPF record into individual terms
     *
     * @param string $content SPF record content
     * @return array Array of parsed terms
     */
    private function parseSpfTerms(string $content): array
    {
        // Remove the leading v=spf1
        $content = preg_replace('/^v=spf1\s*/i', '', $content);

        // Split the content into terms
        $terms = [];
        $rawTerms = preg_split('/\s+/', $content);

        foreach ($rawTerms as $term) {
            if (empty($term)) {
                continue;
            }

            // Handle modifiers (name=value format)
            if (strpos($term, '=') !== false) {
                $terms[] = [
                    'type' => 'modifier',
                    'value' => $term
                ];
                continue;
            }

            // Handle directives (mechanisms with optional qualifiers)
            $qualifier = '+'; // Default qualifier
            if (in_array($term[0], ['+', '-', '~', '?'])) {
                $qualifier = $term[0];
                $term = substr($term, 1);
            }

            // Split the mechanism and its value (if any)
            $parts = explode(':', $term, 2);
            $mechanism = strtolower($parts[0]);
            $value = $parts[1] ?? '';

            $terms[] = [
                'type' => $mechanism,
                'qualifier' => $qualifier,
                'value' => $value
            ];
        }

        return $terms;
    }

    /**
     * Validate IPv4 mechanism format according to RFC 7208
     *
     * @param string $value IPv4 address with optional CIDR
     * @return bool True if valid
     */
    private function validateIp4Mechanism(string $value): bool
    {
        // Split IP and CIDR if present
        $parts = explode('/', $value, 2);
        $ip = $parts[0];

        // Validate IP part
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        // Validate CIDR if present
        if (isset($parts[1])) {
            $cidr = (int)$parts[1];
            if ($cidr < 0 || $cidr > 32) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate IPv6 mechanism format according to RFC 7208
     *
     * @param string $value IPv6 address with optional CIDR
     * @return bool True if valid
     */
    private function validateIp6Mechanism(string $value): bool
    {
        // Split IP and CIDR if present
        $parts = explode('/', $value, 2);
        $ip = $parts[0];

        // Validate IP part
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return false;
        }

        // Validate CIDR if present
        if (isset($parts[1])) {
            $cidr = (int)$parts[1];
            if ($cidr < 0 || $cidr > 128) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate CIDR length
     *
     * @param string $cidr CIDR length string
     * @return bool True if valid
     */
    private function validateCidrLength(string $cidr): bool
    {
        // Check if CIDR is a valid number
        if (!is_numeric($cidr)) {
            return false;
        }

        $cidrValue = (int)$cidr;

        // RFC 7208 section 5.3 defines valid CIDR length ranges
        if ($cidrValue < 0 || $cidrValue > 128) {
            return false;
        }

        return true;
    }

    /**
     * Build SPF regex pattern
     *
     * @return string Complete SPF validation regex pattern
     */
    private function buildSpfRegexPattern(): string
    {
        // Breaking the SPF regex into manageable parts
        $versionPart = "[Vv]=[Ss][Pp][Ff]1";

        $mechanismPrefix = "[-+?~]?";
        $allMechanism = "([Aa][Ll][Ll]";

        $macroString = "(%\\{[CDHILOPR-Tcdhilopr-t]([1-9][0-9]?|10[0-9]|11[0-9]|12[0-8])?[Rr]?[+-/=_]*\\}|%%|%_|%-|[!-$&-~])*";
        $domainSpec = "(\\\.([A-Za-z]|[A-Za-z]([-0-9A-Za-z]?)*[0-9A-Za-z])|%\\{[CDHILOPR-Tcdhilopr-t]([1-9][0-9]?|10[0-9]|11[0-9]|12[0-8])?[Rr]?[+-/=_]*\\})";

        $includeMechanism = "|[Ii][Nn][Cc][Ll][Uu][Dd][Ee]:" . $macroString . $domainSpec;
        $aMechanism = "|[Aa](:" . $macroString . $domainSpec . ")?((/([1-9]|1[0-9]|2[0-9]|3[0-2]))?(//([1-9][0-9]?|10[0-9]|11[0-9]|12[0-8]))?)?";
        $mxMechanism = "|[Mm][Xx](:" . $macroString . $domainSpec . ")?((/([1-9]|1[0-9]|2[0-9]|3[0-2]))?(//([1-9][0-9]?|10[0-9]|11[0-9]|12[0-8]))?)?";
        $ptrMechanism = "|[Pp][Tt][Rr](:" . $macroString . $domainSpec . ")?";

        $ip4Mechanism = "|[Ii][Pp]4:([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])"
            . "\\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])"
            . "\\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])"
            . "\\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])(/([1-9]|1[0-9]|2[0-9]|3[0-2]))?";

        $ip6Mechanism = "|[Ii][Pp]6:(::|([0-9A-Fa-f]{1,4}:){7}[0-9A-Fa-f]{1,4}|([0-9A-Fa-f]{1,4}:){1,8}:|([0-9A-Fa-f]{1,4}:){7}:"
            . "[0-9A-Fa-f]{1,4}|([0-9A-Fa-f]{1,4}:){6}(:[0-9A-Fa-f]{1,4}){1,2}|([0-9A-Fa-f]{1,4}:){5}(:[0-9A-Fa-f]{1,4}){1,3}|"
            . "([0-9A-Fa-f]{1,4}:){4}(:[0-9A-Fa-f]{1,4}){1,4}|([0-9A-Fa-f]{1,4}:){3}(:[0-9A-Fa-f]{1,4}){1,5}|([0-9A-Fa-f]{1,4}:){2}"
            . "(:[0-9A-Fa-f]{1,4}){1,6}|[0-9A-Fa-f]{1,4}:(:[0-9A-Fa-f]{1,4}){1,7}|:(:[0-9A-Fa-f]{1,4}){1,8}|([0-9A-Fa-f]{1,4}:){6}"
            . "([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])"
            . "\\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])|"
            . "([0-9A-Fa-f]{1,4}:){6}:([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])"
            . "\\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])|"
            . "([0-9A-Fa-f]{1,4}:){5}:([0-9A-Fa-f]{1,4}:)?([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])"
            . "\\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])"
            . "\\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])|([0-9A-Fa-f]{1,4}:){4}:([0-9A-Fa-f]{1,4}:){0,2}"
            . "([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])"
            . "\\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])|"
            . "([0-9A-Fa-f]{1,4}:){3}:([0-9A-Fa-f]{1,4}:){0,3}([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])"
            . "\\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])"
            . "\\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])|([0-9A-Fa-f]{1,4}:){2}:([0-9A-Fa-f]{1,4}:){0,4}"
            . "([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])"
            . "\\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])|"
            . "[0-9A-Fa-f]{1,4}::([0-9A-Fa-f]{1,4}:){0,5}([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])"
            . "\\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])"
            . "\\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])|::([0-9A-Fa-f]{1,4}:){0,6}([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])"
            . "\\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])"
            . "\\.([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5]))(/([1-9][0-9]?|10[0-9]|11[0-9]|12[0-8]))?";

        $existsMechanism = "|[Ee][Xx][Ii][Ss][Tt][Ss]:" . $macroString . $domainSpec;

        $redirectModifier = "|[Rr][Ee][Dd][Ii][Rr][Ee][Cc][Tt]=" . $macroString . $domainSpec;
        $expModifier = "|[Ee][Xx][Pp]=" . $macroString . $domainSpec;
        $customModifier = "|[A-Za-z][-.0-9A-Z_a-z]*=(" . $macroString . ")";

        // Combine all parts
        $mechanisms = "(" . $mechanismPrefix . $allMechanism . $includeMechanism . $aMechanism . $mxMechanism
            . $ptrMechanism . $ip4Mechanism . $ip6Mechanism . $existsMechanism . $redirectModifier
            . $expModifier . $customModifier . "))* *";

        return $versionPart . "( +" . $mechanisms;
    }
}
