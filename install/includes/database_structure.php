<?php

return [
    'perm_items' => [
        'columns' => [
            'id' => ['type' => 'integer', 'options' => ['autoincrement' => true ]],
            'name' => ['type' => 'string'],
            'descr' => ['type' => 'text']
        ],
        'primaryKey' => ['id'],
        'unique' =>  ['name']
    ],
    'perm_templ' => [
        'columns' => [
            'id' => ['type' => 'integer', 'options' => ['autoincrement' => true ]],
            'name' => ['type' => 'string'],
            'descr' => ['type' => 'text']
        ],
        'primaryKey' => ['id'],
        'unique' =>  ['name']
    ],
    'perm_templ_items' => [
        'columns' => [
            'id' => ['type' => 'integer', 'options' => ['autoincrement' => true ]],
            'templ_id' => ['type' => 'integer'],
            'perm_id' => ['type' => 'integer']
        ],
        'primaryKey' => ['id'],
        'unique' =>  ['templ_id', 'perm_id']
    ],
    'users' => [
        'columns' => [
            'id' => ['type' => 'integer', 'options' => ['autoincrement' => true ]],
            'username' => ['type' => 'string'],
            'password' => ['type' => 'string'],
            'fullname' => ['type' => 'string'],
            'email' => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'perm_templ' => ['type' => 'integer'],
            'use_ldap' => ['type' => 'boolean'],
            'active' => ['type' => 'boolean']
        ],
        'primaryKey' => ['id'],
        'unique' => ['username', 'email']
    ],
    'zones' => [
        'columns' => [
            'id' => ['type' => 'integer', 'options' => ['autoincrement' => true ]],
            'domain_id' => ['type' => 'integer'],
            'owner' => ['type' => 'integer'],
            'comment' => ['type' => 'text'],
            'zone_templ_id' => ['type' => 'integer']
        ],
        'primaryKey' => ['id']
    ],
    'zone_templ' => [
        'columns' => [
            'id' => ['type' => 'integer', 'options' => ['autoincrement' => true ]],
            'name' => ['type' => 'string'],
            'descr' => ['type' => 'text'],
            'owner' => ['type' => 'integer']
        ],
        'primaryKey' => ['id'],
        'unique' => ['name']
    ],
    'zone_templ_records' => [
        'columns' => [
            'id' => ['type' => 'integer', 'options' => ['autoincrement' => true ]],
            'zone_templ_id' => ['type' => 'integer'],
            'name' => ['type' => 'string'],
            'type' => ['type' => 'string', 'length' => 6],
            'content' => ['type' => 'string'],
            'ttl' => ['type' => 'integer'],
            'prio' => ['type' => 'integer']
        ],
        'primaryKey' => ['id'],
        'unique' => ['zone_templ_id', 'name', 'type']
    ],
    'records_zone_templ' => [
        'columns' => [
            'domain_id' => ['type' => 'integer'],
            'record_id' => ['type' => 'integer'],
            'zone_templ_id' => ['type' => 'integer']
        ],
        'unique' => ['domain_id', 'record_id', 'zone_templ_id']
    ],
    'migrations' => [
        'columns' => [
            'domain_id' => ['type' => 'integer'],
            'record_id' => ['type' => 'integer']
        ],
        'unique' => ['domain_id', 'record_id']
    ]
];
