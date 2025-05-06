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
     * Get form data from the session and remove it if it exists
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

        // Get the data and remove it from the session
        $data = $formState['data'];
        unset($_SESSION[self::SESSION_KEY][$formId]);

        return $data;
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
     */
    public function generateFormId(string $prefix = ''): string
    {
        return $prefix . '_' . bin2hex(random_bytes(8));
    }
}
