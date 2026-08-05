<?php declare(strict_types=1);

namespace Amp\Parallel\Worker\Internal;

/** @internal */
final class JobMessage extends JobPacket
{
    public function __construct(
        string $id,
        private readonly mixed $message,
    ) {
        parent::__construct($id);
    }

    public function getMessage(): mixed
    {
        return $this->message;
    }
}
