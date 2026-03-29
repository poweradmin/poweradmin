<?php

/**
 * Poweradmin Settings Configuration File (MySQL + SQL Backend, Subfolder)
 *
 * Devcontainer configuration for testing subfolder deployments.
 * Reuses the MySQL/MariaDB database with base_url_prefix set.
 */

return [
    /**
     * Database Settings
     */
    'database' => [
        'host' => 'mariadb',
        'port' => '3306',
        'name' => 'poweradmin',
        'user' => 'pdns',
        'password' => 'poweradmin',
        'type' => 'mysql',
        'charset' => 'utf8',
        'pdns_db_name' => 'pdns',
    ],

    /**
     * Security Settings
     */
    'security' => [
        'session_key' => 'subfolder_session_key_for_testing_only_12345',
        'password_encryption' => 'bcrypt',
        'login_token_validation' => false,
        'global_token_validation' => false,
        'mfa' => [
            'enabled' => false,
            'enforced' => false,
        ],
    ],

    /**
     * Interface Settings
     */
    'interface' => [
        'language' => 'en_EN',
        'title' => 'Poweradmin (MySQL + Subfolder)',
        'base_url_prefix' => '/poweradmin',
        'show_pdns_status' => true,
        'show_record_comments' => true,
        'show_zone_comments' => false,
        'enable_consistency_checks' => true,
        'add_reverse_record' => true,
    ],

    /**
     * DNS Settings
     */
    'dns' => [
        'hostmaster' => 'hostmaster.example.com',
        'ns1' => 'ns1.example.com',
        'ns2' => 'ns2.example.com',
    ],

    /**
     * DNSSEC Settings
     */
    'dnssec' => [
        'enabled' => true,
    ],

    /**
     * Miscellaneous Settings
     */
    'misc' => [
        'record_comments_sync' => true,
    ],

    /**
     * API Settings
     */
    'api' => [
        'enabled' => true,
        'docs_enabled' => true,
    ],

    /**
     * PowerDNS API Settings
     */
    'pdns_api' => [
        'display_name' => 'PowerDNS',
        'url' => 'http://pdns-mysql:8081',
        'key' => 'fxiBmBFx7MITw5ECRMOr10ghlxGMvWZA',
        'server_name' => 'localhost',
        'webserver_username' => '',
        'webserver_password' => 'poweradmin',
    ],

    /**
     * Logging Settings
     */
    'logging' => [
        'type' => 'native',
        'level' => 'debug',
        'database_enabled' => true,
        'syslog_enabled' => false,
    ],
];
