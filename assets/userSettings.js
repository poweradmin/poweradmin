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
 * UserSettings - A module for managing user preferences in localStorage
 */
const UserSettings = (function() {
    // The localStorage key prefix
    const PREFIX = 'poweradmin_';
    
    /**
     * Get a user setting from localStorage
     * @param {string} key - The setting key
     * @param {*} defaultValue - Default value if setting doesn't exist
     * @returns {*} The setting value or default value
     */
    function getSetting(key, defaultValue = null) {
        const fullKey = PREFIX + key;
        const value = localStorage.getItem(fullKey);
        
        if (value === null) {
            return defaultValue;
        }
        
        try {
            return JSON.parse(value);
        } catch (e) {
            return value;
        }
    }
    
    /**
     * Save a user setting to localStorage
     * @param {string} key - The setting key
     * @param {*} value - The setting value
     */
    function saveSetting(key, value) {
        const fullKey = PREFIX + key;
        if (value === null || value === undefined) {
            localStorage.removeItem(fullKey);
            return;
        }
        
        if (typeof value === 'object') {
            localStorage.setItem(fullKey, JSON.stringify(value));
        } else {
            localStorage.setItem(fullKey, value);
        }
    }
    
    /**
     * Remove a user setting from localStorage
     * @param {string} key - The setting key
     */
    function removeSetting(key) {
        const fullKey = PREFIX + key;
        localStorage.removeItem(fullKey);
    }
    
    /**
     * Clear all user settings from localStorage
     */
    function clearAllSettings() {
        for (let i = 0; i < localStorage.length; i++) {
            const key = localStorage.key(i);
            if (key && key.startsWith(PREFIX)) {
                localStorage.removeItem(key);
            }
        }
    }
    
    /**
     * Apply rows per page setting to the current URL
     * @param {number} rowsPerPage - Number of rows per page
     * @param {string} pageType - Type of page (zones, edit, search_zones, search_records)
     */
    function applyRowsPerPageSetting(rowsPerPage, pageType = 'zones') {
        // Save setting to localStorage with page-specific key
        saveSetting(`rows_per_page_${pageType}`, rowsPerPage);
        
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
    
    /**
     * Initialize rows per page setting from URL or localStorage
     * @param {Array} availableOptions - Array of available rows per page options
     * @param {number} defaultValue - Default value from system config
     * @param {string} pageType - Type of page (zones, edit, search_zones, search_records)
     * @returns {number} The active rows per page setting
     */
    function initRowsPerPage(availableOptions, defaultValue, pageType = 'zones') {
        // For search page, we check localStorage only
        if (pageType === 'search_zones' || pageType === 'search_records') {
            const storedValue = getSetting(`rows_per_page_${pageType}`, null);
            if (storedValue !== null && availableOptions.includes(Number(storedValue))) {
                return Number(storedValue);
            }
            return defaultValue;
        }
        
        // For other pages, we check URL first
        const url = new URL(window.location.href);
        const paramName = `rows_per_page${pageType !== 'zones' ? `_${pageType}` : ''}`;
        const urlParam = url.searchParams.get(paramName);
        
        if (urlParam && !isNaN(Number(urlParam)) && availableOptions.includes(Number(urlParam))) {
            // Save to localStorage and return
            saveSetting(`rows_per_page_${pageType}`, Number(urlParam));
            return Number(urlParam);
        }
        
        // Check localStorage next
        const storedValue = getSetting(`rows_per_page_${pageType}`, null);
        if (storedValue !== null && availableOptions.includes(Number(storedValue))) {
            return Number(storedValue);
        }
        
        // Fall back to default
        return defaultValue;
    }
    
    // Public API
    return {
        getSetting,
        saveSetting,
        removeSetting,
        clearAllSettings,
        applyRowsPerPageSetting,
        initRowsPerPage
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