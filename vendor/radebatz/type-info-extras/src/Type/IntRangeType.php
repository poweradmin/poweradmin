<?php declare(strict_types=1);

namespace Radebatz\TypeInfoExtras\Type;

use Symfony\Component\TypeInfo\TypeIdentifier;

/**
 * Int range type.
 *
 * @author Martin Rademacher <mano@radebatz.net>
 *
 * @extends ExplicitType<TypeIdentifier::INT>
 */
final class IntRangeType extends ExplicitType
{
    public function __construct(
        private int $from = \PHP_INT_MIN,
        private int $to = \PHP_INT_MAX,
        ?string $explicitType = null,
    ) {
        parent::__construct(TypeIdentifier::INT, $explicitType ?? $this->canonical($this->from, $this->to));
    }

    public function getFrom(): int
    {
        return $this->from;
    }

    public function getTo(): int
    {
        return $this->to;
    }

    public function accepts(mixed $value): bool
    {
        return parent::accepts($value) && $this->from <= $value && $value <= $this->to;
    }

    public function __toString(): string
    {
        return $this->canonical($this->from, $this->to);
    }

    protected function canonical(int $from, int $to): string
    {
        $min = \PHP_INT_MIN === $from ? 'min' : $from;
        $max = \PHP_INT_MAX === $to ? 'max' : $to;

        return \sprintf('int<%s, %s>', $min, $max);
    }
}
