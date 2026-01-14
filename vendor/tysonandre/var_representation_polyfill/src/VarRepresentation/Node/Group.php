<?php

declare(strict_types=1);

namespace VarRepresentation\Node;

use RuntimeException;
use VarRepresentation\Node;

/**
 * A group of 1 or more strings/nodes
 */
class Group extends Node
{
    /** @var list<string|Node> the parts, e.g. '-' '1' */
    protected $parts;

    /**
     * @param list<string|node> $parts
     */
    public function __construct(array $parts)
    {
        if (\count($parts) === 0) {
            throw new RuntimeException(__METHOD__ . ' passed no parts');
        }
        $this->parts = $parts;
    }

    /**
     * Create a node or a group from a list of Node|string parts
     * @param list<string|Node> $parts
     */
    public static function fromParts(array $parts): Node
    {
        if (\count($parts) === 1 && $parts[0] instanceof Node) {
            return $parts[0];
        }
        return new self($parts);
    }

    public function toIndentedString(int $depth): string
    {
        $result = '';
        foreach ($this->parts as $part) {
            $result .= \is_string($part) ? $part : $part->toIndentedString($depth);
        }
        return $result;
    }
    public function __toString(): string
    {
        return \implode('', $this->parts);
    }
}
