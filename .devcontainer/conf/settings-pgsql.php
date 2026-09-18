<?php

/**
 * PostgreSQL family: Poweradmin and PowerDNS share the pdns database. The LDAP block stays
 * off here; the ldap container fixtures only match the MySQL user rows.
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
    'ldap' => ['enabled' => false],
];
