<?php declare(strict_types=1);
/** @noinspection PhpComposerExtensionStubsInspection */

namespace Amp\ByteStream\Compression;

use Amp\ByteStream\ClosedException;
use Amp\ByteStream\StreamException;
use Amp\ByteStream\WritableStream;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;

/**
 * Allows compression of output streams using Zlib.
 */
final class CompressingWritableStream implements WritableStream
{
    use ForbidCloning;
    use ForbidSerialization;

    private ?\DeflateContext $deflateContext;

    /**
     * @param WritableStream $destination Output stream to write the compressed data to.
     * @param int $encoding Compression encoding to use, see `deflate_init()`.
     * @param array $options Compression options to use, see `deflate_init()`.
     *
     * @see http://php.net/manual/en/function.deflate-init.php
     */
    public function __construct(
        private readonly WritableStream $destination,
        private readonly int $encoding,
        private readonly array $options = []
    ) {
        \set_error_handler(function ($errno, $message) {
            $this->close();

            throw new \Error("Failed initializing deflate context: $message");
        });

        try {
            /** @psalm-suppress InvalidPropertyAssignmentValue */
            $this->deflateContext = \deflate_init($encoding, $options);
        } finally {
            \restore_error_handler();
        }
    }

    public function close(): void
    {
        $this->destination->close();
    }

    public function end(): void
    {
        if ($this->deflateContext === null) {
            throw new ClosedException("The stream has already been closed");
        }

        \set_error_handler(function ($errno, $message) {
            $this->close();

            throw new StreamException("Failed adding data to deflate context: $message");
        });

        try {
            /** @psalm-suppress InvalidArgument */
            $compressed = \deflate_add($this->deflateContext, '', \ZLIB_FINISH);
        } finally {
            \restore_error_handler();
        }

        if ($compressed === false) {
            $this->close();

            throw new StreamException("Failed adding data to deflate context");
        }

        $this->deflateContext = null;

        $this->destination->write($compressed);
        $this->destination->end();
    }

    public function write(string $bytes): void
    {
        if ($this->deflateContext === null) {
            throw new ClosedException("The stream has already been closed");
        }

        \set_error_handler(function ($errno, $message) {
            $this->close();

            throw new StreamException("Failed adding data to deflate context: $message");
        });

        try {
            /** @psalm-suppress InvalidArgument */
            $compressed = \deflate_add($this->deflateContext, $bytes, \ZLIB_SYNC_FLUSH);
        } finally {
            \restore_error_handler();
        }

        if ($compressed === false) {
            $this->close();

            throw new StreamException("Failed adding data to deflate context");
        }

        $this->destination->write($compressed);
    }

    public function isWritable(): bool
    {
        return $this->deflateContext !== null && $this->destination->isWritable();
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

    public function isClosed(): bool
    {
        return $this->deflateContext === null || $this->destination->isClosed();
    }

    public function onClose(\Closure $onClose): void
    {
        $this->destination->onClose($onClose);
    }
}
