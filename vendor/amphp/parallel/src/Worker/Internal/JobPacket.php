<?php declare(strict_types=1);

namespace Amp\Parallel\Worker\Internal;

/** @internal */
abstract class JobPacket
{
    public function __construct(
        private readonly string $id,
    ) {
    }

    /**
     * @return string Task identifier.
     */
    final public function getId(): string
    {
        return $this->id;
    }
}
