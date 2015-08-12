<?php

class PoweradminUtil
{
    public static function get_username()
    {
        return $_SESSION['userlogin'];
    }

    /**
     * Creates an array out of $val. If val is an array, it will be returned straight away.
     * If however, $val is a value, an array with a single entry ($val) will be returned.
     *
     * @param (mixed|mixed[]) $val
     * @return array $val, if it is an array. [$val] otherwise.
     */
    public static function make_array($val)
    {
        $arr= array();
        if(is_array($val)){
            $arr = $val;
        } else {
            $arr[] = $val;
        }
        return $arr;
    }
}
