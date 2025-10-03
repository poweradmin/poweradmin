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

/**
 * UserSettings - A module for managing user preferences with database
 */
const UserSettings = (function() {
    // API endpoint for user preferences
    const API_ENDPOINT = (window.BASE_URL_PREFIX || '') + '/api/internal/user-preferences';
    
    /**
     * Save a preference to database
     * @param {string} key - The preference key
     * @param {*} value - The preference value
     * @returns {Promise} Promise that resolves when saved
     */
    async function saveToDatabase(key, value) {
        try {
            const response = await fetch(API_ENDPOINT, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ key: key, value: String(value) })
            });

            if (!response.ok) {
                const errorData = await response.json().catch(() => ({}));
                const errorMessage = errorData.error || 'Failed to save preference to database';
                console.error('Failed to save preference:', errorMessage);
                throw new Error(errorMessage);
            }

            return response.json();
        } catch (error) {
            console.error('Error saving preference:', error);
            throw error;
        }
    }
    
    /**
     * Load preferences from database
     * @returns {Promise} Promise that resolves with preferences object
     */
    async function loadFromDatabase() {
        try {
            const response = await fetch(API_ENDPOINT, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                }
            });
            
            if (!response.ok) {
                console.error('Failed to load preferences from database');
                return {};
            }
            
            const data = await response.json();
            return data.preferences || {};
        } catch (error) {
            console.error('Error loading preferences:', error);
            return {};
        }
    }
    
    /**
     * Apply rows per page setting to the current URL
     * @param {number} rowsPerPage - Number of rows per page
     * @param {string} pageType - Type of page (zones, edit, search_zones, search_records)
     */
    function applyRowsPerPageSetting(rowsPerPage, pageType = 'zones') {
        // Get current URL and parse it
        const url = new URL(window.location.href);
        
        // For search page, we need to handle differently as it uses form submission
        if (pageType === 'search_zones' || pageType === 'search_records') {
            return rowsPerPage; // Just return the value to be used by form submission
        }
        
        // For other pages, we update URL parameters
        const paramName = `rows_per_page${pageType !== 'zones' ? `_${pageType}` : ''}`;
        
        // Update or add rows_per_page parameter
        url.searchParams.set(paramName, rowsPerPage);
        
        // If we have a page parameter, reset it to 1
        if (url.searchParams.has('start')) {
            url.searchParams.set('start', 1);
        }
        
        // Navigate to the new URL
        window.location.href = url.toString();
    }
    
    
    // Public API
    return {
        applyRowsPerPageSetting,
        saveToDatabase,
        loadFromDatabase
    };
})();

/**
 * Updates the rows per page setting
 * @param {number} rowsPerPage - The new rows per page value
 * @param {string} pageType - Type of page (zones, edit, search_zones, search_records)
 */
function changeRowsPerPage(rowsPerPage, pageType = 'zones') {
    return UserSettings.applyRowsPerPageSetting(rowsPerPage, pageType);
}