<?php

declare(strict_types=1);

namespace VarRepresentation\Node;

use VarRepresentation\Node;

/**
 * Represents a 'key => value' entry
 */
class ArrayEntry extends Node
{
    /** @var Node the key  */
    public $key;
    /** @var Node the value  */
    public $value;

    public function __construct(Node $key, Node $value)
    {
        $this->key = $key;
        $this->value = $value;
    }

    public function __toString(): string
    {
        return $this->key->__toString() . ' => ' . $this->value->__toString();
    }

    public function toIndentedString(int $depth): string
    {
        return $this->key->__toString() . ' => ' . $this->value->toIndentedString($depth);
    }
}
