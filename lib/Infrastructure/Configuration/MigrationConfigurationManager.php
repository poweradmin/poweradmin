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

        // Database settings
        foreach (['db_host', 'db_port', 'db_user', 'db_pass', 'db_name', 'db_type', 'db_charset', 'db_file', 'db_debug'] as $key) {
            if (isset($legacyConfig[$key])) {
                $newKey = str_replace('db_', '', $key);
                if ($newKey === 'pass') {
                    $newKey = 'password';
                }
                $newConfig['database'][$newKey] = $legacyConfig[$key];
            }
        }

        // Specific fix for pdns_db_name
        if (isset($legacyConfig['pdns_db_name'])) {
            $newConfig['database']['pdns_db_name'] = $legacyConfig['pdns_db_name'];
        }

        // Security settings
        foreach (['session_key', 'password_encryption', 'password_encryption_cost', 'login_token_validation', 'global_token_validation'] as $key) {
            if (isset($legacyConfig[$key])) {
                $newKey = $key;
                if ($key === 'password_encryption_cost') {
                    $newKey = 'password_cost';
                }
                $newConfig['security'][$newKey] = $legacyConfig[$key];
            }
        }

        // We're not setting password_policy and account_lockout defaults
        // as they are new in 4.0.0 and we want to use the defaults from settings.defaults.php

        // Interface settings mapping
        $interfaceMapping = [
            'iface_lang' => 'language',
            'iface_enabled_languages' => 'enabled_languages',
            'iface_style' => 'style',  // Changed from 'theme' to 'style'
            'iface_templates' => 'theme_base_path',  // Updated mapping
            'iface_title' => 'title',
            'iface_expire' => 'session_timeout',
            'iface_rowamount' => 'rows_per_page',
            'iface_zonelist_serial' => 'display_serial_in_zone_list',
            'iface_zonelist_template' => 'display_template_in_zone_list',
            'iface_edit_show_id' => 'show_record_id',
            'iface_edit_add_record_top' => 'position_record_form_top',
            'iface_edit_save_changes_top' => 'position_save_button_top',
            'iface_zone_comments' => 'show_zone_comments',
            'iface_record_comments' => 'show_record_comments',
            'iface_search_group_records' => 'search_group_records',
            'iface_add_reverse_record' => 'add_reverse_record',
            'iface_add_domain_record' => 'add_domain_record',
            'iface_migrations_show' => 'show_migrations',
        ];

        // Process interface settings
        foreach ($interfaceMapping as $oldKey => $newKey) {
            if (isset($legacyConfig[$oldKey])) {
                $newConfig['interface'][$newKey] = $legacyConfig[$oldKey];
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

        // DNS settings
        foreach (['dns_hostmaster', 'dns_ns1', 'dns_ns2', 'dns_ns3', 'dns_ns4', 'dns_ttl', 'dns_strict_tld_check', 'dns_top_level_tld_check', 'dns_third_level_check', 'dns_txt_auto_quote'] as $key) {
            if (isset($legacyConfig[$key])) {
                $newKey = str_replace('dns_', '', $key);
                $newConfig['dns'][$newKey] = $legacyConfig[$key];
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

        // Logging settings
        if (isset($legacyConfig['logger_type'])) {
            $newConfig['logging']['type'] = $legacyConfig['logger_type'];
        }
        if (isset($legacyConfig['logger_level'])) {
            $newConfig['logging']['level'] = $legacyConfig['logger_level'];
        }
        if (isset($legacyConfig['dblog_use'])) {
            $newConfig['logging']['database_enabled'] = $legacyConfig['dblog_use'];
        }
        if (isset($legacyConfig['syslog_use'])) {
            $newConfig['logging']['syslog_enabled'] = $legacyConfig['syslog_use'];
        }
        if (isset($legacyConfig['syslog_ident'])) {
            $newConfig['logging']['syslog_identity'] = $legacyConfig['syslog_ident'];
        }
        if (isset($legacyConfig['syslog_facility'])) {
            $newConfig['logging']['syslog_facility'] = $legacyConfig['syslog_facility'];
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
}
