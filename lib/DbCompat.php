<?php

namespace Poweradmin;

class DbCompat
{
    public static function substr(string $db_type): string
    {
        if ($db_type == "sqlite") {
            return "SUBSTR";
        } else {
            return "SUBSTRING";
        }
    }
}
