<?php declare(strict_types=1);

namespace Amp\Dns\Internal;

use Amp\CancelledException;
use Amp\DeferredFuture;
use Amp\Dns\DnsException;
use Amp\Dns\DnsTimeoutException;
use Amp\Parser\Parser;
use Amp\TimeoutCancellation;
use LibDNS\Decoder\DecoderFactory;
use LibDNS\Encoder\Encoder;
use LibDNS\Encoder\EncoderFactory;
use LibDNS\Messages\Message;
use Revolt\EventLoop;

/** @internal */
final class TcpSocket extends Socket
{
    /**
     * @throws DnsTimeoutException
     * @throws DnsException
     */
    public static function connect(string $uri, float $timeout = 5): self
    {
        $socket = self::openSocket($uri);

        \stream_set_blocking($socket, false);

        $deferred = new DeferredFuture;

        $watcher = EventLoop::onWritable($socket, static function (string $watcher) use ($socket, $deferred): void {
            EventLoop::cancel($watcher);

            $deferred->complete(new self($socket));
        });

        try {
            return $deferred->getFuture()->await(new TimeoutCancellation($timeout));
        } catch (CancelledException) {
            EventLoop::cancel($watcher);

            throw new DnsTimeoutException("Name resolution timed out, could not connect to server at $uri");
        }
    }

    private static function parser(callable $callback): \Generator
    {
        $decoder = (new DecoderFactory)->create();

        while (true) {
            /** @var string $length */
            $length = yield 2;
            $length = \unpack("n", $length)[1];

            $rawData = yield $length;
            $callback($decoder->decode($rawData));
        }
    }

    private readonly Encoder $encoder;
    private readonly \SplQueue $queue;
    private readonly Parser $parser;
    private bool $isAlive = true;

    /**
     * @param resource $socket
     */
    protected function __construct($socket)
    {
        parent::__construct($socket);

        $this->encoder = (new EncoderFactory)->create();
        $this->queue = new \SplQueue;
        $this->parser = new Parser(self::parser([$this->queue, 'push']));
    }

    public function isAlive(): bool
    {
        return $this->isAlive;
    }

    protected function send(Message $message): void
    {
        $data = $this->encoder->encode($message);
        try {
            $this->write(\pack("n", \strlen($data)) . $data);
        } catch (\Throwable $exception) {
            $this->isAlive = false;
            throw $exception;
        }
    }

    protected function receive(): Message
    {
        while ($this->queue->isEmpty()) {
            $chunk = $this->read();

            if ($chunk === null) {
                $this->isAlive = false;
                throw new DnsException("Reading from the server failed");
            }

            $this->parser->push($chunk);
        }

        return $this->queue->shift();
    }
}
