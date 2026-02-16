<?php

/**
 * Poweradmin Settings Configuration File (SQLite)
 *
 * Devcontainer configuration for SQLite database
 */

return [
    /**
     * Database Settings
     */
    'database' => [
        'type' => 'sqlite',
        'file' => '/data/pdns.db',
    ],

    /**
     * Security Settings
     */
    'security' => [
        'session_key' => 'YV4@SQ(Wa8l8L7fDlU3e_(XhuCyuuEifm68Xtuh!MNE#!L',
        'password_encryption' => 'bcrypt',
        'login_token_validation' => false,
        'global_token_validation' => false,
        'mfa' => [
            'enabled' => true,
            'enforced' => false,
            'email_enabled' => true,
        ],
        'password_reset' => [
            'enabled' => true,
            'token_lifetime' => 3600,
        ],
        'username_recovery' => [
            'enabled' => true,
        ],
    ],

    /**
     * Mail Settings (Logger transport for development)
     */
    'mail' => [
        'enabled' => true,
        'transport' => 'logger',
        'from' => 'poweradmin@example.com',
    ],

    /**
     * Interface Settings
     */
    'interface' => [
        'language' => 'en_EN',
        'enabled_languages' => 'en_EN,de_DE,fr_FR,ja_JP,pl_PL',
        'title' => 'Poweradmin (SQLite)',
        'theme' => 'modern',
        'show_pdns_status' => true,
        'show_record_comments' => true,
        'show_zone_comments' => false,
        'enable_consistency_checks' => true,
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
     * User Agreement Settings
     */
    'user_agreement' => [
        'enabled' => false,
        'current_version' => '1.0',
        'require_on_version_change' => true,
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
        'url' => 'http://pdns-sqlite:8081',
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

    /**
     * LDAP Settings
     */
    'ldap' => [
        'enabled' => false,
    ],

    /**
     * WHOIS Settings
     */
    'whois' => [
        'enabled' => true,
        'restrict_to_admin' => false,
    ],

    /**
     * RDAP Settings
     */
    'rdap' => [
        'enabled' => true,
        'restrict_to_admin' => false,
    ],

    /**
     * DNS Wizard Settings
     */
    'dns_wizards' => [
        'enabled' => true,
    ],

    /**
     * Module Settings
     */
    'modules' => [
        'csv_export' => [
            'enabled' => true,
        ],
        'zone_import_export' => [
            'enabled' => true,
            'auto_ttl_value' => 300,
            'max_file_size' => 1048576,
        ],
    ],
];
