<?php

/** Logout the user
 *
 * Logout the user and kickback to login form
 *
 * @param string $msg Error Message
 * @param string $type Message type [default='']
 *
 * @return null
 */
function logout($msg = "", $type = "") {
    session_unset();
    session_destroy();
    session_write_close();
    auth($msg, $type);
    exit;
}
