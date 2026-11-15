<?php

namespace RobRichards\XMLSecLibs\Utils;

/**
 * xmlseclibs.php
 *
 * Copyright (c) 2007-2026, Robert Richards <rrichards@cdatazone.org>.
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions
 * are met:
 *
 *   * Redistributions of source code must retain the above copyright
 *     notice, this list of conditions and the following disclaimer.
 *
 *   * Redistributions in binary form must reproduce the above copyright
 *     notice, this list of conditions and the following disclaimer in
 *     the documentation and/or other materials provided with the
 *     distribution.
 *
 *   * Neither the name of Robert Richards nor the names of his
 *     contributors may be used to endorse or promote products derived
 *     from this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS
 * FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT,
 * INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
 * BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT
 * LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN
 * ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 *
 * @author    Robert Richards <rrichards@cdatazone.org>
 * @copyright 2007-2026 Robert Richards <rrichards@cdatazone.org>
 * @license   http://www.opensource.org/licenses/bsd-license.php  BSD License
 */

class XPath
{
    const ALPHANUMERIC = '\w\d';
    const NUMERIC = '\d';
    const LETTERS = '\w';
    const EXTENDED_ALPHANUMERIC = '-\w\d\s_:\.';

    /* Attribute names only — no whitespace or XPath operators. */
    const EXTENDED_ALPHANUMERIC_STRICT = '-\w\d_:\.';

    const SINGLE_QUOTE = '\'';
    const DOUBLE_QUOTE = '"';
    const ALL_QUOTES = '[\'"]';


    /**
     * Filter an attribute value for save inclusion in an XPath query.
     *
     * @param string $value The value to filter.
     * @param string $quotes The quotes used to delimit the value in the XPath query.
     *
     * @return string The filtered attribute value.
     */
    public static function filterAttrValue($value, $quotes = self::ALL_QUOTES)
    {
        return preg_replace('#'.$quotes.'#', '', $value);
    }


    /**
     * Filter an attribute name for save inclusion in an XPath query.
     *
     * @param string $name The attribute name to filter.
     * @param mixed $allow The set of characters to allow. Can be one of the constants provided by this class, or a
     * custom regex excluding the '#' character (used as delimiter).
     *
     * @return string The filtered attribute name.
     */
    public static function filterAttrName($name, $allow = self::EXTENDED_ALPHANUMERIC_STRICT)
    {
        return preg_replace('#[^'.$allow.']#', '', $name);
    }
}
