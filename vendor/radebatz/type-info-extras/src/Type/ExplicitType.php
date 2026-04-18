<?php declare(strict_types=1);

namespace Radebatz\TypeInfoExtras\Type;

use Symfony\Component\TypeInfo\Type;
use Symfony\Component\TypeInfo\Type\BuiltinType;
use Symfony\Component\TypeInfo\TypeIdentifier;

/**
 * A special type of `BuiltinType` for when a more specific type exits.
 *
 * When using this library, either code would have to check for `BuiltinType|ExplicitType` or just rely on `Type::getTypeIdentifier()`.
 *
 * @template T of TypeIdentifier
 */
class ExplicitType extends Type
{
    /**
     * @param T $typeIdentifier
     */
    public function __construct(
        private readonly TypeIdentifier $typeIdentifier,
        private readonly string $explicitType,
    ) {
    }

    /**
     * @return T
     */
    public function getTypeIdentifier(): TypeIdentifier
    {
        return $this->typeIdentifier;
    }

    /**
     * Get the underlying builtin type.
     */
    public function getBuiltinType(): BuiltinType
    {
        return Type::builtin($this->typeIdentifier);
    }

    public function getExplicitType(): string
    {
        return $this->explicitType;
    }

    public function isIdentifiedBy(TypeIdentifier|string ...$identifiers): bool
    {
        foreach ($identifiers as $identifier) {
            if (\is_string($identifier)) {
                try {
                    $identifier = TypeIdentifier::from($identifier);
                } catch (\ValueError) {
                    continue;
                }
            }

            if ($identifier === $this->typeIdentifier) {
                return true;
            }
        }

        return false;
    }

    public function isNullable(): bool
    {
        return false;
    }

    public function accepts(mixed $value): bool
    {
        return match ($this->typeIdentifier) {
            TypeIdentifier::ARRAY => \is_array($value),
            TypeIdentifier::INT => \is_int($value),
            default => false,
        };
    }

    public function __toString(): string
    {
        return $this->explicitType;
    }
}
