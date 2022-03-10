<?php

namespace Poweradmin;

class DependencyCheck
{
    const DEPENDENCIES = array(
        'intl' => 'idn_to_utf8',
        'gettext' => 'gettext',
        'openssl' => 'openssl_encrypt',
        'session' => 'session_start'
    );

    public static function verifyExtensions()
    {
        foreach (array_keys(self::DEPENDENCIES) as $extension) {
            if (!function_exists(self::DEPENDENCIES[$extension])) {
                die("You have to install PHP {$extension} extension!");
            }
        }
    }
}