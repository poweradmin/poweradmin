<?php

/**
 * Poweradmin devcontainer settings: MySQL + SQL backend with OIDC and SAML sign-in
 * against the mock-oidc and mock-saml containers (port 8089)
 *
 * Shared values come from settings-base.php and settings-mysql.php.
 */

require_once is_file(__DIR__ . '/settings-base.php') ? __DIR__ . '/settings-base.php' : '/app/.devcontainer/conf/settings-base.php';

$settings = devcontainer_settings('mysql');

$settings['interface']['title'] = 'Poweradmin (MySQL + SQL, OIDC and SAML)';
// The OAuth redirect_uri and the SAML ACS are built from it
$settings['interface']['application_url'] = 'http://localhost:8089';

// Lockout runs only here: every devcontainer client reaches PHP from the docker gateway
// address, so on the shared instances a few wrong admin passwords would lock out every run
$settings['security']['account_lockout'] = [
    'enable_lockout' => true,
    'lockout_duration' => 1,
];

// The devcontainer database has no 'Guest' template; groups map onto the seeded ones
$sso = [
    'enabled' => true,
    'default_permission_template' => 'No Access',
    'permission_template_mapping' => ['pa-managers' => 'Zone Manager'],
    'group_mapping' => ['pa-viewers' => 'Viewers'],
];

$settings['oidc'] = $sso + [
    'providers' => [
        'mock' => [
            'name' => 'Mock OIDC',
            'display_name' => 'Sign in with Mock OIDC',
            'client_id' => 'poweradmin',
            'client_secret' => 'poweradmin-secret',
            // Manual endpoints: the browser reaches the IdP on localhost, the app container by
            // service name, and the mock never closes the keep-alive that discovery's stream waits on
            'auto_discovery' => false,
            'authorize_url' => 'http://localhost:9080/default/authorize',
            'token_url' => 'http://mock-oidc:9080/default/token',
            'userinfo_url' => 'http://mock-oidc:9080/default/userinfo',
            'logout_url' => 'http://localhost:9080/default/endsession',
            'scopes' => 'openid profile email',
            'user_mapping' => [
                'username' => 'preferred_username',
                'email' => 'email',
                'display_name' => 'name',
                'groups' => 'groups',
                'subject' => 'sub',
            ],
        ],
    ],
];

$settings['saml'] = $sso + [
    'sp' => [
        'entity_id' => 'http://localhost:8089/saml/metadata',
    ],
    'providers' => [
        'mock' => [
            'name' => 'Mock SAML',
            'display_name' => 'Sign in with Mock SAML',
            'entity_id' => 'http://localhost:9081/simplesaml/saml2/idp/metadata.php',
            'sso_url' => 'http://localhost:9081/simplesaml/saml2/idp/SSOService.php',
            'slo_url' => 'http://localhost:9081/simplesaml/saml2/idp/SingleLogoutService.php',
            'x509cert' => 'MIIDXTCCAkWgAwIBAgIJALmVVuDWu4NYMA0GCSqGSIb3DQEBCwUAMEUxCzAJBgNVBAYTAkFVMRMwEQYDVQQIDApTb21lLVN0YXRlMSEwHwYDVQQKDBhJbnRlcm5ldCBXaWRnaXRzIFB0eSBMdGQwHhcNMTYxMjMxMTQzNDQ3WhcNNDgwNjI1MTQzNDQ3WjBFMQswCQYDVQQGEwJBVTETMBEGA1UECAwKU29tZS1TdGF0ZTEhMB8GA1UECgwYSW50ZXJuZXQgV2lkZ2l0cyBQdHkgTHRkMIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAzUCFozgNb1h1M0jzNRSCjhOBnR+uVbVpaWfXYIR+AhWDdEe5ryY+CgavOg8bfLybyzFdehlYdDRgkedEB/GjG8aJw06l0qF4jDOAw0kEygWCu2mcH7XOxRt+YAH3TVHa/Hu1W3WjzkobqqqLQ8gkKWWM27fOgAZ6GieaJBN6VBSMMcPey3HWLBmc+TYJmv1dbaO2jHhKh8pfKw0W12VM8P1PIO8gv4Phu/uuJYieBWKixBEyy0lHjyixYFCR12xdh4CA47q958ZRGnnDUGFVE1QhgRacJCOZ9bd5t9mr8KLaVBYTCJo5ERE8jymab5dPqe5qKfJsCZiqWglbjUo9twIDAQABo1AwTjAdBgNVHQ4EFgQUxpuwcs/CYQOyui+r1G+3KxBNhxkwHwYDVR0jBBgwFoAUxpuwcs/CYQOyui+r1G+3KxBNhxkwDAYDVR0TBAUwAwEB/zANBgkqhkiG9w0BAQsFAAOCAQEAAiWUKs/2x/viNCKi3Y6blEuCtAGhzOOZ9EjrvJ8+COH3Rag3tVBWrcBZ3/uhhPq5gy9lqw4OkvEws99/5jFsX1FJ6MKBgqfuy7yh5s1YfM0ANHYczMmYpZeAcQf2CGAaVfwTTfSlzNLsF2lW/ly7yapFzlYSJLGoVE+OHEu8g5SlNACUEfkXw+5Eghh+KzlIN7R6Q7r2ixWNFBC/jWf7NKUfJyX8qIG5md1YUeT6GBW9Bm2/1/RiO24JTaYlfLdKK9TYb8sG5B+OLab2DImG99CJ25RkAcSobWNF5zD0O6lgOo3cEdB/ksCq3hmtlC/DlLZ/D8CJ+7VuZnS1rR2naQ==',
            'user_mapping' => [
                'username' => 'uid',
                'email' => 'mail',
                'display_name' => 'displayName',
                'groups' => 'groups',
            ],
        ],
    ],
];

return $settings;
