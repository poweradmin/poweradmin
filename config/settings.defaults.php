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
     *
     * WARNING: Passwords containing single quotes ('), double quotes ("), backslashes (\),
     * or line breaks will cause configuration file generation to fail during installation.
     * Use only alphanumeric characters and basic symbols in passwords.
     */
    'database' => [
        'host' => 'localhost',
        'port' => '',
        'user' => 'poweradmin',
        'password' => 'your_password',  // AVOID: quotes, backslashes, line breaks
        'name' => 'powerdns',
        'type' => 'mysql',     // Options: 'mysql', 'pgsql', 'sqlite' (mysqli added in 2.1.5, sqlite in 2.1.6)
        'charset' => 'latin1', // or 'utf8' (added in 2.1.8)
        'file' => '',          // Only used for SQLite, provide full path to database file (added in 2.1.6)
        'debug' => false,      // Show all SQL queries (added in 2.1.6)
        'pdns_db_name' => '',  // Separate database for PowerDNS (experimental, added in 3.8.0)
    ],

    /**
     * Security Settings
     */
    'security' => [
        'session_key' => 'change_this_key',      // IMPORTANT: Change this to a unique random string (default was p0w3r4dm1n, added in 2.1.6)
        'password_encryption' => 'bcrypt',       // Options: 'md5', 'md5salt', 'bcrypt', 'argon2i', 'argon2id' (added in 2.1.6)
        'password_cost' => 12,                   // Cost factor for bcrypt (added in 2.1.8)
        'login_token_validation' => true,        // Enable token validation for login form (added in 3.9.0)
        'global_token_validation' => true,       // Enable token validation for all forms (added in 3.9.0)
        /**
         * Password Policy Settings
         */
        'password_policy' => [
            // Basic password rules
            'enable_password_rules' => true,    // Enable password policy enforcement
            'min_length' => 6,                   // Minimum password length
            'require_uppercase' => true,         // Require at least one uppercase letter
            'require_lowercase' => true,         // Require at least one lowercase letter
            'require_numbers' => true,           // Require at least one number
            'require_special' => false,          // Require at least one special character
            'special_characters' => '!@#$%^&*()+-=[]{}|;:,.<>?',  // Allowed special characters
        ],
        /**
         * Account Lockout Settings
         */
        'account_lockout' => [
            'enable_lockout' => false,           // Enable account lockout after failed login attempts
            'lockout_attempts' => 5,             // Number of attempts before lockout
            'lockout_duration' => 15,            // Duration in minutes
            'track_ip_address' => true,          // Lock accounts based on IP address
            'clear_attempts_on_success' => true, // Clear failed attempts after successful login
            'whitelist_ip_addresses' => [],      // IP addresses to never lock out (supports IPs, CIDRs, wildcards) - takes priority over blacklist
            'blacklist_ip_addresses' => [],      // IP addresses to always block (supports IPs, CIDRs, wildcards)
        ],
        /**
         * Multi-Factor Authentication Settings
         */
        'mfa' => [
            'enabled' => false,                  // Enable MFA functionality
            'app_enabled' => true,               // Enable authenticator app option
            'email_enabled' => true,             // Enable email verification option
            'recovery_codes' => 8,               // Number of recovery codes to generate
            'recovery_code_length' => 10,        // Length of recovery codes
        ],
        /**
         * Password Reset Settings
         */
        'password_reset' => [
            'enabled' => false,                         // Enable/disable password reset functionality
            'token_lifetime' => 3600,                   // Token validity in seconds (1 hour default)
            'rate_limit_attempts' => 5,                 // Max reset attempts per time window
            'rate_limit_window' => 3600,                // Rate limit window in seconds (1 hour)
            'min_time_between_requests' => 60,          // Minimum seconds between requests (1 minute)
        ],
        /**
         * Username Recovery Settings
         */
        'username_recovery' => [
            'enabled' => false,                         // Enable/disable username recovery functionality
            'rate_limit_attempts' => 5,                 // Max recovery attempts per time window
            'rate_limit_window' => 3600,                // Rate limit window in seconds (1 hour)
            'min_time_between_requests' => 60,         // Minimum seconds between requests (1 minute)
        ],
        /**
         * Google reCAPTCHA Settings
         */
        'recaptcha' => [
            'enabled' => false,                  // Enable reCAPTCHA on login form
            'site_key' => '',                    // Your reCAPTCHA site key (public key)
            'secret_key' => '',                  // Your reCAPTCHA secret key (private key)
            'version' => 'v3',                   // reCAPTCHA version: 'v2' or 'v3'
            'v3_threshold' => 0.5,               // Score threshold for v3 (0.0 - 1.0)
        ],
    ],

    /**
     * Interface Settings
     */
    'interface' => [
        'language' => 'en_EN',                // Default language for the interface
        'enabled_languages' => 'cs_CZ,de_DE,en_EN,es_ES,fr_FR,it_IT,ja_JP,lt_LT,nb_NO,nl_NL,pl_PL,pt_PT,ru_RU,tr_TR,zh_CN', // Added in 3.8.0
        'title' => 'Poweradmin',              // Title displayed in the browser (added in 2.1.5)
        'session_timeout' => 1800,            // Session timeout in seconds (30 minutes)
        'rows_per_page' => 10,
        'theme' => 'default',                 // Theme name to use (default, custom, etc.) (added in 4.0.0)
        'style' => 'light',                   // UI Style options: 'light', 'dark' (added in 4.0.0)
        'theme_base_path' => 'templates',     // Base path for theme templates (added in 4.0.0)
        'base_url_prefix' => '',              // Base URL prefix for deployments (default: '', subdirectory example: '/poweradmin') (added in 4.1.0)
        'application_url' => '',              // Full application URL for emails and absolute links (default: auto-detect, example: 'https://dns.example.com/poweradmin') (added in 4.1.0)

        // UI Element Settings
        'show_record_id' => false,            // Show record ID column in edit mode (added in 3.9.0)
        'show_add_record_form' => false,      // Show or hide add record form (added in 4.1.0)
        'show_record_edit_button' => false,   // Show individual edit button per record (added in 4.1.0)
        'show_record_delete_button' => false, // Show individual delete button per record (added in 4.1.0)
        'position_record_form_top' => true,   // Position the "Add record" form at the top of the page (added in 3.9.0)
        'position_save_button_top' => false,  // Position the "Save changes" button at the top of the page (added in 3.9.0)
        'show_zone_comments' => true,         // Show or hide zone comments (added in 2.2.3)
        'show_record_comments' => false,      // Show or hide record comments (added in 3.9.0)
        'display_serial_in_zone_list' => false,
        'display_template_in_zone_list' => false,
        'display_fullname_in_zone_list' => false,  // Show user's full name instead of username in zone lists (added in 4.0.0)
        'search_group_records' => false,      // Group records by name and content in search results (added in 3.8.0)
        'reverse_zone_sort' => 'natural',     // Reverse zone sorting algorithm: 'natural' (default) or 'hierarchical' (experimental) (added in 4.0.0)
        'show_pdns_status' => false,          // Show PowerDNS server status page and dashboard card (added in 4.0.0)

        // Zone Editing Features
        'add_reverse_record' => true,         // Enable checkbox to add PTR record from regular zone view (added in 2.1.7)
        'add_domain_record' => true,          // Enable checkbox to add A/AAAA record from reverse zone view
        'display_hostname_only' => false,     // Display only hostname part in zone edit form (strips zone suffix) (added in 4.0.0)
        'enable_consistency_checks' => false, // Enable database consistency checks page (added in 4.0.0)

        // Avatar Settings
        'avatar_oauth_enabled' => false,      // Enable OAuth provider avatar images (default: false)
        'avatar_gravatar_enabled' => false,   // Enable Gravatar integration (default: false)
        'avatar_priority' => 'oauth',         // Avatar priority when both enabled: 'oauth' or 'gravatar'
        'avatar_size' => 40,                  // Default avatar size in pixels
        'avatar_cache_ttl' => 3600,           // Avatar cache TTL in seconds (1 hour)
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
        'soa_minimum' => 86400,                    // 24 hours (SOA settings added in 2.2.3)

        'zone_type_default' => 'MASTER',           // Options: 'MASTER', 'NATIVE' (added in 2.1.9)

        // Validation Settings
        'strict_tld_check' => false,               // Strict validation of TLDs
        'top_level_tld_check' => false,            // Prevent creation of top-level domains (added in 2.1.7)
        'third_level_check' => false,              // Prevent creation of third-level domains (added in 2.1.7)
        'txt_auto_quote' => false,                 // Automatically quote TXT records (added in 3.9.2)
        'prevent_duplicate_ptr' => true,           // Prevent creation of multiple PTR records for same IP in batch operations (added in 4.0.0)

        // Record Type Settings (added in 4.0.0)
        // Set to null to use all default types, or provide an array of specific types to show
        // When editing zone templates, the system will automatically show the combined list
        // of both domain_record_types and reverse_record_types

        // Common record types for domain zones (forward zones)
        'domain_record_types' => null,              // Uses default domain zone record types

        // Example to restrict domain types: uncomment and adjust as needed
        //'domain_record_types' => ['A', 'AAAA', 'CNAME', 'MX', 'NS', 'SOA', 'TXT', 'SRV', 'CAA'],

        // Common record types for reverse zones
        'reverse_record_types' => null,             // Uses default reverse zone record types

        // Example to restrict reverse types: uncomment and adjust as needed
        //'reverse_record_types' => ['PTR', 'NS', 'SOA', 'TXT', 'CNAME'],
    ],

    /**
     * DNS Wizard Settings
     */
    'dns_wizards' => [
        'enabled' => false,                                // Enable DNS record wizards (added in 4.1.0)
        'available_types' => ['DMARC', 'SPF', 'DKIM', 'CAA', 'TLSA', 'SRV'], // Available wizard types (added in 4.1.0)

        // CAA Provider Configuration
        // Based on industry research of major Certificate Authorities (added in 4.1.0)
        'caa_providers' => [
            // Most common CAs
            'letsencrypt.org' => "Let's Encrypt",
            'digicert.com' => 'DigiCert',
            'sectigo.com' => 'Sectigo (Comodo)',
            'comodoca.com' => 'Sectigo (legacy domain)',

            // Major cloud providers
            'awstrust.com' => 'Amazon Trust Services',
            'amazontrust.com' => 'Amazon Trust Services (alt)',
            'amazonaws.com' => 'AWS Certificate Manager',
            'pki.goog' => 'Google Trust Services',
            'cloudflare.com' => 'Cloudflare',

            // Other popular CAs
            'godaddy.com' => 'GoDaddy',
            'globalsign.com' => 'GlobalSign',
            'entrust.com' => 'Entrust',
            'entrust.net' => 'Entrust (legacy)',
            'ssl.com' => 'SSL.com',
            'buypass.com' => 'Buypass',
            'usertrust.com' => 'USERTrust (Sectigo)',

            // Special values
            ';' => 'Allow all CAs (not recommended)',
        ],
    ],

    /**
     * Mail Settings
     */
    'mail' => [
        'enabled' => true,                          // Enable email functionality
        'from' => 'poweradmin@example.com',         // Default "from" address
        'from_name' => '',                          // Default "from" name
        'return_path' => 'poweradmin@example.com',  // Default "Return-Path" address
        'transport' => 'php',                       // Transport method: smtp, sendmail, php, or logger

        // SMTP settings
        'host' => 'smtp.example.com',              // SMTP server hostname
        'port' => 587,                             // SMTP server port
        'username' => '',                          // SMTP authentication username
        'password' => '',                          // SMTP password - AVOID: quotes, backslashes, line breaks
        'encryption' => 'tls',                     // Options: 'tls', 'ssl', ''
        'auth' => false,                           // Whether SMTP requires authentication

        // Sendmail settings
        'sendmail_path' => '/usr/sbin/sendmail -bs', // Path to sendmail binary

        // Logger settings (for development/debugging)
        // When transport is set to 'logger', emails are logged to error_log and application logs
        // instead of being sent. This is useful for development and debugging password reset tokens.
    ],

    /**
     * Notification Settings
     */
    'notifications' => [
        'zone_access_enabled' => false,      // Enable/disable zone access change notifications
    ],

    /**
     * DNSSEC Settings
     */
    'dnssec' => [
        'enabled' => false,                        // Enable DNSSEC functionality (added in 2.1.7)
        'debug' => false,                          // Enable DNSSEC debug logging (added in 2.1.9)
    ],

    /**
     * PowerDNS API Settings
     */
    'pdns_api' => [
        'display_name' => 'PowerDNS',              // PowerDNS name to identify server
        'url' => '',                               // PowerDNS API URL, e.g., 'http://127.0.0.1:8081' (added in 3.7.0)
        'key' => '',                               // PowerDNS API key (added in 3.7.0)
        'server_name' => 'localhost',              // PowerDNS server name used in API calls (added in 4.0.0)
        'webserver_username' => '',                // PowerDNS webserver Basic Auth username (usually '#') (added in 4.0.3)
        'webserver_password' => '',                // PowerDNS webserver Basic Auth password (for /metrics endpoint) (added in 4.0.3)
    ],

    /**
     * Logging Settings
     */
    'logging' => [
        'type' => 'null',                          // Options: 'null', 'native' (added in 3.9.0)
        'level' => 'info',                         // Options: 'debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency' (added in 3.9.0)
        'database_enabled' => false,               // Enable logging zone and record changes to the database (added in 3.2.0)

        // Syslog Settings
        'syslog_enabled' => false,                 // Write authentication attempts to syslog (added in 2.1.6)
        'syslog_identity' => 'poweradmin',         // Syslog identity (added in 2.1.6)
        'syslog_facility' => LOG_USER,             // Syslog facility (added in 2.1.6)
    ],

    /**
     * LDAP Settings
     *
     * WARNING: LDAP bind passwords containing single quotes ('), double quotes ("),
     * backslashes (\), or line breaks will cause configuration file generation to fail.
     * Use only alphanumeric characters and basic symbols in passwords.
     */
    'ldap' => [
        'enabled' => false,                        // Enable LDAP authentication (added in 2.1.7)
        'debug' => false,                          // Enable LDAP debug logging (added in 2.1.7)
        'uri' => 'ldap://domaincontroller.example.com',  // LDAP server URI (added in 2.1.7)
        'base_dn' => 'ou=users,dc=example,dc=com',      // Base DN where users are stored (added in 2.1.7)
        'bind_dn' => 'cn=admin,dc=example,dc=com',      // Bind DN for LDAP authentication (added in 2.1.7)
        'bind_password' => 'some_password',             // AVOID: quotes, backslashes, line breaks
        'user_attribute' => 'uid',                      // User attribute (uid for OpenLDAP, sAMAccountName for Active Directory) (added in 2.1.7)
        'protocol_version' => 3,                        // LDAP protocol version (added in 2.1.7)
        'search_filter' => '',                          // Additional search filter (added in 2.1.7)
        'session_cache_timeout' => 300,                 // Session cache timeout in seconds (5 minutes). Set to 0 to disable caching. (added in 4.1.0)
        // Examples:
        // '(memberOf=cn=powerdns,ou=groups,dc=poweradmin,dc=org)'
        // '(objectClass=account)'
        // '(objectClass=person)(memberOf=cn=admins,ou=groups,dc=poweradmin,dc=org)'
        // '(cn=*admin*)'
    ],

    /**
     * Miscellaneous Settings
     */
    'misc' => [
        'display_stats' => false,                      // Display memory usage and execution time
        'timezone' => 'UTC',                           // Default timezone
        'record_comments_sync' => false,               // Enable bidirectional comment sync between A and PTR records (added in 3.9.0)
        'edit_conflict_resolution' => 'last_writer_wins', // Options: 'last_writer_wins', 'only_latest_version', '3_way_merge'
        'display_errors' => false,                     // Display PHP errors (false for production) (added in 4.0.0)
        'show_generated_passwords' => true,            // Show generated passwords on user creation (added in 4.0.0)
        'email_previews_enabled' => false,             // Enable email template preview functionality (added in 4.0.0)
    ],

    /**
     * WHOIS Settings
     */
    'whois' => [
        'enabled' => false,                            // Enable WHOIS lookup functionality
        'default_server' => '',                        // Optional default WHOIS server (empty to use server from the WHOIS database)
        'socket_timeout' => 10,                        // Socket timeout in seconds for WHOIS queries
        'restrict_to_admin' => true,                   // Only allow administrators (user_is_ueberuser) to use WHOIS functionality
    ],

    /**
     * RDAP Settings
     */
    'rdap' => [
        'enabled' => false,                            // Enable RDAP lookup functionality
        'default_server' => '',                        // Optional default RDAP server URL (empty to use server from the RDAP database)
        'request_timeout' => 10,                       // HTTP request timeout in seconds for RDAP queries
        'restrict_to_admin' => true,                   // Only allow administrators (user_is_ueberuser) to use RDAP functionality
    ],

    /**
     * API Settings
     */
    'api' => [
        'enabled' => false,                            // Enable API functionality (including API keys)
        'basic_auth_enabled' => false,                 // Enable HTTP Basic Authentication for public API endpoints
        'basic_auth_realm' => 'Poweradmin API',        // Realm name for HTTP Basic Authentication
        'log_requests' => false,                       // Log all API requests
        'docs_enabled' => false,                       // Enable API documentation at /api/docs endpoint
        'max_keys_per_user' => 5,                      // Maximum number of API keys per user (admin users have no limit)
    ],

    /**
     * User Agreement Settings
     */
    'user_agreement' => [
        'enabled' => false,                            // Enable user agreement system
        'current_version' => '1.0',                    // Current agreement version
        'require_on_version_change' => true,           // Require re-acceptance when version changes
    ],

    /**
     * OIDC (OpenID Connect) Authentication Settings
     */
    'oidc' => [
        'enabled' => false,                   // Enable OIDC authentication
        'auto_provision' => true,             // Automatically create user accounts from OIDC
        'link_by_email' => true,              // Link OIDC accounts to existing users by email
        'sync_user_info' => true,             // Sync user information (name, email) from OIDC provider
        'default_permission_template' => '',  // Default permission template for new OIDC users

        // Permission template mapping for automatic role assignment
        // Maps OIDC groups to existing permission template names
        // Note: Users can only have one permission template assigned
        // Configure your actual group mappings in config/settings.php
        'permission_template_mapping' => [
            // Examples (configure your actual mappings in settings.php):
            // 'poweradmin-admins' => 'Administrator',    // Map this OIDC group to Administrator permission template
            // 'dns-operators' => 'DNS Operator',         // Example: DNS operations template (if exists)
            // 'dns-viewers' => 'Read Only',              // Example: Read-only template (if exists)
        ],

        // Provider configurations
        'providers' => [
            // Example Azure AD configuration
            /*
            'azure' => [
                'name' => 'Microsoft Azure AD',
                'display_name' => 'Sign in with Microsoft',
                'client_id' => '',                     // Application (client) ID from Azure
                'client_secret' => '',                 // Client secret from Azure
                'tenant' => 'common',                  // Tenant ID or 'common' for multi-tenant
                'auto_discovery' => true,
                'metadata_url' => 'https://login.microsoftonline.com/{tenant}/v2.0/.well-known/openid-configuration',
                'logout_url' => 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/logout',
                'scopes' => 'openid profile email',
                'user_mapping' => [
                    'username' => 'email',
                    'email' => 'email',
                    'first_name' => 'given_name',
                    'last_name' => 'family_name',
                    'display_name' => 'name',
                    'groups' => 'groups',
                    'subject' => 'sub',
                ],
            ],
            */

            // Example Google configuration
            /*
            'google' => [
                'name' => 'Google',
                'display_name' => 'Sign in with Google',
                'client_id' => '',                     // Client ID from Google Cloud Console
                'client_secret' => '',                 // Client secret from Google Cloud Console
                'auto_discovery' => true,
                'metadata_url' => 'https://accounts.google.com/.well-known/openid-configuration',
                'logout_url' => 'https://accounts.google.com/logout',
                'scopes' => 'openid profile email',
                'user_mapping' => [
                    'username' => 'email',
                    'email' => 'email',
                    'first_name' => 'given_name',
                    'last_name' => 'family_name',
                    'display_name' => 'name',
                    'groups' => 'groups',
                    'subject' => 'sub',
                ],
            ],
            */

            // Example Keycloak configuration
            /*
            'keycloak' => [
                'name' => 'Keycloak',
                'display_name' => 'Sign in with Keycloak',
                'client_id' => '',                     // Client ID from Keycloak
                'client_secret' => '',                 // Client secret from Keycloak
                'base_url' => 'https://keycloak.example.com',
                'realm' => 'master',                   // Keycloak realm name
                'auto_discovery' => true,
                'metadata_url' => '{base_url}/realms/{realm}/.well-known/openid-configuration',
                'logout_url' => '{base_url}/realms/{realm}/protocol/openid-connect/logout',
                'scopes' => 'openid profile email groups',
                'user_mapping' => [
                    'username' => 'preferred_username',
                    'email' => 'email',
                    'first_name' => 'given_name',
                    'last_name' => 'family_name',
                    'display_name' => 'name',
                    'groups' => 'groups',
                ],
            ],
            */

            // Example Okta configuration
            /*
            'okta' => [
                'name' => 'Okta',
                'display_name' => 'Sign in with Okta',
                'client_id' => '',                     // Client ID from Okta
                'client_secret' => '',                 // Client secret from Okta
                'domain' => 'your-org.okta.com',      // Your Okta domain
                'auto_discovery' => true,
                'metadata_url' => 'https://{domain}/.well-known/openid-configuration',
                'logout_url' => 'https://{domain}/oauth2/v1/logout',
                'scopes' => 'openid profile email groups',
                'user_mapping' => [
                    'username' => 'preferred_username',
                    'email' => 'email',
                    'first_name' => 'given_name',
                    'last_name' => 'family_name',
                    'display_name' => 'name',
                    'groups' => 'groups',
                    'subject' => 'sub',
                ],
            ],
            */

            // Example Authentik configuration
            /*
            'authentik' => [
                'name' => 'Authentik',
                'display_name' => 'Sign in with Authentik',
                'client_id' => '',                     // Client ID from Authentik
                'client_secret' => '',                 // Client secret from Authentik
                'base_url' => 'https://authentik.example.com',
                'application_slug' => 'poweradmin',    // Application slug in Authentik
                'auto_discovery' => true,
                'metadata_url' => '{base_url}/application/o/{application_slug}/.well-known/openid-configuration',
                'logout_url' => '{base_url}/application/o/{application_slug}/end-session/',
                'scopes' => 'openid profile email',
                'user_mapping' => [
                    'username' => 'preferred_username',
                    'email' => 'email',
                    'first_name' => 'given_name',
                    'last_name' => 'family_name',
                    'display_name' => 'name',
                    'groups' => 'groups',
                ],
            ],
            */

            // Example Auth0 configuration
            /*
            'auth0' => [
                'name' => 'Auth0',
                'display_name' => 'Sign in with Auth0',
                'client_id' => '',                     // Client ID from Auth0
                'client_secret' => '',                 // Client secret from Auth0
                'domain' => 'your-domain.auth0.com',  // Your Auth0 domain
                'auto_discovery' => true,
                'metadata_url' => 'https://{domain}/.well-known/openid-configuration',
                'logout_url' => 'https://{domain}/v2/logout',
                'scopes' => 'openid profile email',
                'user_mapping' => [
                    'username' => 'nickname',
                    'email' => 'email',
                    'first_name' => 'given_name',
                    'last_name' => 'family_name',
                    'display_name' => 'name',
                    'groups' => 'groups',
                    'subject' => 'sub',
                ],
            ],
            */

            // Generic OIDC provider configuration
            /*
            'generic' => [
                'name' => 'Generic OIDC',
                'display_name' => 'Sign in with OIDC',
                'client_id' => '',
                'client_secret' => '',
                'auto_discovery' => false,             // Set to false for manual endpoint configuration
                'authorize_url' => '',                 // Authorization endpoint URL
                'token_url' => '',                     // Token endpoint URL
                'userinfo_url' => '',                  // UserInfo endpoint URL
                'logout_url' => '',                    // Logout endpoint URL (optional)
                'scopes' => 'openid profile email',
                'user_mapping' => [
                    'username' => 'preferred_username',
                    'email' => 'email',
                    'first_name' => 'given_name',
                    'last_name' => 'family_name',
                    'display_name' => 'name',
                    'groups' => 'groups',
                ],
            ],
            */
        ],
    ],

    /**
     * SAML (Security Assertion Markup Language) Authentication Settings
     */
    'saml' => [
        'enabled' => false,                   // Enable SAML authentication
        'auto_provision' => true,             // Automatically create user accounts from SAML
        'link_by_email' => true,              // Link SAML accounts to existing users by email
        'sync_user_info' => true,             // Sync user information (name, email) from SAML provider
        'default_permission_template' => '',  // Default permission template for new SAML users

        // Permission template mapping for automatic role assignment
        // Maps SAML groups/roles to existing permission template names
        // Note: Users can only have one permission template assigned
        // Configure your actual group mappings in config/settings.php
        'permission_template_mapping' => [
            // Examples (configure your actual mappings in settings.php):
            // 'poweradmin-admins' => 'Administrator',    // Map this SAML group to Administrator permission template
            // 'dns-operators' => 'DNS Operator',         // Example: DNS operations template (if exists)
            // 'dns-viewers' => 'Read Only',              // Example: Read-only template (if exists)
        ],

        // Service Provider (SP) Settings - Your PowerAdmin instance
        'sp' => [
            'entity_id' => '',                // SP Entity ID (usually your PowerAdmin URL)
            'assertion_consumer_service_url' => '', // ACS URL (leave empty for auto-generation)
            'single_logout_service_url' => '',      // SLO URL (leave empty for auto-generation)
            'name_id_format' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress',
            'x509cert' => '',                // SP X.509 Certificate (optional, for signing requests)
            'private_key' => '',             // SP Private Key (optional, for signing requests)
        ],

        // Provider configurations
        'providers' => [
            // Example Azure AD SAML configuration
            /*
            'azure' => [
                'name' => 'Microsoft Azure AD',
                'display_name' => 'Sign in with Microsoft (SAML)',
                'entity_id' => 'https://sts.windows.net/{tenant-id}/',
                'sso_url' => 'https://login.microsoftonline.com/{tenant-id}/saml2',
                'slo_url' => 'https://login.microsoftonline.com/{tenant-id}/saml2',
                'x509cert' => '',             // IdP X.509 Certificate (required)
                'x509cert_multi' => [         // Multiple certificates for rollover (optional)
                    'signing' => [],
                    'encryption' => []
                ],
                'user_mapping' => [
                    'username' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/name',
                    'email' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress',
                    'first_name' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/givenname',
                    'last_name' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/surname',
                    'display_name' => 'http://schemas.microsoft.com/identity/claims/displayname',
                    'groups' => 'http://schemas.microsoft.com/ws/2008/06/identity/claims/groups',
                ],
            ],
            */

            // Example Okta SAML configuration
            /*
            'okta' => [
                'name' => 'Okta',
                'display_name' => 'Sign in with Okta (SAML)',
                'entity_id' => 'http://www.okta.com/{app-id}',
                'sso_url' => 'https://{domain}.okta.com/app/{app-name}/{app-id}/sso/saml',
                'slo_url' => 'https://{domain}.okta.com/app/{app-name}/{app-id}/slo/saml',
                'x509cert' => '',             // Okta X.509 Certificate
                'user_mapping' => [
                    'username' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/name',
                    'email' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress',
                    'first_name' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/givenname',
                    'last_name' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/surname',
                    'display_name' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/name',
                    'groups' => 'http://schemas.xmlsoap.org/claims/Group',
                ],
            ],
            */

            // Example Auth0 SAML configuration
            /*
            'auth0' => [
                'name' => 'Auth0',
                'display_name' => 'Sign in with Auth0 (SAML)',
                'entity_id' => 'urn:auth0:{tenant}:{connection}',
                'sso_url' => 'https://{tenant}.auth0.com/samlp/{client-id}',
                'slo_url' => 'https://{tenant}.auth0.com/samlp/{client-id}/logout',
                'x509cert' => '',             // Auth0 X.509 Certificate
                'user_mapping' => [
                    'username' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/nameidentifier',
                    'email' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress',
                    'first_name' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/givenname',
                    'last_name' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/surname',
                    'display_name' => 'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/name',
                    'groups' => 'http://schemas.auth0.com/roles',
                ],
            ],
            */

            // Example Keycloak SAML configuration
            /*
            'keycloak' => [
                'name' => 'Keycloak',
                'display_name' => 'Sign in with Keycloak (SAML)',
                'entity_id' => 'https://keycloak.example.com/realms/{realm}',
                'sso_url' => 'https://keycloak.example.com/realms/{realm}/protocol/saml',
                'slo_url' => 'https://keycloak.example.com/realms/{realm}/protocol/saml',
                'x509cert' => '',             // Keycloak X.509 Certificate
                'user_mapping' => [
                    'username' => 'username',
                    'email' => 'email',
                    'first_name' => 'firstName',
                    'last_name' => 'lastName',
                    'display_name' => 'name',
                    'groups' => 'groups',
                ],
            ],
            */

            // Generic SAML provider configuration template
            // Copy and modify this template for your SAML Identity Provider
            /*
            'generic_saml' => [
                'enabled' => false,                // Set to true to enable this provider
                'name' => 'Generic SAML IdP',      // Internal provider name
                'display_name' => 'Sign in with SAML', // Text shown on login button

                // Identity Provider settings (get these from your IdP metadata)
                'entity_id' => 'https://your-idp.example.com/metadata',
                'sso_url' => 'https://your-idp.example.com/sso',
                'slo_url' => 'https://your-idp.example.com/slo',
                'x509cert' => '',              // Required: IdP X.509 Certificate (without headers/footers)

                // Attribute mapping - map SAML attributes to Poweradmin user fields
                'user_mapping' => [
                    'username' => 'uid',               // SAML attribute for username
                    'email' => 'email',                // SAML attribute for email
                    'first_name' => 'firstName',       // SAML attribute for first name
                    'last_name' => 'lastName',         // SAML attribute for last name
                    'display_name' => 'displayName',   // SAML attribute for display name
                    'groups' => 'groups',              // SAML attribute for groups/roles
                ],

                // Security settings (recommended for production)
                'security' => [
                    'nameIdEncrypted' => false,        // Whether NameID should be encrypted
                    'authnRequestsSigned' => false,    // Whether to sign authentication requests
                    'logoutRequestSigned' => false,    // Whether to sign logout requests
                    'logoutResponseSigned' => false,   // Whether to sign logout responses
                    'signMetadata' => false,           // Whether to sign SP metadata
                    'wantAssertionsSigned' => true,    // Require signed assertions from IdP
                    'wantNameId' => true,              // Require NameID in assertions
                    'wantAssertionsEncrypted' => false, // Require encrypted assertions
                    'wantNameIdEncrypted' => false,    // Require encrypted NameID
                    'requestedAuthnContext' => true,   // Request specific authentication context
                    'signatureAlgorithm' => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
                    'digestAlgorithm' => 'http://www.w3.org/2001/04/xmlenc#sha256',
                ],

                // Advanced settings (optional)
                'settings' => [
                    'compress_requests' => true,       // Compress SAML requests
                    'compress_responses' => true,      // Compress SAML responses
                    'allow_single_label_domains' => false, // Allow single-label domains
                ],
            ],
            */
        ],
    ],
];
