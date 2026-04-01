<?php

declare(strict_types=1);

namespace VarRepresentation\Node;

use VarRepresentation\Node;

/**
 * Represents an array literal
 */
class Array_ extends Node
{
    /** @var list<ArrayEntry> the list of nodes (keys and optional values) in the array */
    public $entries;

    /** @param list<ArrayEntry> $entries the list of nodes (keys and optional values) in the array */
    public function __construct(array $entries)
    {
        $this->entries = $entries;
    }

    /**
     * If this is a list, returns only the nodes for values.
     * If this is not a list, returns the entries with keys and values.
     *
     * @return list<ArrayEntry>|list<Node>
     */
    public function getValuesOrEntries(): array
    {
        $values = [];
        foreach ($this->entries as $i => $entry) {
            if ($entry->key->__toString() !== (string)$i) {
                // not a list
                return $this->entries;
            }
            $values[] = $entry->value;
        }
        return $values;
    }

    public function __toString(): string
    {
        // TODO check if list
        $inner = \implode(', ', $this->getValuesOrEntries());
        return '[' . $inner . ']';
    }

    public function toIndentedString(int $depth): string
    {
        $parts = $this->getValuesOrEntries();
        if (\count($parts) === 0) {
            return '[]';
        }
        $representation = "[\n";
        foreach ($parts as $part) {
            $representation .= \str_repeat('  ', $depth + 1) . $part->toIndentedString($depth + 1) . ",\n";
        }
        return $representation . \str_repeat('  ', $depth) . "]";
    }
}
