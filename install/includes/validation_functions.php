<?php

/**
 * Custom validation-rule for checking if value is a valid file
 */
Valitron\Validator::addRule('isFile', function($field, $value) {
    // Checks if value is file
    if (is_file($value)) {
        return true;
    }

    return false;
}, _('is not a file!'));
