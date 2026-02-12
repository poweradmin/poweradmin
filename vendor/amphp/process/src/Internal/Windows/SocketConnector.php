<?php declare(strict_types=1);

namespace Amp\Process\Internal\Windows;

use Amp\ByteStream\ReadableResourceStream;
use Amp\ByteStream\WritableResourceStream;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Process\Internal\ProcessStatus;
use Amp\Process\Internal\ProcessStreams;
use Amp\Process\ProcessException;
use Amp\TimeoutCancellation;
use Revolt\EventLoop;
use function Amp\async;

/**
 * @internal
 * @codeCoverageIgnore Windows only.
 */
final class SocketConnector
{
    use ForbidCloning;
    use ForbidSerialization;

    public const SECURITY_TOKEN_SIZE = 16;

    private const SERVER_SOCKET_URI = 'tcp://127.0.0.1:0';

    public string $address;
    public int $port;

    /** @var resource */
    private $server;

    /** @var WindowsHandle[] */
    private array $pendingProcesses = [];

    private string $acceptCallbackId;

    public function __construct()
    {
        $flags = \STREAM_SERVER_LISTEN | \STREAM_SERVER_BIND;
        $this->server = \stream_socket_server(self::SERVER_SOCKET_URI, $errNo, $errStr, $flags);

        if (!$this->server) {
            throw new \Error("Failed to create TCP server socket for process wrapper: {$errNo}: {$errStr}");
        }

        if (!\stream_set_blocking($this->server, false)) {
            throw new \Error("Failed to set server socket to non-blocking mode");
        }

        [$this->address, $port] = \explode(':', \stream_socket_get_name($this->server, false));
        $this->port = (int) $port;

        $this->acceptCallbackId = EventLoop::unreference(EventLoop::onReadable(
            $this->server,
            fn () => $this->acceptClient()
        ));
    }

    public function connectPipes(WindowsHandle $handle, Cancellation $cancellation): ProcessStreams
    {
        EventLoop::reference($this->acceptCallbackId);

        $this->pendingProcesses[$handle->wrapperPid] = $handle;

        try {
            $handle->startBarrier->arrive();
            $handle->startBarrier->await($cancellation);

            $controlPipe = new ReadableResourceStream($handle->sockets[0]);
            $handle->pid = $this->readChildPid($controlPipe, $cancellation);
        } catch (\Throwable $exception) {
            foreach ($handle->sockets as $socket) {
                \fclose($socket);
            }

            throw $exception;
        } finally {
            unset($this->pendingProcesses[$handle->wrapperPid]);

            if (!$this->pendingProcesses) {
                EventLoop::unreference($this->acceptCallbackId);
            }
        }

        /** @psalm-suppress PossiblyUndefinedArrayOffset */
        $streams = new ProcessStreams(
            new WritableResourceStream($handle->sockets[0]),
            new ReadableResourceStream($handle->sockets[1]),
            new ReadableResourceStream($handle->sockets[2]),
        );

        $handle->status = ProcessStatus::Running;

        $handle->exitCodeStream = $controlPipe;

        $stdin = \WeakReference::create($streams->stdin);
        async(function () use ($handle, $stdin, $cancellation): void {
            try {
                $exitCode = $this->readExitCode($handle->exitCodeStream, $cancellation);

                $handle->joinDeferred->complete($exitCode);
            } catch (\Throwable) {
                $handle->joinDeferred->error(new ProcessException("Failed to read exit code from process wrapper"));
            } finally {
                $handle->status = ProcessStatus::Ended;
                $stdin->get()?->close();

                if (\is_resource($handle->sockets[0])) {
                    @\fclose($handle->sockets[0]);
                }
            }
        });

        return $streams;
    }

    private function acceptClient(): void
    {
        $socket = @\stream_socket_accept($this->server);
        if (!$socket) {
            return;
        }

        if (!\stream_set_blocking($socket, false)) {
            throw new \Error("Failed to set client socket to non-blocking mode");
        }

        async(function () use ($socket): void {
            try {
                $handle = $this->performClientHandshake($socket, new TimeoutCancellation(5));
                $handle->startBarrier->arrive();
            } catch (HandshakeException|CancelledException $e) {
                /** @psalm-suppress InvalidScalarArgument */
                \fwrite($socket, \chr(SignalCode::HANDSHAKE_ACK) . \chr($e->getCode()));
                \fclose($socket);
            }
        });
    }

