<?php

declare(strict_types=1);

/**
 * The MIT License (MIT)
 *
 * Copyright (c) 2021 Tyson Andre
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

use VarRepresentation\Encoder;

if (!function_exists('var_representation')) {
    /**
     * Convert a variable to a string in a way that fixes the shortcomings of `var_export()`.
     *
     * @param mixed $value
     * @param int $flags bitmask of flags (VAR_REPRESENTATION_SINGLE_LINE, VAR_REPRESENTATION_UNESCAPED)
     * @suppress PhanRedefineFunctionInternal this is a polyfill
     */
    function var_representation($value, int $flags = 0): string
    {
        return Encoder::toVarRepresentation($value, $flags);
    }
}
if (!defined('VAR_REPRESENTATION_SINGLE_LINE')) {
    define('VAR_REPRESENTATION_SINGLE_LINE', 1);
}
if (!defined('VAR_REPRESENTATION_UNESCAPED')) {
    define('VAR_REPRESENTATION_UNESCAPED', 2);
}
