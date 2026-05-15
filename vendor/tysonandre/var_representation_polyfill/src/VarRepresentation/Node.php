<?php

declare(strict_types=1);

namespace VarRepresentation;

/**
 * Represents an expression
 */
abstract class Node
{
    /** Convert this to a single line string */
    abstract public function __toString(): string;
    /**
     * Convert this to an indented string
     */
    abstract public function toIndentedString(int $depth): string;
}
