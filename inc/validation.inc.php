<?php

/** Validate email address string
 *
 * @param string $address email address string
 *
 * @return boolean true if valid, false otherwise
 */
function is_valid_email($address) {
    $fields = preg_split("/@/", $address, 2);
    if ((!preg_match("/^[0-9a-z]([-_.]?[0-9a-z])*$/i", $fields[0])) || (!isset($fields[1]) || $fields[1] == '' || !is_valid_hostname_fqdn($fields[1], 0))) {
        return false;
    }
    return true;
}

/** Validate numeric string
 *
 * @param string $string number
 *
 * @return boolean true if number, false otherwise
 */
function v_num($string) {
    if (!preg_match("/^[0-9]+$/i", $string)) {
        return false;
    } else {
        return true;
    }
}
