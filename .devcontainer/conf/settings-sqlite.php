<?php

/**
 * SQLite family: one file holds both schemas, and each instance gets its own file
 * so they can run side by side (SQLite allows one writer per file). A short
 * language list keeps the language-selector tests deterministic on this instance.
 */

return [
    'database' => [
        'type' => 'sqlite',
        'file' => '/data/pdns.db',
    ],
    'interface' => ['enabled_languages' => 'en_EN,de_DE,fr_FR,ja_JP,pl_PL'],
    'pdns_api' => ['url' => 'http://pdns-sqlite:8081'],
    'ldap' => ['enabled' => false],
];
