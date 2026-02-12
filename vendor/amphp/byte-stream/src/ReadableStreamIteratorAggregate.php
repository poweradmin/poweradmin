<?php declare(strict_types=1);

namespace Amp\ByteStream;

trait ReadableStreamIteratorAggregate
{
    /** @see ReadableStream::read() */
    abstract public function read(): ?string;

    /**
     * @return \Traversable<int, string>
     */
    public function getIterator(): \Traversable
    {
        while (($chunk = $this->read()) !== null) {
            yield $chunk;
        }
    }
}
