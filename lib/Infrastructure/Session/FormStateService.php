<?php

/*  Poweradmin, a friendly web-based admin tool for PowerDNS.
 *  See <https://www.poweradmin.org> for more details.
 *
 *  Copyright 2007-2010 Rejo Zenger <rejo@zenger.nl>
 *  Copyright 2010-2026 Poweradmin Development Team
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

namespace Poweradmin\Infrastructure\Session;

use Poweradmin\Domain\Port\SessionInterface;
use Poweradmin\Domain\Service\Auth\SessionKeys;

/**
 * Keeps submitted form data in the session so a failed request can refill the form.
 *
 * This service handles storing and retrieving form data when validation errors occur,
 * allowing form fields to retain their values after a failed submission.
 */
class FormStateService
{
    private const SESSION_KEY = 'form_state';
    private const EXPIRY_TIME = 300; // 5 minutes in seconds

    public function __construct(private readonly SessionInterface $session)
    {
    }

    /**
     * Save form data to the session with an expiry time
     *
     * @param string $formId Unique identifier for the form
     * @param array $data Form data to store
     * @return void
     */
    public function saveFormData(string $formId, array $data): void
    {
        $forms = $this->forms();
        $forms[$formId] = [
            'data' => $data,
            'expires' => time() + self::EXPIRY_TIME
        ];
        $this->session->set(self::SESSION_KEY, $forms);
    }

    /**
     * Get form data from the session without removing it
     *
     * @param string $formId Unique identifier for the form
     * @return array|null The form data or null if it doesn't exist or has expired
     */
    public function getFormData(string $formId): ?array
    {
        $this->cleanupExpiredData();

        $forms = $this->forms();
        if (!isset($forms[$formId])) {
            return null;
        }

        $formState = $forms[$formId];

        // Check if the data has expired
        if (time() > $formState['expires']) {
            unset($forms[$formId]);
            $this->session->set(self::SESSION_KEY, $forms);
            return null;
        }

        // Refresh the expiry time
        $forms[$formId]['expires'] = time() + self::EXPIRY_TIME;
        $this->session->set(self::SESSION_KEY, $forms);

        // Return the data without removing it
        return $formState['data'];
    }

    /**
     * Explicitly clear form data when it's no longer needed
     *
     * @param string $formId Unique identifier for the form
     * @return void
     */
    public function clearFormData(string $formId): void
    {
        $forms = $this->forms();
        if (isset($forms[$formId])) {
            unset($forms[$formId]);
            $this->session->set(self::SESSION_KEY, $forms);
        }
    }

    /**
     * Clean up expired form data from the session
     *
     * @return void
     */
    private function cleanupExpiredData(): void
    {
        if (!$this->session->has(self::SESSION_KEY)) {
            return;
        }

        $now = time();
        $forms = $this->forms();
        foreach ($forms as $formId => $formState) {
            if ($now > $formState['expires']) {
                unset($forms[$formId]);
            }
        }
        $this->session->set(self::SESSION_KEY, $forms);
    }

    /**
     * @return array<string, array{data: array, expires: int}> The stored forms, keyed by form id
     */
    private function forms(): array
    {
        $forms = $this->session->get(self::SESSION_KEY, []);

        return is_array($forms) ? $forms : [];
    }

    /**
     * Generate a unique form ID based on the current request
     *
     * @param string $prefix Optional prefix for the form ID
     * @return string The generated form ID
     */
    public function generateFormId(string $prefix = ''): string
    {
        return $prefix . '_' . bin2hex(random_bytes(8));
    }

    /**
     * Stash the zone editor's inline add-record form before processing, so a
     * failed validation can re-display the submitted values.
     *
     * These use the same session keys the zone editor always used, so the
     * behaviour (and any in-flight sessions) are unchanged.
     *
     * @param array $values The submitted add-record fields
     */
    public function rememberAddRecordForm(array $values): void
    {
        $this->session->set(SessionKeys::ADD_RECORD_LAST_DATA, $values);
    }

    /**
     * Record why the stashed add-record submission was refused.
     *
     * @param array $error Error flags/message/field for the re-rendered form
     */
    public function rememberAddRecordError(array $error): void
    {
        $this->session->set(SessionKeys::ADD_RECORD_ERROR, $error);
    }

    /**
     * The stashed add-record values merged with their error, or null unless
     * both are present. Non-destructive: the stash survives until the add
     * succeeds or the zone changes.
     */
    public function addRecordFormWithError(): ?array
    {
        if (!$this->session->has(SessionKeys::ADD_RECORD_LAST_DATA) || !$this->session->has(SessionKeys::ADD_RECORD_ERROR)) {
            return null;
        }

        return array_merge($this->session->get(SessionKeys::ADD_RECORD_LAST_DATA), $this->session->get(SessionKeys::ADD_RECORD_ERROR));
    }

    /**
     * Drop the stashed add-record form and its error, after a successful add.
     */
    public function forgetAddRecordForm(): void
    {
        $this->session->remove(SessionKeys::ADD_RECORD_LAST_DATA);
        $this->session->remove(SessionKeys::ADD_RECORD_ERROR);
    }

    /**
     * Remember which zone the add-record stash belongs to, clearing it when the
     * operator moves to a different zone so values never leak across zones.
     */
    public function trackAddRecordZone(int $zoneId): void
    {
        if ($this->session->has(SessionKeys::ADD_RECORD_ZONE_ID) && $this->session->get(SessionKeys::ADD_RECORD_ZONE_ID) != $zoneId) {
            $this->forgetAddRecordForm();
            $this->session->remove(SessionKeys::ADD_RECORD_ZONE_ID);
        }

        $this->session->set(SessionKeys::ADD_RECORD_ZONE_ID, $zoneId);
    }
}
