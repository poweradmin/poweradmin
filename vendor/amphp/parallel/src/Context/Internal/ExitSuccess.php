<?php declare(strict_types=1);

namespace Amp\Parallel\Context\Internal;

/**
 * @internal
 * @template-covariant TValue
 * @implements ExitResult<TValue>
 */
final class ExitSuccess implements ExitResult
{
    /**
     * @param TValue $result
     */
    public function __construct(
        private readonly mixed $result
    ) {
    }

    /**
     * @return TValue
     */
    public function getResult(): mixed
    {
        return $this->result;
    }
}
