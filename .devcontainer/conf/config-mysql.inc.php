<?php

// Database settings (MySQL/MariaDB)
$db_host = 'mysql';
$db_port = '3306';
$db_user = 'pdns';
$db_pass = 'poweradmin';
$db_name = 'poweradmin';
$db_type = 'mysql';
$db_charset = 'utf8';
$db_debug = true;

// Separate PowerDNS database
$pdns_db_name = 'pdns';

// Security settings
$session_key = 'mysql-session-key-poweradmin-dev-2024';
$password_encryption = 'bcrypt';
$password_encryption_cost = 10;

// Interface settings - all features enabled
$iface_lang = 'en_EN';
$iface_enabled_languages = 'cs_CZ,de_DE,en_EN,fr_FR,it_IT,ja_JP,lt_LT,nb_NO,nl_NL,pl_PL,ru_RU,tr_TR,zh_CN';
$iface_style = 'ignite';
$iface_templates = 'templates';
$iface_rowamount = 50;
$iface_expire = 1800;
$iface_zonelist_serial = true;
$iface_zonelist_template = true;
$iface_title = 'Poweradmin (MySQL)';
$iface_add_reverse_record = true;
$iface_add_domain_record = true;
$iface_zone_type_default = 'MASTER';
$iface_zone_comments = true;
$iface_record_comments = true;
$iface_index = 'cards';
$iface_search_group_records = true;
$iface_edit_show_id = true;
$iface_edit_add_record_top = true;
$iface_edit_save_changes_top = true;
$iface_migrations_show = true;

// Predefined DNS settings
$dns_hostmaster = 'hostmaster.example.com';
$dns_ns1 = 'ns1.example.com';
$dns_ns2 = 'ns2.example.com';
$dns_ns3 = 'ns3.example.com';
$dns_ns4 = 'ns4.example.com';
$dns_ttl = 86400;
$dns_soa = '28800 7200 604800 86400';
$dns_strict_tld_check = true;
$dns_top_level_tld_check = true;
$dns_third_level_check = true;
$dns_txt_auto_quote = true;

// Timezone settings
$timezone = 'UTC';

// Logging settings - all enabled
$logger_type = 'native';
$logger_level = 'debug';
$syslog_use = true;
$syslog_ident = 'poweradmin-mysql';
$syslog_facility = LOG_USER;
$dblog_use = true;

// DNSSEC settings - enabled
$pdnssec_use = true;
$pdnssec_debug = true;
$pdnssec_command = '/usr/bin/pdnsutil';

// PowerDNS API settings (MySQL instance)
$pdns_api_url = 'http://pdns-mysql:8081';
$pdns_api_key = 'fxiBmBFx7MITw5ECRMOr10ghlxGMvWZA';

// LDAP settings - disabled
$ldap_use = false;
$ldap_debug = false;

// Display stats
$display_stats = false;

// Experimental edit conflict resolution
$experimental_edit_conflict_resolution = 'only_latest_version';

// CSRF token validation
$login_token_validation = true;
$global_token_validation = true;

// Record comments sync
$record_comments_sync = true;
