<?php

// Test users of the mock-saml IdP (kristophjunge/test-saml-idp); password = username + "pass"
$config = [
    'admin' => [
        'core:AdminPassword',
    ],

    'example-userpass' => [
        'exampleauth:UserPass',
        'samlmanager:samlmanagerpass' => [
            'uid' => ['samlmanager'],
            'mail' => ['samlmanager@example.net'],
            'displayName' => ['SAML Manager'],
            'groups' => ['pa-managers', 'pa-viewers'],
        ],
        'samlnobody:samlnobodypass' => [
            'uid' => ['samlnobody'],
            'mail' => ['samlnobody@example.net'],
            'displayName' => ['SAML Nobody'],
            'groups' => [],
        ],
    ],
];
