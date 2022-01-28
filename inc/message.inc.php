<?php

/** Print success message (toolkit.inc)
 *
 * @param string $msg Success message
 *
 * @return null
 */
function success($msg) {
    if ($msg) {
        echo "     <div class=\"success\">" . $msg . "</div>\n";
    } else {
        echo "     <div class=\"success\">" . _('Something has been successfully performed. What exactly, however, will remain a mystery.') . "</div>\n";
    }
}
