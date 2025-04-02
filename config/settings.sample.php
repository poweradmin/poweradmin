<?php
/**
 * Poweradmin Settings Configuration File
 * 
 * Copy this file to settings.php and modify to customize your installation.
 * This file contains all configuration settings that override defaults.
 */

return [
    /**
     * Database Settings
     */
    'database' => [
        'host' => 'localhost',
        'port' => '3306',
        'user' => 'poweradmin',
        'password' => 'your_password',
        'name' => 'powerdns',
        'type' => 'mysql',     // Options: 'mysql', 'pgsql', 'sqlite'
        'charset' => 'utf8',
        'file' => '',          // Only used for SQLite, provide full path to database file
        'debug' => false,      // Show all SQL queries
    ],

    /**
     * Security Settings
     */
    'security' => [
        'session_key' => 'change_this_key',      // IMPORTANT: Change this to a unique random string
        'password_encryption' => 'bcrypt',       // Options: 'md5', 'md5salt', 'bcrypt', 'argon2i', 'argon2id'
        'password_cost' => 12,                   // Cost factor for bcrypt
        'login_token_validation' => true,        // Enable token validation for login form
        'global_token_validation' => true,       // Enable token validation for all forms
    ],

    /**
     * Interface Settings
     */
    'interface' => [
        'language' => 'en_EN',                // Default language for the interface
        'enabled_languages' => 'cs_CZ,de_DE,en_EN,fr_FR,it_IT,ja_JP,lt_LT,nb_NO,nl_NL,pl_PL,ru_RU,tr_TR,zh_CN',
        'theme' => 'ignite',                  // Options: 'ignite' (light), 'spark' (dark)
        'title' => 'Poweradmin',              // Title displayed in the browser
        'session_timeout' => 1800,            // Session timeout in seconds (30 minutes)
        'rows_per_page' => 10,
        'index_display' => 'cards',           // Options: 'cards', 'list'
        
        // UI Element Settings
        'show_record_id' => true,             // Show record ID column in edit mode
        'position_record_form_top' => false,  // Position the "Add record" form at the top of the page
        'position_save_button_top' => true,   // Position the "Save changes" button at the top of the page
        'show_zone_comments' => true,         // Show or hide zone comments
        'show_record_comments' => false,      // Show or hide record comments
        'display_serial_in_zone_list' => false,
        'display_template_in_zone_list' => false,
        'search_group_records' => false,      // Group records by name and content in search results
        
        // Zone Editing Features
        'add_reverse_record' => true,         // Enable checkbox to add PTR record from regular zone view
        'add_domain_record' => true,          // Enable checkbox to add A/AAAA record from reverse zone view
        'show_migrations' => false,           // Show migrations menu item (experimental)
    ],

    /**
     * DNS Settings
     */
    'dns' => [
        'hostmaster' => 'hostmaster.example.com',  // Default hostmaster email address
        'ns1' => 'ns1.example.com',
        'ns2' => 'ns2.example.com',
        'ns3' => '',
        'ns4' => '',
        'ttl' => 86400,                            // Default TTL for new records (86400 = 24 hours)
        
        // SOA Record Settings
        'soa_refresh' => 28800,                    // 8 hours
        'soa_retry' => 7200,                       // 2 hours
        'soa_expire' => 604800,                    // 1 week
        'soa_minimum' => 86400,                    // 24 hours
        
        'zone_type_default' => 'MASTER',           // Options: 'MASTER', 'NATIVE'
        
        // Validation Settings
        'strict_tld_check' => false,               // Strict validation of TLDs
        'top_level_tld_check' => false,            // Prevent creation of top-level domains
        'third_level_check' => false,              // Prevent creation of third-level domains
        'txt_auto_quote' => false,                 // Automatically quote TXT records
    ],

    /**
     * Mail Settings
     */
    'mail' => [
        'enabled' => false,                        // Enable email functionality
        'from' => 'poweradmin@example.com',        // Default "from" address
        'host' => 'smtp.example.com',              // SMTP server hostname
        'port' => 587,                             // SMTP server port
        'username' => '',                          // SMTP authentication username
        'password' => '',                          // SMTP authentication password
        'encryption' => 'tls',                     // Options: 'tls', 'ssl', ''
    ],
    
    /**
     * DNSSEC Settings
     */
    'dnssec' => [
        'enabled' => false,                        // Enable DNSSEC functionality
        'debug' => false,                          // Enable DNSSEC debug logging
        'command' => '/usr/bin/pdnsutil',          // Path to pdnsutil command
    ],
    
    /**
     * PowerDNS API Settings
     */
    'pdns_api' => [
        'url' => '',                               // PowerDNS API URL, e.g., 'http://127.0.0.1:8081'
        'key' => '',                               // PowerDNS API key
    ],
    
    /**
     * Logging Settings
     */
    'logging' => [
        'type' => 'null',                          // Options: 'null', 'native'
        'level' => 'info',                         // Options: 'debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'
        'database_enabled' => false,               // Enable logging zone and record changes to the database
        
        // Syslog Settings
        'syslog_enabled' => false,                 // Write authentication attempts to syslog
        'syslog_identity' => 'poweradmin',         // Syslog identity
        'syslog_facility' => LOG_USER,             // Syslog facility
    ],
    
    /**
     * LDAP Settings
     */
    'ldap' => [
        'enabled' => false,                        // Enable LDAP authentication
        'debug' => false,                          // Enable LDAP debug logging
        'uri' => 'ldap://domaincontroller.example.com',  // LDAP server URI
        'base_dn' => 'ou=users,dc=example,dc=com',      // Base DN where users are stored
        'bind_dn' => 'cn=admin,dc=example,dc=com',      // Bind DN for LDAP authentication
        'bind_password' => 'some_password',             // Bind password for LDAP authentication
        'user_attribute' => 'uid',                      // User attribute (uid for OpenLDAP, sAMAccountName for Active Directory)
        'protocol_version' => 3,                        // LDAP protocol version
        'search_filter' => '',                          // Additional search filter
    ],
    
    /**
     * Miscellaneous Settings
     */
    'misc' => [
        'display_stats' => false,                      // Display memory usage and execution time
        'timezone' => 'UTC',                           // Default timezone
        'record_comments_sync' => false,               // Enable bidirectional comment sync between A and PTR records
        'edit_conflict_resolution' => 'last_writer_wins', // Options: 'last_writer_wins', 'only_latest_version', '3_way_merge'
    ],
];