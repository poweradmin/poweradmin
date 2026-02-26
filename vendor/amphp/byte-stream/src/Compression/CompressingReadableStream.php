<?php declare(strict_types=1);
/** @noinspection PhpComposerExtensionStubsInspection */

namespace Amp\ByteStream\Compression;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\ReadableStreamIteratorAggregate;
use Amp\ByteStream\StreamException;
use Amp\Cancellation;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;

/**
 * Allows compression of input streams using Zlib.
 *
 * @implements \IteratorAggregate<int, string>
 */
final class CompressingReadableStream implements ReadableStream, \IteratorAggregate
{
    use ReadableStreamIteratorAggregate;
    use ForbidCloning;
    use ForbidSerialization;

    private ?\DeflateContext $deflateContext;

    /**
     * @param ReadableStream $source Input stream to read data from.
     * @param int $encoding Compression algorithm used, see `deflate_init()`.
     * @param array $options Algorithm options, see `deflate_init()`.
     *
     * @see http://php.net/manual/en/function.deflate-init.php
     */
    public function __construct(
        private readonly ReadableStream $source,
        private readonly int $encoding,
        private readonly array $options = [],
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
        $this->source->close();
        $this->deflateContext = null;
    }

    public function read(?Cancellation $cancellation = null): ?string
    {
        if ($this->deflateContext === null) {
            return null;
        }

        $data = $this->source->read($cancellation);

        // Needs a double guard, as stream might have been closed while reading
        /** @psalm-suppress TypeDoesNotContainNull */
        if ($this->deflateContext === null) {
            return null;
        }

        \set_error_handler(function ($errno, $message) {
            $this->close();

            throw new StreamException("Failed adding data to deflate context: $message");
        });

        try {
            if ($data === null) {
                /** @psalm-suppress InvalidArgument */
                $compressed = \deflate_add($this->deflateContext, "", \ZLIB_FINISH);

                $this->close();
            } else {
                /** @psalm-suppress InvalidArgument */
                $compressed = \deflate_add($this->deflateContext, $data, \ZLIB_SYNC_FLUSH);
            }
        } finally {
            \restore_error_handler();
        }

        if ($compressed === false) {
            $this->close();

            throw new StreamException("Failed adding data to deflate context");
        }

        return $compressed;
    }

    public function isReadable(): bool
    {
        return $this->deflateContext !== null && $this->source->isReadable();
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
        return $this->deflateContext === null || $this->source->isClosed();
    }

    public function onClose(\Closure $onClose): void
    {
        $this->source->onClose($onClose);
    }
}
