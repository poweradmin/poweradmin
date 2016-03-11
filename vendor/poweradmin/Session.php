<?php

namespace Poweradmin;

class Session
{
    public static function getRandomKey() {
        $key = '';

        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789~!@#$%^&*()_+=-][{}';
        $length = 46;

        $size = strlen($chars);
        for ($i = 0; $i < $length; $i++) {
            $key .= $chars[mt_rand(0, $size - 1)];
        }

        return $key;
    }
}