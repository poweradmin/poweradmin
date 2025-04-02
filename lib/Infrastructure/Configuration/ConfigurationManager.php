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

use Poweradmin\Infrastructure\Service\MessageService;

/**
 * Class ConfigurationManager
 *
 * This class is responsible for loading and accessing configuration values.
 * It combines legacy configuration, defaults, and the new settings structure.
 */
class ConfigurationManager
{
    private static ?ConfigurationManager $instance = null;
    private array $settings = [];
    private bool $initialized = false;

    /**
     * Private constructor to prevent direct instantiation
     */
    private function __construct()
    {
    }

    /**
     * Get the singleton instance
     * 
     * @return ConfigurationManager
     */
    public static function getInstance(): ConfigurationManager
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        
        return self::$instance;
    }

    /**
     * Initialize the configuration
     * 
     * @return void
     */
    public function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        // Load default values from legacy config if it exists
        // This ensures backward compatibility
        $legacyConfig = [];
        $legacyDefaultConfigFile = __DIR__ . '/../../../inc/config-defaults.inc.php';
        $legacyCustomConfigFile = __DIR__ . '/../../../inc/config.inc.php';

        if (file_exists($legacyDefaultConfigFile)) {
            $legacyConfig = $this->loadLegacyConfig($legacyDefaultConfigFile);
        }

        // Override with custom legacy config if it exists
        if (file_exists($legacyCustomConfigFile)) {
            $legacyConfig = array_merge($legacyConfig, $this->loadLegacyConfig($legacyCustomConfigFile));
        }

        // Convert legacy config to new structure
        $this->settings = $this->convertLegacyConfig($legacyConfig);

        // Load new configuration file if it exists
        $newConfigFile = __DIR__ . '/../../../config/settings.php';
        if (file_exists($newConfigFile)) {
            $newSettings = require $newConfigFile;
            if (is_array($newSettings)) {
                $this->settings = array_replace_recursive($this->settings, $newSettings);
            }
        }

        $this->initialized = true;
    }

    /**
     * Load configuration values from a legacy config file
     * 
     * @param string $filePath Path to the configuration file
     * @return array Parsed configuration settings
     */
    private function loadLegacyConfig(string $filePath): array
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
     * Convert legacy configuration to new structure
     * 
     * @param array $legacyConfig Legacy configuration array
     * @return array Converted configuration
     */
    private function convertLegacyConfig(array $legacyConfig): array
    {
        $newConfig = [
            'database' => [],
            'security' => [],
            'interface' => [],
            'dns' => [],
            'mail' => [],
            'dnssec' => [],
            'pdns_api' => [],
            'logging' => [],
            'ldap' => [],
            'misc' => [],
        ];

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

        // Security settings
        if (isset($legacyConfig['session_key'])) {
            $newConfig['security']['session_key'] = $legacyConfig['session_key'];
        }
        if (isset($legacyConfig['password_encryption'])) {
            $newConfig['security']['password_encryption'] = $legacyConfig['password_encryption'];
        }
        if (isset($legacyConfig['password_encryption_cost'])) {
            $newConfig['security']['password_cost'] = $legacyConfig['password_encryption_cost'];
        }
        if (isset($legacyConfig['login_token_validation'])) {
            $newConfig['security']['login_token_validation'] = $legacyConfig['login_token_validation'];
        }
        if (isset($legacyConfig['global_token_validation'])) {
            $newConfig['security']['global_token_validation'] = $legacyConfig['global_token_validation'];
        }

        // Interface settings
        foreach ($legacyConfig as $key => $value) {
            if (strpos($key, 'iface_') === 0) {
                $newKey = str_replace('iface_', '', $key);
                
                // Map special cases
                switch ($newKey) {
                    case 'lang':
                        $newKey = 'language';
                        break;
                    case 'enabled_languages':
                        $newKey = 'enabled_languages';
                        break;
                    case 'style':
                        $newKey = 'theme';
                        break;
                    case 'expire':
                        $newKey = 'session_timeout';
                        break;
                    case 'rowamount':
                        $newKey = 'rows_per_page';
                        break;
                    case 'zonelist_serial':
                        $newKey = 'display_serial_in_zone_list';
                        break;
                    case 'zonelist_template':
                        $newKey = 'display_template_in_zone_list';
                        break;
                    case 'edit_show_id':
                        $newKey = 'show_record_id';
                        break;
                    case 'edit_add_record_top':
                        $newKey = 'position_record_form_top';
                        break;
                    case 'edit_save_changes_top':
                        $newKey = 'position_save_button_top';
                        break;
                    case 'zone_comments':
                        $newKey = 'show_zone_comments';
                        break;
                    case 'record_comments':
                        $newKey = 'show_record_comments';
                        break;
                    case 'search_group_records':
                        $newKey = 'search_group_records';
                        break;
                    case 'add_reverse_record':
                        $newKey = 'add_reverse_record';
                        break;
                    case 'add_domain_record':
                        $newKey = 'add_domain_record';
                        break;
                    case 'migrations_show':
                        $newKey = 'show_migrations';
                        break;
                }
                
                $newConfig['interface'][$newKey] = $value;
            }
        }

        // DNS settings
        foreach (['dns_hostmaster', 'dns_ns1', 'dns_ns2', 'dns_ns3', 'dns_ns4', 'dns_ttl', 'dns_soa', 'dns_strict_tld_check', 'dns_top_level_tld_check', 'dns_third_level_check', 'dns_txt_auto_quote'] as $key) {
            if (isset($legacyConfig[$key])) {
                $newKey = str_replace('dns_', '', $key);
                
                // Handle SOA values specifically
                if ($newKey === 'soa' && is_string($legacyConfig[$key])) {
                    $soaValues = explode(' ', $legacyConfig[$key]);
                    if (count($soaValues) === 4) {
                        $newConfig['dns']['soa_refresh'] = (int) $soaValues[0];
                        $newConfig['dns']['soa_retry'] = (int) $soaValues[1];
                        $newConfig['dns']['soa_expire'] = (int) $soaValues[2];
                        $newConfig['dns']['soa_minimum'] = (int) $soaValues[3];
                    }
                } else {
                    $newConfig['dns'][$newKey] = $legacyConfig[$key];
                }
            }
        }

        // Add zone_type_default
        if (isset($legacyConfig['iface_zone_type_default'])) {
            $newConfig['dns']['zone_type_default'] = $legacyConfig['iface_zone_type_default'];
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

        return $newConfig;
    }

    /**
     * Get a configuration value by its key
     * 
     * @param string $group Configuration group
     * @param string $key Configuration key
     * @param mixed $default Default value if not found
     * @return mixed Configuration value
     */
    public function get(string $group, string $key, mixed $default = null): mixed
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        if (isset($this->settings[$group][$key])) {
            return $this->settings[$group][$key];
        }

        return $default;
    }

    /**
     * Get an entire configuration group
     * 
     * @param string $group Configuration group
     * @return array Configuration group values
     */
    public function getGroup(string $group): array
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        return $this->settings[$group] ?? [];
    }

    /**
     * Get all configuration settings
     * 
     * @return array All settings
     */
    public function getAll(): array
    {
        if (!$this->initialized) {
            $this->initialize();
        }

        return $this->settings;
    }
}