    /**
     * @param resource $socket
     *
     * @throws HandshakeException
     */
    public function performClientHandshake($socket, Cancellation $cancellation): WindowsHandle
    {
        $stream = new ReadableResourceStream($socket);

        $packet = \unpack(
            'Csignal/Npid/Cstream_id/a*client_token',
            $this->read($stream, $cancellation, length: self::SECURITY_TOKEN_SIZE + 6)
        );

        // validate the client's handshake
        if ($packet['signal'] !== SignalCode::HANDSHAKE) {
            throw new HandshakeException(HandshakeStatus::SIGNAL_UNEXPECTED);
        }

        if ($packet['stream_id'] > 2) {
            throw new HandshakeException(HandshakeStatus::INVALID_STREAM_ID);
        }

        if (!isset($this->pendingProcesses[$packet['pid']])) {
            throw new HandshakeException(HandshakeStatus::INVALID_PROCESS_ID);
        }

        $handle = $this->pendingProcesses[$packet['pid']];

        if (isset($handle->sockets[$packet['stream_id']])) {
            throw new HandshakeException(HandshakeStatus::DUPLICATE_STREAM_ID);
        }

        if (!\hash_equals($packet['client_token'], $handle->securityTokens[$packet['stream_id']])) {
            throw new HandshakeException(HandshakeStatus::INVALID_CLIENT_TOKEN);
        }

        $ackData = \chr(SignalCode::HANDSHAKE_ACK) . \chr(HandshakeStatus::SUCCESS) . $handle->securityTokens[$packet['stream_id'] + 3];

        // Unless we set the security token size so high that it won't fit in the
        // buffer, this probably shouldn't ever happen unless something has gone wrong
        if (\fwrite($socket, $ackData) !== self::SECURITY_TOKEN_SIZE + 2) {
            throw new HandshakeException(HandshakeStatus::ACK_WRITE_ERROR);
        }

        $clientPid = (int) $packet['pid'];
        $clientStreamId = (int) $packet['stream_id'];

        // can happen if the start promise was failed
        if (!isset($this->pendingProcesses[$clientPid]) || $this->pendingProcesses[$clientPid]->status === ProcessStatus::Ended) {
            throw new HandshakeException(HandshakeStatus::NO_LONGER_PENDING);
        }

        $packet = \unpack('Csignal/Cstatus', $this->read($stream, $cancellation, length: 2));

        if ($packet['signal'] !== SignalCode::HANDSHAKE_ACK || $packet['status'] !== HandshakeStatus::SUCCESS) {
            throw new HandshakeException(HandshakeStatus::ACK_STATUS_ERROR);
        }

        $handle->sockets[$clientStreamId] = $socket;

        return $handle;
    }

    /**
     * @return positive-int
     */
    private function readChildPid(ReadableResourceStream $stream, Cancellation $cancellation): int
    {
        $packet = \unpack('Csignal/Npid', $this->read($stream, $cancellation, length: 5));
        if ($packet['signal'] !== SignalCode::CHILD_PID) {
            throw new HandshakeException(HandshakeStatus::SIGNAL_UNEXPECTED);
        }

        $pid = (int) $packet['pid'];
        \assert($pid > 0, 'Expected positive integer for PID');
        return $pid;
    }

    private function readExitCode(ReadableResourceStream $stream, Cancellation $cancellation): int
    {
        $packet = \unpack('Csignal/Ncode', $this->read($stream, $cancellation, length: 5));

        if ($packet['signal'] !== SignalCode::EXIT_CODE) {
            throw new HandshakeException(HandshakeStatus::SIGNAL_UNEXPECTED);
        }

        return (int) $packet['code'];
    }

    private function read(ReadableResourceStream $stream, Cancellation $cancellation, int $length): string
    {
        $buffer = '';

        do {
            $remaining = $length - \strlen($buffer);
            \assert($remaining > 0);

            $chunk = $stream->read($cancellation, limit: $remaining);
            if ($chunk === null) {
                break;
            }

            $buffer .= $chunk;
        } while (\strlen($buffer) < $length);

        if (\strlen($buffer) !== $length) {
            throw new ProcessException('Received ' . \strlen($buffer) . ' of ' . $length . ' expected bytes');
        }

        return $buffer;
    }
}
