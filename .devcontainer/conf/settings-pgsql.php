<?php

/**
 * PostgreSQL family: Poweradmin and PowerDNS share the pdns database. LDAP comes from the
 * base settings; import-test-data.sh adds the matching LDAP users here too.
 */

return [
    'database' => [
        'host' => 'postgres',
        'port' => '5432',
        'name' => 'pdns',
        'user' => 'pdns',
        'password' => 'poweradmin',
        'type' => 'pgsql',
        'charset' => 'utf8',
    ],
    'pdns_api' => ['url' => 'http://pdns-pgsql:8081'],
];
