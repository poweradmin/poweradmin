<?php
/*---------------------------------------------------------------------------------------------
 * Copyright (c) Microsoft Corporation. All rights reserved.
 *  Licensed under the MIT License. See License.txt in the project root for license information.
 *--------------------------------------------------------------------------------------------*/

namespace Microsoft\PhpParser\Node\Expression;

use Microsoft\PhpParser\Node\Expression;
use Microsoft\PhpParser\Token;

class CloneExpression extends Expression {

    /** @var Token */
    public $cloneKeyword;

    /** @var Token|null OpenParenToken for clone($obj, ...) syntax (PHP 8.5+) */
    public $openParen;

    /** @var Expression */
    public $expression;

    /** @var Token|null CommaToken between object and modifications (PHP 8.5+) */
    public $commaToken;

    /** @var Expression|null Array expression with property modifications (PHP 8.5+) */
    public $modifications;

    /** @var Token|null CloseParenToken for clone($obj, ...) syntax (PHP 8.5+) */
    public $closeParen;

    const CHILD_NAMES = [
        'cloneKeyword',
        'openParen',
        'expression',
        'commaToken',
        'modifications',
        'closeParen'
    ];
}
