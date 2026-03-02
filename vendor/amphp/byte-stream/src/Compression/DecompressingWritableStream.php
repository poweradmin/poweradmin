<?php declare(strict_types=1);
/** @noinspection PhpComposerExtensionStubsInspection */

namespace Amp\ByteStream\Compression;

use Amp\ByteStream\ClosedException;
use Amp\ByteStream\StreamException;
use Amp\ByteStream\WritableStream;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;

/**
 * Allows decompression of output streams using Zlib.
 */
final class DecompressingWritableStream implements WritableStream
{
    use ForbidCloning;
    use ForbidSerialization;

    private ?\InflateContext $inflateContext;

    /**
     * @param WritableStream $destination Output stream to write the decompressed data to.
     * @param int $encoding Compression encoding to use, see `inflate_init()`.
     * @param array $options Compression options to use, see `inflate_init()`.
     *
     * @see http://php.net/manual/en/function.inflate-init.php
     */
    public function __construct(
        private readonly WritableStream $destination,
        private readonly int $encoding,
        private readonly array $options = []
    ) {
        \set_error_handler(function ($errno, $message) {
            $this->close();

            throw new \Error("Failed initializing inflate context: $message");
        });

        try {
            /** @psalm-suppress InvalidPropertyAssignmentValue */
            $this->inflateContext = \inflate_init($encoding, $options);
        } finally {
            \restore_error_handler();
        }
    }

    public function write(string $bytes): void
    {
        if ($this->inflateContext === null) {
            throw new ClosedException("The stream has already been closed");
        }

        /** @psalm-suppress InvalidArgument */
        $decompressed = \inflate_add($this->inflateContext, $bytes, \ZLIB_SYNC_FLUSH);

        if ($decompressed === false) {
            $this->close();

            throw new StreamException("Failed adding data to inflate context");
        }

        $this->destination->write($decompressed);
    }

    public function end(): void
    {
        if ($this->inflateContext === null) {
            throw new ClosedException("The stream has already been closed");
        }

        /** @psalm-suppress InvalidArgument */
        $decompressed = \inflate_add($this->inflateContext, '', \ZLIB_FINISH);

        if ($decompressed === false) {
            $this->close();

            throw new StreamException("Failed adding data to inflate context");
        }

        $this->inflateContext = null;

        $this->destination->write($decompressed);
        $this->destination->end();
    }

    public function isWritable(): bool
    {
        return $this->inflateContext !== null && $this->destination->isWritable();
    }

    /**
     * Gets the used compression encoding.
     *
     * @return int Encoding specified on construction time.
     */
    public function getEncoding(): int
    {
        return $this->encoding;
    }

    /**
     * Gets the used compression options.
     *
     * @return array Options array passed on construction time.
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    public function close(): void
    {
        $this->destination->close();
    }

    public function isClosed(): bool
    {
        return $this->inflateContext === null || $this->destination->isClosed();
    }

    public function onClose(\Closure $onClose): void
    {
        $this->destination->onClose($onClose);
    }
}
