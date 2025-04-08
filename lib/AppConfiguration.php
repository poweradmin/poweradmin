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

namespace Poweradmin;

use Poweradmin\Infrastructure\Configuration\ConfigurationInterface;

/**
 * Class AppConfiguration
 *
 * This class is a legacy adapter for backward compatibility.
 * It now delegates to the ConfigurationManager for all operations.
 *
 * @package Poweradmin
 * @deprecated Use ConfigurationManager directly instead
 */
class AppConfiguration implements ConfigurationInterface
{
    /**
     * @var array The configuration settings.
     */
    protected array $config;

    /**
     * AppConfiguration constructor.
     *
     * @param string $defaultConfigFile Ignored, kept for backward compatibility
     * @param string $customConfigFile Ignored, kept for backward compatibility
     */
    public function __construct(
        string $defaultConfigFile = '',
        string $customConfigFile = ''
    ) {
        // Get configuration from the ConfigurationManager
        $configManager = \Poweradmin\Infrastructure\Configuration\ConfigurationManager::getInstance();
        $configManager->initialize();
        $this->config = $this->convertToLegacyFormat($configManager->getAll());
    }

    /**
     * Converts the new configuration format to the legacy format for backward compatibility
     *
     * @param array $newConfig The configuration in the new format
     * @return array The configuration in the legacy format
     */
    private function convertToLegacyFormat(array $newConfig): array
    {
        $legacyConfig = [];

        // Database settings
        if (isset($newConfig['database'])) {
            $legacyConfig['db_host'] = $newConfig['database']['host'] ?? '';
            $legacyConfig['db_port'] = $newConfig['database']['port'] ?? '';
            $legacyConfig['db_user'] = $newConfig['database']['user'] ?? '';
            $legacyConfig['db_pass'] = $newConfig['database']['password'] ?? '';
            $legacyConfig['db_name'] = $newConfig['database']['name'] ?? '';
            $legacyConfig['db_type'] = $newConfig['database']['type'] ?? '';
            $legacyConfig['db_charset'] = $newConfig['database']['charset'] ?? '';
            $legacyConfig['db_file'] = $newConfig['database']['file'] ?? '';
            $legacyConfig['db_debug'] = $newConfig['database']['debug'] ?? false;
            $legacyConfig['pdns_db_name'] = $newConfig['database']['pdns_name'] ?? '';
        }

        // Security settings
        if (isset($newConfig['security'])) {
            $legacyConfig['session_key'] = $newConfig['security']['session_key'] ?? '';
            $legacyConfig['password_encryption'] = $newConfig['security']['password_encryption'] ?? 'bcrypt';
            $legacyConfig['password_encryption_cost'] = $newConfig['security']['password_cost'] ?? 12;
            $legacyConfig['login_token_validation'] = $newConfig['security']['login_token_validation'] ?? true;
            $legacyConfig['global_token_validation'] = $newConfig['security']['global_token_validation'] ?? true;
        }

        // Interface settings
        if (isset($newConfig['interface'])) {
            $legacyConfig['iface_lang'] = $newConfig['interface']['language'] ?? 'en_EN';
            $legacyConfig['iface_enabled_languages'] = $newConfig['interface']['enabled_languages'] ?? 'en_EN';
            $legacyConfig['iface_style'] = $newConfig['interface']['theme'] ?? 'ignite';
            $legacyConfig['iface_templates'] = $newConfig['interface']['templates_path'] ?? 'templates';
            $legacyConfig['iface_rowamount'] = $newConfig['interface']['rows_per_page'] ?? 10;
            $legacyConfig['iface_expire'] = $newConfig['interface']['session_timeout'] ?? 1800;
            $legacyConfig['iface_zonelist_serial'] = $newConfig['interface']['display_serial_in_zone_list'] ?? false;
            $legacyConfig['iface_zonelist_template'] = $newConfig['interface']['display_template_in_zone_list'] ?? false;
            $legacyConfig['iface_title'] = $newConfig['interface']['title'] ?? 'Poweradmin';
            $legacyConfig['iface_add_reverse_record'] = $newConfig['interface']['add_reverse_record'] ?? true;
            $legacyConfig['iface_add_domain_record'] = $newConfig['interface']['add_domain_record'] ?? true;
            $legacyConfig['iface_zone_type_default'] = $newConfig['interface']['zone_type_default'] ?? 'MASTER';
            $legacyConfig['iface_zone_comments'] = $newConfig['interface']['show_zone_comments'] ?? true;
            $legacyConfig['iface_record_comments'] = $newConfig['interface']['show_record_comments'] ?? false;
            $legacyConfig['iface_index'] = $newConfig['interface']['index_display'] ?? 'cards';
            $legacyConfig['iface_search_group_records'] = $newConfig['interface']['search_group_records'] ?? false;
            $legacyConfig['iface_migrations_show'] = $newConfig['interface']['show_migrations'] ?? false;
        }

        // DNS settings
        if (isset($newConfig['dns'])) {
            $legacyConfig['dns_hostmaster'] = $newConfig['dns']['hostmaster'] ?? '';
            $legacyConfig['dns_ns1'] = $newConfig['dns']['ns1'] ?? '';
            $legacyConfig['dns_ns2'] = $newConfig['dns']['ns2'] ?? '';
            $legacyConfig['dns_ns3'] = $newConfig['dns']['ns3'] ?? '';
            $legacyConfig['dns_ns4'] = $newConfig['dns']['ns4'] ?? '';
            $legacyConfig['dns_ttl'] = $newConfig['dns']['ttl'] ?? 86400;
            $legacyConfig['dns_strict_tld_check'] = $newConfig['dns']['strict_tld_check'] ?? false;
            $legacyConfig['dns_top_level_tld_check'] = $newConfig['dns']['top_level_tld_check'] ?? false;
            $legacyConfig['dns_third_level_check'] = $newConfig['dns']['third_level_check'] ?? false;
            $legacyConfig['dns_txt_auto_quote'] = $newConfig['dns']['txt_auto_quote'] ?? false;

            // Construct SOA string from individual values
            if (
                isset($newConfig['dns']['soa_refresh']) && isset($newConfig['dns']['soa_retry']) &&
                isset($newConfig['dns']['soa_expire']) && isset($newConfig['dns']['soa_minimum'])
            ) {
                $legacyConfig['dns_soa'] = sprintf(
                    '%d %d %d %d',
                    $newConfig['dns']['soa_refresh'],
                    $newConfig['dns']['soa_retry'],
                    $newConfig['dns']['soa_expire'],
                    $newConfig['dns']['soa_minimum']
                );
            } else {
                $legacyConfig['dns_soa'] = '28800 7200 604800 86400';
            }
        }

        // DNSSEC settings
        if (isset($newConfig['dnssec'])) {
            $legacyConfig['pdnssec_use'] = $newConfig['dnssec']['enabled'] ?? false;
            $legacyConfig['pdnssec_debug'] = $newConfig['dnssec']['debug'] ?? false;
            $legacyConfig['pdnssec_command'] = $newConfig['dnssec']['command'] ?? '';
        }

        // PowerDNS API settings
        if (isset($newConfig['pdns_api'])) {
            $legacyConfig['pdns_api_url'] = $newConfig['pdns_api']['url'] ?? '';
            $legacyConfig['pdns_api_key'] = $newConfig['pdns_api']['key'] ?? '';
        }

        // Logging settings
        if (isset($newConfig['logging'])) {
            $legacyConfig['logger_type'] = $newConfig['logging']['type'] ?? 'null';
            $legacyConfig['logger_level'] = $newConfig['logging']['level'] ?? 'info';
            $legacyConfig['dblog_use'] = $newConfig['logging']['database_enabled'] ?? false;
            $legacyConfig['syslog_use'] = $newConfig['logging']['syslog_enabled'] ?? false;
            $legacyConfig['syslog_ident'] = $newConfig['logging']['syslog_identity'] ?? 'poweradmin';
            $legacyConfig['syslog_facility'] = $newConfig['logging']['syslog_facility'] ?? LOG_USER;
        }

        // LDAP settings
        if (isset($newConfig['ldap'])) {
            $legacyConfig['ldap_use'] = $newConfig['ldap']['enabled'] ?? false;
            $legacyConfig['ldap_debug'] = $newConfig['ldap']['debug'] ?? false;
            $legacyConfig['ldap_uri'] = $newConfig['ldap']['uri'] ?? '';
            $legacyConfig['ldap_basedn'] = $newConfig['ldap']['base_dn'] ?? '';
            $legacyConfig['ldap_binddn'] = $newConfig['ldap']['bind_dn'] ?? '';
            $legacyConfig['ldap_bindpw'] = $newConfig['ldap']['bind_password'] ?? '';
            $legacyConfig['ldap_user_attribute'] = $newConfig['ldap']['user_attribute'] ?? '';
            $legacyConfig['ldap_proto'] = $newConfig['ldap']['protocol_version'] ?? 3;
            $legacyConfig['ldap_search_filter'] = $newConfig['ldap']['search_filter'] ?? '';
        }

        // Misc settings
        if (isset($newConfig['misc'])) {
            $legacyConfig['display_stats'] = $newConfig['misc']['display_stats'] ?? false;
            $legacyConfig['timezone'] = $newConfig['misc']['timezone'] ?? '';
            $legacyConfig['record_comments_sync'] = $newConfig['misc']['record_comments_sync'] ?? false;
            $legacyConfig['experimental_edit_conflict_resolution'] = $newConfig['misc']['edit_conflict_resolution'] ?? 'last_writer_wins';
            $legacyConfig['display_errors'] = $newConfig['misc']['display_errors'] ?? false;
        }

        return $legacyConfig;
    }

    /**
     * Gets a configuration value.
     *
     * @param string|null $key The name of the configuration setting.
     * @param mixed $default Default value to return if the key is not found.
     * @return mixed The configuration value or the default value if the key is not found.
     */
    public function get(?string $key = null, mixed $default = null): mixed
    {
        // If no key is provided, return all config
        if ($key === null) {
            return $this->getAll();
        }

        // Check for legacy config keys first for backward compatibility
        if (array_key_exists($key, $this->config)) {
            return $this->config[$key];
        }

        // Otherwise return the default value
        return $default;
    }

    /**
     * Gets all configuration values.
     *
     * @return array All configuration settings.
     */
    public function getAll(): array
    {
        return $this->config;
    }
}
