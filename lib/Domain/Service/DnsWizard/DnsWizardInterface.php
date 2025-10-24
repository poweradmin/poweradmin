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

/**
 * Interface for DNS Record Wizards
 *
 * Defines the contract for all DNS record wizard implementations.
 * Each wizard type (DMARC, SPF, DKIM, CAA, TLSA, SRV) must implement this interface.
 *
 * @package Poweradmin\Domain\Service\DnsWizard
 */
interface DnsWizardInterface
{
    /**
     * Get the record type this wizard handles
     *
     * @return string The DNS record type (e.g., 'TXT', 'CAA', 'SRV')
     */
    public function getRecordType(): string;

    /**
     * Get the wizard identifier
     *
     * @return string The wizard identifier (e.g., 'DMARC', 'SPF', 'DKIM')
     */
    public function getWizardType(): string;

    /**
     * Get the wizard display name
     *
     * @return string Human-readable wizard name for UI display
     */
    public function getDisplayName(): string;

    /**
     * Get the wizard description
     *
     * @return string Short description of what this wizard does
     */
    public function getDescription(): string;

    /**
     * Get the form schema for this wizard
     *
     * Returns an array describing the form fields, their types, validation rules,
     * and default values. Used to dynamically generate wizard forms.
     *
     * @return array Form schema structure
     */
    public function getFormSchema(): array;

    /**
     * Generate DNS record data from wizard form input
     *
     * Takes the user's form data and generates the appropriate DNS record
     * content string and other record properties.
     *
     * @param array $formData The form data submitted by the user
     * @return array Array with keys: name, type, content, ttl, prio
     */
    public function generateRecord(array $formData): array;

    /**
     * Validate wizard form input
     *
     * Validates the form data according to DNS specifications and wizard rules.
     * Returns validation result with any errors or warnings.
     *
     * @param array $formData The form data to validate
     * @return array Validation result with 'valid' (bool), 'errors' (array), 'warnings' (array)
     */
    public function validate(array $formData): array;

    /**
     * Get a preview of the generated DNS record
     *
     * Generates a human-readable preview of the DNS record that will be created,
     * useful for displaying to users before they save.
     *
     * @param array $formData The form data to preview
     * @return string Formatted preview string
     */
    public function getPreview(array $formData): string;

    /**
     * Parse an existing DNS record for editing
     *
     * Takes an existing DNS record content string and converts it back into
     * form data that can populate the wizard form for editing.
     *
     * @param string $content The existing DNS record content
     * @param array $recordData Additional record data (name, ttl, etc.)
     * @return array Form data array
     */
    public function parseExistingRecord(string $content, array $recordData = []): array;

    /**
     * Check if this wizard supports two-mode design (simple + advanced)
     *
     * @return bool True if wizard supports mode switching
     */
    public function supportsTwoModes(): bool;
}
