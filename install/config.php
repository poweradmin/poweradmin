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
        ],
        // X-Forwarded-For is honored ONLY when REMOTE_ADDR appears in this list.
        // Leave empty (default) to ignore X-Forwarded-For entirely and gate access
        // strictly on REMOTE_ADDR. Add the IPs of your reverse proxy chain (the
        // local nginx, any internal load balancer, the public CDN/WAF) if you want
        // allowlist decisions to use the real client behind those proxies.
        'trusted_proxies' => [
            //'127.0.0.1',
            //'203.0.113.10',
            //'198.51.100.0/24',
        ],
    ],

    // Admin user password requirements
    'password_policy' => [
        'min_length' => 6,
        'require_uppercase' => true,
        'require_lowercase' => true,
        'require_numbers' => true,
        'require_special' => false,
        'special_characters' => '!@#$%^&*()+-=[]{}|;:,.<>?',
    ],
];
