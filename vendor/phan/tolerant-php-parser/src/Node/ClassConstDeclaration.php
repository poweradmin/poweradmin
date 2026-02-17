<?php
/*---------------------------------------------------------------------------------------------
 * Copyright (c) Microsoft Corporation. All rights reserved.
 *  Licensed under the MIT License. See License.txt in the project root for license information.
 *--------------------------------------------------------------------------------------------*/

namespace Microsoft\PhpParser\Node;

use Microsoft\PhpParser\MissingToken;
use Microsoft\PhpParser\ModifiedTypeInterface;
use Microsoft\PhpParser\ModifiedTypeTrait;
use Microsoft\PhpParser\Node;
use Microsoft\PhpParser\Node\DelimitedList\QualifiedNameList;
use Microsoft\PhpParser\Token;

class ClassConstDeclaration extends Node implements ModifiedTypeInterface {
    use ModifiedTypeTrait;

    /** @var AttributeGroup[]|null */
    public $attributes;

    /** @var Token[] */
    public $modifiers;

    /** @var Token */
    public $constKeyword;

    /** @var Token|null question token when a nullable type is declared */
    public $questionToken;

    /** @var QualifiedNameList|MissingToken|null */
    public $typeDeclarationList;

    /** @var DelimitedList\ConstElementList */
    public $constElements;

    /** @var Token */
    public $semicolon;

    const CHILD_NAMES = [
        'attributes',
        'modifiers',
        'constKeyword',
        'questionToken',
        'typeDeclarationList',
        'constElements',
        'semicolon'
    ];
}
