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
 * MX Record Validator
 *
 * Validates MX (Mail Exchange) records according to:
 * - RFC 1035: Domain Names - Implementation and Specification (Section 3.3.9)
 * - RFC 2181: Clarifications to the DNS Specification (Section 10.3)
 * - RFC 7505: A Method for Indicating Mail Box Unavailability ("null MX")
 *
 * MX records specify the mail servers responsible for accepting email for a domain.
 * RFC 1035 establishes the basic format of MX records with a 16-bit priority value
 * and a domain name. RFC 2181 clarifies that MX targets cannot point to a CNAME.
 * RFC 7505 defines the "null MX" record (priority 0, target ".") to indicate
 * that a domain does not accept email.
 *
 * Implementation notes:
 * - Priority values are from 0 to 65535, with lower values indicating higher priority
 * - Standard MX records should point to a valid hostname with A/AAAA records
 * - "Null MX" records use "." as the target with priority 0 to indicate no mail service
 * - Domains with a null MX record should not have any other MX records
 * - MX targets should never point to CNAME records (RFC 2181)
 */
class MXRecordValidator implements DnsRecordValidatorInterface
{
    private ConfigurationManager $config;
    private TTLValidator $ttlValidator;
    private HostnameValidator $hostnameValidator;

    public function __construct(ConfigurationManager $config)
    {
        $this->config = $config;
        $this->ttlValidator = new TTLValidator();
        $this->hostnameValidator = new HostnameValidator($config);
    }

    /**
     * Validate MX record
     *
     * @param string $content Mail server hostname or "." for null MX
     * @param string $name Domain name for the MX record
     * @param mixed $prio Priority value
     * @param int|string|null $ttl TTL value
     * @param int $defaultTTL Default TTL to use if not specified
     *
     * @return ValidationResult ValidationResult containing validated data or errors
     */
    public function validate(string $content, string $name, mixed $prio, $ttl, int $defaultTTL): ValidationResult
    {
        $errors = [];
        $warnings = [];
        $isNullMx = false;

        // Process priority early so we can validate null MX correctly
        $prioResult = $this->validatePriority($prio);
        if (!$prioResult->isValid()) {
            $errors[] = $prioResult->getFirstError();
        }
        $priority = $prioResult->isValid() ? $prioResult->getData() : 10;

        // Handle RFC 7505 null MX case
        if (trim($content) === '.') {
            $isNullMx = true;

            // RFC 7505 requires null MX to have priority 0
            if ($priority !== 0) {
                $errors[] = _('Null MX record (.) must have priority 0 according to RFC 7505.');
            }

            // Set content as '.' - no need for hostname validation
            $content = '.';

            $warnings[] = _('This is a null MX record (RFC 7505) indicating this domain does not accept email.');
            $warnings[] = _('A domain with null MX record must not have any other MX records.');
        } else {
            // Validate content (mail server hostname) for standard MX records
            $contentResult = $this->hostnameValidator->validate($content, false);
            if (!$contentResult->isValid()) {
                return ValidationResult::errors(
                    array_merge([_('Invalid mail server hostname.')], $contentResult->getErrors())
                );
            }
            $hostnameData = $contentResult->getData();
            $content = $hostnameData['hostname'];

            // Add warning for potential CNAME targets (RFC 2181 violation)
            $warnings[] = _('MX records should not point to CNAME records (RFC 2181).');

            // Add warning for high priority values
            if ($priority > 100) {
                $warnings[] = _('Priority values above 100 are unusual and may be unnecessary.');
            }
        }

        // Validate name (domain name)
        $nameResult = $this->hostnameValidator->validate($name, true);
        if (!$nameResult->isValid()) {
            return $nameResult;
        }
        $nameData = $nameResult->getData();
        $name = $nameData['hostname'];

        // Validate TTL
        $ttlResult = $this->ttlValidator->validate($ttl, $defaultTTL);
        if (!$ttlResult->isValid()) {
            return ValidationResult::errors(array_merge($errors, $ttlResult->getErrors()));
        }
        $ttlData = $ttlResult->getData();
        $validatedTtl = is_array($ttlData) && isset($ttlData['ttl']) ? $ttlData['ttl'] : $ttlData;

        if (count($errors) > 0) {
            return ValidationResult::errors($errors);
        }

        return ValidationResult::success([
            'content' => $content,
            'name' => $name,
            'prio' => $priority,
            'ttl' => $validatedTtl,
            'is_null_mx' => $isNullMx
        ], $warnings);
    }

    /**
     * Validate priority for MX records
     * MX records require a numeric priority between 0 and 65535
     *
     * @param mixed $prio Priority value
     *
     * @return ValidationResult ValidationResult containing validated priority or error message
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

        return ValidationResult::failure(_('Invalid value for MX priority field. Must be between 0 and 65535.'));
    }
}
