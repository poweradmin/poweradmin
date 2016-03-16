<?php

/**
 * Custom validation-rule for checking if value is a valid file
 */
Valitron\Validator::addRule('isFile', function($field, $value) { return is_file($value); }, _('is not a file!'));
