<?php

/**
 * SQLite family: one file holds both schemas. A short language list keeps the
 * language-selector tests deterministic on this instance.
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
