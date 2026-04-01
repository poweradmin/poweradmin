<?php declare(strict_types=1);

namespace Amp\Socket;

use Amp\ByteStream\ClosedException;
use Amp\ByteStream\ReadableResourceStream;
use Amp\ByteStream\ReadableStreamIteratorAggregate;
use Amp\ByteStream\ResourceStream;
use Amp\ByteStream\WritableResourceStream;
use Amp\Cancellation;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;

/**
 * @implements \IteratorAggregate<int, string>
 */
final class ResourceSocket implements Socket, ResourceStream, \IteratorAggregate
{
    use ForbidCloning;
    use ForbidSerialization;
    use ReadableStreamIteratorAggregate;

    public const DEFAULT_CHUNK_SIZE = ReadableResourceStream::DEFAULT_CHUNK_SIZE;

    /**
     * @param resource $resource Stream resource.
     * @param positive-int $chunkSize Read and write chunk size.
     */
    public static function fromServerSocket($resource, int $chunkSize = self::DEFAULT_CHUNK_SIZE): self
    {
        return new self($resource, null, $chunkSize);
    }

    /**
     * @param resource $resource Stream resource.
     * @param positive-int $chunkSize Read and write chunk size.
     */
    public static function fromClientSocket(
        $resource,
        ?ClientTlsContext $tlsContext = null,
        int $chunkSize = self::DEFAULT_CHUNK_SIZE
    ): self {
        return new self($resource, $tlsContext, $chunkSize);
    }

    private TlsState $tlsState = TlsState::Disabled;

    private ?array $streamContext = null;

    private readonly ReadableResourceStream $reader;

    private readonly WritableResourceStream $writer;

    private readonly SocketAddress $localAddress;

    private readonly SocketAddress $remoteAddress;

    private ?TlsInfo $tlsInfo = null;

    /**
     * @param resource $resource Stream resource.
     * @param positive-int $chunkSize Read and write chunk size.
     */
    private function __construct(
        $resource,
        private readonly ?ClientTlsContext $tlsContext = null,
        int $chunkSize = self::DEFAULT_CHUNK_SIZE,
    ) {
        $this->reader = new ReadableResourceStream($resource, $chunkSize);
        $this->writer = new WritableResourceStream($resource, $chunkSize);
        $this->remoteAddress = SocketAddress\fromResourcePeer($resource);
        $this->localAddress = SocketAddress\fromResourceLocal($resource);
    }

    public function setupTls(?Cancellation $cancellation = null): void
    {
        $resource = $this->getResource();
        if ($resource === null) {
            throw new ClosedException("Can't setup TLS, because the socket has already been closed");
        }

        $context = $this->getStreamContext();

        if (empty($context['ssl'])) {
            throw new TlsException(
                "Can't enable TLS without configuration. If you used Amp\\Socket\\listen(), " .
                "be sure to pass a ServerTlsContext within the BindContext in the second argument, " .
                "otherwise set the 'ssl' context option to the PHP stream resource."
            );
        }

        $this->tlsState = TlsState::SetupPending;

        try {
            /** @psalm-suppress PossiblyInvalidArgument */
            Internal\setupTls($resource, $context, $cancellation);

            $this->tlsState = TlsState::Enabled;
        } catch (\Throwable $exception) {
            $this->close();
            $this->tlsState = TlsState::Disabled;
            throw $exception;
        }
    }

    public function shutdownTls(?Cancellation $cancellation = null): void
    {
        if (($resource = $this->reader->getResource()) === null) {
            throw new ClosedException("Can't shutdown TLS, because the socket has already been closed");
        }

        $this->tlsState = TlsState::ShutdownPending;

        try {
            /** @psalm-suppress PossiblyInvalidArgument */
            Internal\shutdownTls($resource);
        } finally {
            $this->tlsState = TlsState::Disabled;
        }
    }

    public function read(?Cancellation $cancellation = null, ?int $limit = null): ?string
    {
        return $this->reader->read($cancellation, $limit);
    }

    public function write(string $bytes): void
    {
        $this->writer->write($bytes);
    }

    public function end(): void
    {
        $this->writer->end();
    }

    public function close(): void
    {
        $this->reader->close();
        $this->writer->close();
    }

    public function reference(): void
    {
        $this->reader->reference();
        $this->writer->reference();
    }

    public function unreference(): void
    {
        $this->reader->unreference();
        $this->writer->unreference();
    }

    public function getLocalAddress(): SocketAddress
    {
        return $this->localAddress;
    }

    /**
     * @return resource|object|null
     */
    public function getResource()
    {
        return $this->reader->getResource();
    }

    public function getRemoteAddress(): SocketAddress
    {
        return $this->remoteAddress;
    }

    public function isTlsConfigurationAvailable(): bool
    {
        return $this->tlsContext || !empty($this->getStreamContext()['ssl']);
    }

    private function getStreamContext(): ?array
    {
        if ($this->streamContext !== null) {
            return $this->streamContext;
        }

        if ($this->tlsContext) {
            return $this->streamContext = $this->tlsContext->toStreamContextArray();
        }

        $resource = $this->getResource();
        if (!\is_resource($resource)) {
            return null;
        }

        return $this->streamContext = \stream_context_get_options($resource);
    }

    public function getTlsState(): TlsState
    {
        return $this->tlsState;
    }

    public function getTlsInfo(): ?TlsInfo
    {
        if ($this->tlsInfo !== null) {
            return $this->tlsInfo;
        }

        $resource = $this->getResource();
        if (!\is_resource($resource)) {
            return null;
        }

        return $this->tlsInfo = TlsInfo::fromStreamResource($resource);
    }

    public function isClosed(): bool
    {
        return $this->reader->isClosed() && $this->writer->isClosed();
    }

    public function onClose(\Closure $onClose): void
    {
        $this->reader->onClose($onClose);
    }

    /**
     * @param positive-int $chunkSize New default chunk size for reading and writing.
     */
    public function setChunkSize(int $chunkSize): void
    {
        $this->reader->setChunkSize($chunkSize);
        $this->writer->setChunkSize($chunkSize);
    }

    public function isReadable(): bool
    {
        return $this->reader->isReadable();
    }

    public function isWritable(): bool
    {
        return $this->writer->isWritable();
    }
}
