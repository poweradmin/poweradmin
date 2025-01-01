<?php

return [
    // CSRF Protection
    'csrf' => [
        'enabled' => true,
    ],

    // IP Access Control
    'ip_access' => [
        'enabled' => false,
        'allowed_ips' => [
            '127.0.0.1',
            '::1',
        ],
        'allowed_ranges' => [
            //'192.168.0.0/16',
            //'172.16.0.0/12',
            //'10.0.0.0/8'
        ]
    ],

    // Password Requirements
    'password_policy' => [
        'min_length' => 6,
        'require_uppercase' => true,
        'require_lowercase' => true,
        'require_numbers' => true,
        'require_special' => false,
        'special_characters' => '!@#$%^&*()+-=[]{}|;:,.<>?',
    ],
];
