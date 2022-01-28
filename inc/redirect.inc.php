<?php

/** Send 302 Redirect with optional argument
 *
 * Reroute a user to a cleanpage of (if passed) arg
 *
 * @param string $arg argument string to add to url
 *
 * @return null
 */
function clean_page($arg = '') {
    if (!$arg) {
        header("Location: " . htmlentities($_SERVER['SCRIPT_NAME'], ENT_QUOTES) . "?time=" . time());
        exit;
    } else {
        if (preg_match('!\?!si', $arg)) {
            $add = "&time=";
        } else {
            $add = "?time=";
        }
        header("Location: $arg$add" . time());
        exit;
    }
}