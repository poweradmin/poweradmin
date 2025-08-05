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

/**
 * Custom class for configuration migration without extending ConfigurationManager
 */
class MigrationConfigurationManager
{
    /**
     * Convert legacy configuration to new format
     */
    public function migrateWithCustomMapping(string $legacyConfigFile): array
    {
        // Load legacy config
        $legacyConfig = $this->loadLegacyConfigFile($legacyConfigFile);

        // Start with an empty configuration structure
        // We'll only fill in values that were actually in the old config file
        $newConfig = [
            'database' => [],
            'security' => [],
            'interface' => [],
            'dns' => [],
            'dnssec' => [],
            'pdns_api' => [],
            'logging' => [],
            'ldap' => [],
            'misc' => [],
        ];

        // We intentionally don't include 'mail' as it's new in 4.0.0
        // and we want to use defaults for new features

        // Database settings with type conversion
        $databaseMapping = [
            'db_host' => ['key' => 'host', 'type' => 'string'],
            'db_port' => ['key' => 'port', 'type' => 'int'],
            'db_user' => ['key' => 'user', 'type' => 'string'],
            'db_pass' => ['key' => 'password', 'type' => 'string'],
            'db_name' => ['key' => 'name', 'type' => 'string'],
            'db_type' => ['key' => 'type', 'type' => 'string'],
            'db_charset' => ['key' => 'charset', 'type' => 'string'],
            'db_file' => ['key' => 'file', 'type' => 'string'],
            'db_debug' => ['key' => 'debug', 'type' => 'bool'],
        ];

        foreach ($databaseMapping as $oldKey => $config) {
            if (array_key_exists($oldKey, $legacyConfig)) {
                $newConfig['database'][$config['key']] = $this->convertType($legacyConfig[$oldKey], $config['type']);
            }
        }

        // Specific fix for pdns_db_name
        if (isset($legacyConfig['pdns_db_name'])) {
            $newConfig['database']['pdns_db_name'] = $legacyConfig['pdns_db_name'];
        }

        // Security settings with type conversion
        $securityMapping = [
            'session_key' => ['key' => 'session_key', 'type' => 'string'],
            'password_encryption' => ['key' => 'password_encryption', 'type' => 'string'],
            'password_encryption_cost' => ['key' => 'password_cost', 'type' => 'int'],
            'login_token_validation' => ['key' => 'login_token_validation', 'type' => 'bool'],
            'global_token_validation' => ['key' => 'global_token_validation', 'type' => 'bool'],
        ];

        foreach ($securityMapping as $oldKey => $config) {
            if (array_key_exists($oldKey, $legacyConfig)) {
                $newConfig['security'][$config['key']] = $this->convertType($legacyConfig[$oldKey], $config['type']);
            }
        }

        // We're not setting password_policy and account_lockout defaults
        // as they are new in 4.0.0 and we want to use the defaults from settings.defaults.php

        // Interface settings mapping with type conversion
        $interfaceMapping = [
            'iface_lang' => ['key' => 'language', 'type' => 'string'],
            'iface_enabled_languages' => ['key' => 'enabled_languages', 'type' => 'string'],
            'iface_style' => ['key' => 'style', 'type' => 'string'],
            'iface_templates' => ['key' => 'theme_base_path', 'type' => 'string'],
            'iface_title' => ['key' => 'title', 'type' => 'string'],
            'iface_expire' => ['key' => 'session_timeout', 'type' => 'int'],
            'iface_rowamount' => ['key' => 'rows_per_page', 'type' => 'int'],
            'iface_zonelist_serial' => ['key' => 'display_serial_in_zone_list', 'type' => 'bool'],
            'iface_zonelist_template' => ['key' => 'display_template_in_zone_list', 'type' => 'bool'],
            'iface_edit_show_id' => ['key' => 'show_record_id', 'type' => 'bool'],
            'iface_edit_add_record_top' => ['key' => 'position_record_form_top', 'type' => 'bool'],
            'iface_edit_save_changes_top' => ['key' => 'position_save_button_top', 'type' => 'bool'],
            'iface_zone_comments' => ['key' => 'show_zone_comments', 'type' => 'bool'],
            'iface_record_comments' => ['key' => 'show_record_comments', 'type' => 'bool'],
            'iface_search_group_records' => ['key' => 'search_group_records', 'type' => 'bool'],
            'iface_add_reverse_record' => ['key' => 'add_reverse_record', 'type' => 'bool'],
            'iface_add_domain_record' => ['key' => 'add_domain_record', 'type' => 'bool'],
            'iface_migrations_show' => ['key' => 'show_migrations', 'type' => 'bool'],
        ];

        // Process interface settings with type conversion
        foreach ($interfaceMapping as $oldKey => $config) {
            if (array_key_exists($oldKey, $legacyConfig)) {
                $newConfig['interface'][$config['key']] = $this->convertType($legacyConfig[$oldKey], $config['type']);
            }
        }

        // Handle style mapping - if iface_style is 'ignite' or 'spark', map to 'light' or 'dark'
        if (isset($legacyConfig['iface_style'])) {
            if ($legacyConfig['iface_style'] === 'ignite') {
                $newConfig['interface']['style'] = 'light';
            } elseif ($legacyConfig['iface_style'] === 'spark') {
                $newConfig['interface']['style'] = 'dark';
            } else {
                // If it's not one of the known styles, assume it's 'light'
                $newConfig['interface']['style'] = 'light';
            }
            // Set theme to 'default' - all themes now default to 'default'
            $newConfig['interface']['theme'] = 'default';
        }

        // DNS settings with type conversion
        $dnsMapping = [
            'dns_hostmaster' => ['key' => 'hostmaster', 'type' => 'string'],
            'dns_ns1' => ['key' => 'ns1', 'type' => 'string'],
            'dns_ns2' => ['key' => 'ns2', 'type' => 'string'],
            'dns_ns3' => ['key' => 'ns3', 'type' => 'string'],
            'dns_ns4' => ['key' => 'ns4', 'type' => 'string'],
            'dns_ttl' => ['key' => 'ttl', 'type' => 'int'],
            'dns_strict_tld_check' => ['key' => 'strict_tld_check', 'type' => 'bool'],
            'dns_top_level_tld_check' => ['key' => 'top_level_tld_check', 'type' => 'bool'],
            'dns_third_level_check' => ['key' => 'third_level_check', 'type' => 'bool'],
            'dns_txt_auto_quote' => ['key' => 'txt_auto_quote', 'type' => 'bool'],
        ];

        foreach ($dnsMapping as $oldKey => $config) {
            if (array_key_exists($oldKey, $legacyConfig)) {
                $newConfig['dns'][$config['key']] = $this->convertType($legacyConfig[$oldKey], $config['type']);
            }
        }

        // Handle zone_type_default (moved from interface to dns section)
        if (isset($legacyConfig['iface_zone_type_default'])) {
            $newConfig['dns']['zone_type_default'] = $legacyConfig['iface_zone_type_default'];
        }

        // We don't set domain_record_types and reverse_record_types
        // as they're new in 4.0.0 and we want to use defaults

        // Handle SOA values specifically
        if (isset($legacyConfig['dns_soa']) && is_string($legacyConfig['dns_soa'])) {
            $soaValues = explode(' ', $legacyConfig['dns_soa']);

            // Default SOA values
            $defaultRefresh = 28800;  // 8 hours
            $defaultRetry = 7200;     // 2 hours
            $defaultExpire = 604800;  // 1 week
            $defaultMinimum = 86400;  // 24 hours

            // If we have a valid SOA string, use those values
            if (count($soaValues) === 4) {
                $newConfig['dns']['soa_refresh'] = (int) $soaValues[0];
                $newConfig['dns']['soa_retry'] = (int) $soaValues[1];
                $newConfig['dns']['soa_expire'] = (int) $soaValues[2];
                $newConfig['dns']['soa_minimum'] = (int) $soaValues[3];
            } else {
                // Use default values if the SOA string doesn't have all 4 parts
                $newConfig['dns']['soa_refresh'] = $defaultRefresh;
                $newConfig['dns']['soa_retry'] = $defaultRetry;
                $newConfig['dns']['soa_expire'] = $defaultExpire;
                $newConfig['dns']['soa_minimum'] = $defaultMinimum;
            }
        }

        // DNSSEC settings
        if (isset($legacyConfig['pdnssec_use'])) {
            $newConfig['dnssec']['enabled'] = $legacyConfig['pdnssec_use'];
        }
        if (isset($legacyConfig['pdnssec_debug'])) {
            $newConfig['dnssec']['debug'] = $legacyConfig['pdnssec_debug'];
        }
        if (isset($legacyConfig['pdnssec_command'])) {
            $newConfig['dnssec']['command'] = $legacyConfig['pdnssec_command'];
        }

        // PowerDNS API settings
        if (isset($legacyConfig['pdns_api_url'])) {
            $newConfig['pdns_api']['url'] = $legacyConfig['pdns_api_url'];
        }
        if (isset($legacyConfig['pdns_api_key'])) {
            $newConfig['pdns_api']['key'] = $legacyConfig['pdns_api_key'];
        }

        // Logging settings with type conversion
        $loggingMapping = [
            'logger_type' => ['key' => 'type', 'type' => 'string'],
            'logger_level' => ['key' => 'level', 'type' => 'string'],
            'dblog_use' => ['key' => 'database_enabled', 'type' => 'bool'],
            'syslog_use' => ['key' => 'syslog_enabled', 'type' => 'bool'],
            'syslog_ident' => ['key' => 'syslog_identity', 'type' => 'string'],
            'syslog_facility' => ['key' => 'syslog_facility', 'type' => 'int'],
        ];

        foreach ($loggingMapping as $oldKey => $config) {
            if (array_key_exists($oldKey, $legacyConfig)) {
                $newConfig['logging'][$config['key']] = $this->convertType($legacyConfig[$oldKey], $config['type']);
            }
        }

        // LDAP settings
        if (isset($legacyConfig['ldap_use'])) {
            $newConfig['ldap']['enabled'] = $legacyConfig['ldap_use'];
        }
        if (isset($legacyConfig['ldap_debug'])) {
            $newConfig['ldap']['debug'] = $legacyConfig['ldap_debug'];
        }
        if (isset($legacyConfig['ldap_uri'])) {
            $newConfig['ldap']['uri'] = $legacyConfig['ldap_uri'];
        }
        if (isset($legacyConfig['ldap_basedn'])) {
            $newConfig['ldap']['base_dn'] = $legacyConfig['ldap_basedn'];
        }
        if (isset($legacyConfig['ldap_binddn'])) {
            $newConfig['ldap']['bind_dn'] = $legacyConfig['ldap_binddn'];
        }
        if (isset($legacyConfig['ldap_bindpw'])) {
            $newConfig['ldap']['bind_password'] = $legacyConfig['ldap_bindpw'];
        }
        if (isset($legacyConfig['ldap_user_attribute'])) {
            $newConfig['ldap']['user_attribute'] = $legacyConfig['ldap_user_attribute'];
        }
        if (isset($legacyConfig['ldap_proto'])) {
            $newConfig['ldap']['protocol_version'] = $legacyConfig['ldap_proto'];
        }
        if (isset($legacyConfig['ldap_search_filter'])) {
            $newConfig['ldap']['search_filter'] = $legacyConfig['ldap_search_filter'];
        }

        // Miscellaneous settings
        if (isset($legacyConfig['display_stats'])) {
            $newConfig['misc']['display_stats'] = $legacyConfig['display_stats'];
        }
        if (isset($legacyConfig['timezone'])) {
            $newConfig['misc']['timezone'] = $legacyConfig['timezone'];
        }
        if (isset($legacyConfig['record_comments_sync'])) {
            $newConfig['misc']['record_comments_sync'] = $legacyConfig['record_comments_sync'];
        }
        if (isset($legacyConfig['experimental_edit_conflict_resolution'])) {
            $newConfig['misc']['edit_conflict_resolution'] = $legacyConfig['experimental_edit_conflict_resolution'];
        }

        // We don't include mail settings
        // as they're new in 4.0.0 and we want to use defaults from settings.defaults.php

        return $newConfig;
    }

    /**
     * Load legacy config directly from file
     */
    private function loadLegacyConfigFile(string $filePath): array
    {
        $config = [];

        // Include the file to load variables
        include $filePath;

        // Extract PHP variables from included file
        $extractedVars = get_defined_vars();

        // Process each variable
        foreach ($extractedVars as $name => $value) {
            // Skip local variables and this object
            if ($name !== 'this' && $name !== 'filePath' && $name !== 'config') {
                $config[$name] = $value;
            }
        }

        return $config;
    }

    /**
     * Convert value to specified type
     */
    private function convertType(mixed $value, string $type): mixed
    {
        return match ($type) {
            'bool' => $this->convertToBool($value),
            'int' => (int) $value,
            'string' => (string) $value,
            default => $value,
        };
    }

    /**
     * Convert various boolean representations to actual boolean
     */
    private function convertToBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $trimmedValue = strtolower(trim($value));
            if (in_array($trimmedValue, ['true', '1', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($trimmedValue, ['false', '0', 'no', 'off', ''], true)) {
                return false;
            }
            // For any other string, return true (non-empty string is truthy)
            return !empty($trimmedValue);
        }

        if (is_numeric($value)) {
            return (bool) $value;
        }

        return (bool) $value;
    }
}
