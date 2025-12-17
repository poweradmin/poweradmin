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

namespace Poweradmin\Infrastructure\Configuration;

class ConfigValidator
{
    private array $config;
    private array $errors;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->errors = [];
    }

    public function validate(): bool
    {
        $this->errors = [];

        $this->validateIfaceRowAmount();
        $this->validateIfaceLang();
        $this->validateTheme();
        $this->validateSyslogUse();
        if ($this->getSetting('logging', 'syslog_enabled')) {
            $this->validateSyslogIdent();
            $this->validateSyslogFacility();
        }
        $this->validatePdnsApiUrl();
        $this->validatePdnsApiKey();
        $this->validatePdnsDbName();

        return empty($this->errors);
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Helper method to get setting from hierarchical config
     *
     * @param string $group Settings group
     * @param string $key Setting key
     * @param mixed $default Default value
     * @return mixed
     */
    private function getSetting(string $group, string $key, mixed $default = null): mixed
    {
        return $this->config[$group][$key] ?? $default;
    }

    private function validateSyslogUse(): void
    {
        $syslogEnabled = $this->getSetting('logging', 'syslog_enabled');
        if (!is_bool($syslogEnabled)) {
            $this->errors['logging.syslog_enabled'] = 'syslog_enabled must be a boolean value (unquoted true or false)';
        }
    }

    private function validateSyslogIdent(): void
    {
        $syslogIdent = $this->getSetting('logging', 'syslog_identity');
        if (!is_string($syslogIdent) || empty($syslogIdent)) {
            $this->errors['logging.syslog_identity'] = 'syslog_identity must be a non-empty string';
        }
    }

    private function validateSyslogFacility(): void
    {
        $validFacilities = [
            'LOG_USER' => LOG_USER,
            'LOG_LOCAL0' => LOG_LOCAL0,
            'LOG_LOCAL1' => LOG_LOCAL1,
            'LOG_LOCAL2' => LOG_LOCAL2,
            'LOG_LOCAL3' => LOG_LOCAL3,
            'LOG_LOCAL4' => LOG_LOCAL4,
            'LOG_LOCAL5' => LOG_LOCAL5,
            'LOG_LOCAL6' => LOG_LOCAL6,
            'LOG_LOCAL7' => LOG_LOCAL7,
        ];

        $syslogFacility = $this->getSetting('logging', 'syslog_facility');
        if (!in_array($syslogFacility, $validFacilities)) {
            $validFacilitiesList = implode(', ', array_keys($validFacilities));
            $this->errors['logging.syslog_facility'] = "syslog_facility must be an unquoted value and one of the following values: $validFacilitiesList";
        }
    }

    private function validateIfaceRowAmount(): void
    {
        $rowsPerPage = $this->getSetting('interface', 'rows_per_page');
        if (!is_int($rowsPerPage) || $rowsPerPage <= 0) {
            $this->errors['interface.rows_per_page'] = 'rows_per_page must be a positive integer';
        }
    }

    private function validateIfaceLang(): void
    {
        $language = $this->getSetting('interface', 'language');
        if (!is_string($language) || empty($language)) {
            $this->errors['interface.language'] = 'language must be a non-empty string';
        }

        $enabledLanguages = $this->getSetting('interface', 'enabled_languages');
        if (empty($enabledLanguages)) {
            $this->errors['interface.enabled_languages'] = 'enabled_languages must be a non-empty string and contain a list of languages separated by commas';
            return;
        }

        $enabledLanguagesArray = array_map('trim', explode(',', $enabledLanguages));
        if (!in_array($language, $enabledLanguagesArray)) {
            $this->errors['interface.language'] = 'language must be one of the enabled languages';
        }

        foreach ($enabledLanguagesArray as $lang) {
            if (!is_string($lang) || empty($lang)) {
                $this->errors['interface.enabled_languages'] = 'enabled_languages must be a non-empty string and contain a list of languages separated by commas';
                break;
            }
        }
    }

    private function validateTheme(): void
    {
        $themeBasePath = $this->getSetting('interface', 'theme_base_path', 'templates');
        $theme = $this->getSetting('interface', 'theme', 'default');

        if (!is_string($theme) || empty($theme)) {
            $this->errors['interface.theme'] = 'theme must be a non-empty string';
            return;
        }

        $themePath = $themeBasePath . '/' . $theme;

        if (!is_dir($themePath)) {
            // Check if this is a legacy config issue (old removed themes)
            $removedThemes = ['spark', 'ignite', 'mobile'];
            if (in_array($theme, $removedThemes)) {
                $this->errors['interface.theme'] = "Theme '$theme' was removed in Poweradmin 4.0. The theme directory '$themePath' does not exist. " .
                    "Please run the configuration migration script: php config/migrate-config.php " .
                    "or update your configuration to use theme: 'default'.";
            } else {
                $this->errors['interface.theme'] = "Theme directory '$themePath' does not exist. " .
                    "Please check your theme configuration or use the default theme.";
            }
        }
    }

    private function validatePdnsApiUrl(): void
    {
        $apiUrl = $this->getSetting('pdns_api', 'url');

        // Skip validation if API URL is not configured
        if ($apiUrl === null || $apiUrl === '') {
            return;
        }

        // Check if it's a string
        if (!is_string($apiUrl)) {
            $this->errors['pdns_api.url'] = 'PowerDNS API URL must be a string';
            return;
        }

        // Check for missing protocol prefix - common misconfiguration
        if (!preg_match('/^https?:\/\//i', $apiUrl)) {
            $this->errors['pdns_api.url'] = 'PowerDNS API URL must start with http:// or https:// (found: "' . $apiUrl . '"). ' .
                'Common mistake: missing protocol prefix. Example: http://127.0.0.1:8081';
            return;
        }

        // Validate URL format
        $parsedUrl = parse_url($apiUrl);
        if ($parsedUrl === false || !isset($parsedUrl['scheme']) || !isset($parsedUrl['host'])) {
            $this->errors['pdns_api.url'] = 'PowerDNS API URL is not a valid URL format. Expected format: http://hostname:port or https://hostname:port';
            return;
        }

        // Check scheme
        if (!in_array(strtolower($parsedUrl['scheme']), ['http', 'https'])) {
            $this->errors['pdns_api.url'] = 'PowerDNS API URL must use http or https protocol';
            return;
        }

        // Check port if specified
        if (isset($parsedUrl['port'])) {
            $port = $parsedUrl['port'];
            // parse_url() returns int|null for port, so this should always be int here
            if (!is_int($port)) {
                $this->errors['pdns_api.url'] = 'PowerDNS API URL contains an invalid port number';
                return;
            }

            if ($port < 1 || $port > 65535) {
                $this->errors['pdns_api.url'] = 'PowerDNS API URL port must be between 1 and 65535';
                return;
            }
        }
    }

    private function validatePdnsApiKey(): void
    {
        $apiKey = $this->getSetting('pdns_api', 'key');
        $apiUrl = $this->getSetting('pdns_api', 'url');

        // Only validate API key if URL is configured
        if ($apiUrl === null || $apiUrl === '') {
            return;
        }

        if (!is_string($apiKey) || empty($apiKey)) {
            $this->errors['pdns_api.key'] = 'PowerDNS API key must be a non-empty string when API URL is configured';
        }
    }

    private function validatePdnsDbName(): void
    {
        $dbType = $this->getSetting('database', 'type');
        $pdnsDbName = $this->getSetting('database', 'pdns_db_name');

        // Skip if pdns_db_name is not set or is null (which is valid)
        if ($pdnsDbName === null) {
            return;
        }

        // For PostgreSQL and SQLite, pdns_db_name should be null or empty
        // Only MySQL supports separate database for PowerDNS tables
        if (in_array($dbType, ['pgsql', 'sqlite'], true) && !empty($pdnsDbName)) {
            $this->errors['database.pdns_db_name'] = "pdns_db_name must be null for '$dbType' (only MySQL supports separate database)";
        }
    }
}
