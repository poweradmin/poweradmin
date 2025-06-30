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

namespace Poweradmin\Domain\Service;

use Random\RandomException;

/**
 * Service class for managing form state across requests
 *
 * This service handles storing and retrieving form data when validation errors occur,
 * allowing form fields to retain their values after a failed submission.
 *
 * @package Poweradmin\Domain\Service
 */
class FormStateService
{
    private const SESSION_KEY = 'form_state';
    private const EXPIRY_TIME = 300; // 5 minutes in seconds

    /**
     * Save form data to the session with an expiry time
     *
     * @param string $formId Unique identifier for the form
     * @param array $data Form data to store
     * @return void
     */
    public function saveFormData(string $formId, array $data): void
    {
        if (!isset($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = [];
        }

        $_SESSION[self::SESSION_KEY][$formId] = [
            'data' => $data,
            'expires' => time() + self::EXPIRY_TIME
        ];
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

        if (!isset($_SESSION[self::SESSION_KEY][$formId])) {
            return null;
        }

        $formState = $_SESSION[self::SESSION_KEY][$formId];

        // Check if the data has expired
        if (time() > $formState['expires']) {
            unset($_SESSION[self::SESSION_KEY][$formId]);
            return null;
        }

        // Refresh the expiry time
        $_SESSION[self::SESSION_KEY][$formId]['expires'] = time() + self::EXPIRY_TIME;

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
        if (isset($_SESSION[self::SESSION_KEY][$formId])) {
            unset($_SESSION[self::SESSION_KEY][$formId]);
        }
    }

    /**
     * Clean up expired form data from the session
     *
     * @return void
     */
    private function cleanupExpiredData(): void
    {
        if (!isset($_SESSION[self::SESSION_KEY])) {
            return;
        }

        $now = time();
        foreach ($_SESSION[self::SESSION_KEY] as $formId => $formState) {
            if ($now > $formState['expires']) {
                unset($_SESSION[self::SESSION_KEY][$formId]);
            }
        }
    }

    /**
     * Generate a unique form ID based on the current request
     *
     * @param string $prefix Optional prefix for the form ID
     * @return string The generated form ID
     * @throws RandomException
     */
    public function generateFormId(string $prefix = ''): string
    {
        return $prefix . '_' . bin2hex(random_bytes(8));
    }

    /**
     * Get the latest form ID with a specified prefix
     *
     * @param string $prefix The prefix to search for
     * @return string|null The latest form ID or null if none found
     */
    public function getLatestFormId(string $prefix): ?string
    {
        if (!isset($_SESSION[self::SESSION_KEY])) {
            return null;
        }

        $latestTime = 0;
        $latestId = null;

        foreach ($_SESSION[self::SESSION_KEY] as $formId => $formState) {
            // Check if the form ID starts with the given prefix
            if (strpos($formId, $prefix . '_') === 0) {
                // If this form is newer than the latest we've found so far
                if ($formState['expires'] > $latestTime) {
                    $latestTime = $formState['expires'];
                    $latestId = $formId;
                }
            }
        }

        return $latestId;
    }
}
