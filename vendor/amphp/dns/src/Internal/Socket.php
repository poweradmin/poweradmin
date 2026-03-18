<?php declare(strict_types=1);

namespace Amp\Dns\Internal;

use Amp\ByteStream\ClosedException;
use Amp\ByteStream\ReadableResourceStream;
use Amp\ByteStream\StreamException;
use Amp\ByteStream\WritableResourceStream;
use Amp\Cancellation;
use Amp\DeferredFuture;
use Amp\Dns\DnsException;
use Amp\Dns\DnsTimeoutException;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use LibDNS\Messages\Message;
use LibDNS\Messages\MessageFactory;
use LibDNS\Messages\MessageTypes;
use LibDNS\Records\Question;
use Revolt\EventLoop;
use function Amp\now;
use function Amp\weakClosure;

/** @internal */
abstract class Socket
{
    use ForbidCloning;
    use ForbidSerialization;

    private const MAX_CONCURRENT_REQUESTS = 500;

    protected int $invalidPacketsReceived = 0;

    abstract public static function connect(string $uri): self;

    private readonly ReadableResourceStream $input;

    private readonly WritableResourceStream $output;

    /**
     * Contains already sent queries with no response yet. For UDP this is exactly zero or one item.
     *
     * @var \ArrayObject<int, object{deferred: DeferredFuture|null, question: Question}>
     */
    private readonly \ArrayObject $pending;

    private readonly MessageFactory $messageFactory;

    /** @var float Used for determining whether the socket can be garbage collected, because it's inactive. */
    private float $lastActivity;

    private bool $receiving = false;

    /** @var \SplQueue<EventLoop\Suspension> Queued requests if the number of concurrent requests is too large. */
    private readonly \SplQueue $queue;

    /**
     * @return resource
     */
    final protected static function openSocket(string $uri)
    {
        \set_error_handler(static fn () => true);

        try {
            $socket = \stream_socket_client($uri, $errno, $errstr, flags: \STREAM_CLIENT_ASYNC_CONNECT);
        } finally {
            \restore_error_handler();
        }

        if (!$socket) {
            throw new DnsException(\sprintf(
                'Connection to %s failed: (Error #%d) %s',
                $uri,
                $errno,
                $errstr,
            ));
        }

        return $socket;
    }

    /**
     * @param resource $socket
     */
    protected function __construct($socket)
    {
        $this->pending = new \ArrayObject();
        $this->queue = new \SplQueue();

        $this->input = new ReadableResourceStream($socket);
        $this->output = new WritableResourceStream($socket);
        $this->messageFactory = new MessageFactory();
        $this->lastActivity = now();
    }

    private function fetch(): void
    {
        EventLoop::queue(function (): void {
            try {
                try {
                    $message = $this->receive();
                } finally {
                    $this->lastActivity = now();
                    $this->receiving = false;
                }
            } catch (\Throwable $exception) {
                $this->handleError($exception);
                return;
            }

            $this->handleMessage($message);
        });
    }

    private function handleMessage(Message $message): void
    {
        $id = $message->getId();

        // Ignore duplicate and invalid responses.
        if (isset($this->pending[$id]) && $this->matchesQuestion($message, $this->pending[$id]->question)) {
            $pending = $this->pending[$id];
            unset($this->pending[$id]);

            $pending->deferred?->complete(static fn () => $message);
            $pending->deferred = null;
        }

        /** @psalm-suppress RedundantCondition */
        if (!$this->pending->count()) {
            $this->input->unreference();
        } elseif (!$this->receiving) {
            $this->input->reference();
            $this->receiving = true;
            $this->fetch();
        }
    }

    abstract public function isAlive(): bool;

    public function getLastActivity(): float
    {
        return $this->lastActivity;
    }

    /**
     * @throws DnsException
     */
    final public function ask(Question $question, float $timeout, ?Cancellation $cancellation = null): Message
    {
        $this->lastActivity = now();

        if ($this->pending->count() > self::MAX_CONCURRENT_REQUESTS) {
            $suspension = EventLoop::getSuspension();
            $this->queue->enqueue($suspension);
            $suspension->suspend();
        }

        do {
            $id = \random_int(0, 0xffff);
        } while (isset($this->pending[$id]));

        /** @var DeferredFuture<\Closure():Message> $deferred */
        $deferred = new DeferredFuture;

        $invalidPacketsReceived = &$this->invalidPacketsReceived;

        /** @psalm-suppress InaccessibleProperty, InvalidArgument $this->pending is an ArrayObject */
        $this->pending[$id] = new class($this->pending, $id, $deferred, $question, $timeout, $invalidPacketsReceived) {
            private readonly string $callbackId;

            public ?DeferredFuture $deferred;

            public function __construct(
                \ArrayObject $pending,
                int $id,
                DeferredFuture $deferred,
                public readonly Question $question,
                float $timeout,
                int &$invalidPacketsReceived
            ) {
                $this->deferred = $deferred;

                $this->callbackId = EventLoop::unreference(EventLoop::delay(
                    $timeout,
                    weakClosure(function () use ($id, $pending, $timeout, &$invalidPacketsReceived): void {
                        if ($invalidPacketsReceived > 0) {
                            $this->deferred?->complete(static fn () => throw new DnsTimeoutException(
                                "Didn't receive a response within {$timeout} seconds, but received {$invalidPacketsReceived} invalid packets on this socket"
                            ));
                        } else {
                            $this->deferred?->complete(static fn () => throw new DnsTimeoutException(
                                "Didn't receive a response within {$timeout} seconds."
                            ));
                        }

                        $this->deferred = null;

                        unset($pending[$id]);
                    }),
                ));
            }

            public function __destruct()
            {
                EventLoop::cancel($this->callbackId);
            }
        };

        $message = $this->createMessage($question, $id);

        try {
            $this->send($message);
        } catch (StreamException $exception) {
            $exception = new DnsException("Sending the request failed", 0, $exception);
            $this->handleError($exception);
            throw $exception;
        }

        $this->input->reference();

        if (!$this->receiving) {
            $this->receiving = true;
            $this->fetch();
        }

        try {
            $callback = $deferred->getFuture()->await($cancellation);
        } finally {
            /** @psalm-suppress TypeDoesNotContainType */
            if (!$this->pending->count()) {
                $this->input->unreference();
            }

            if (!$this->queue->isEmpty()) {
                $suspension = $this->queue->dequeue();
                $suspension->resume();
            }
        }

        return $callback();
    }

    final public function close(): void
    {
        $this->handleError(new ClosedException('Socket has been closed'));
    }

    /**
     * @throws StreamException
     */
    abstract protected function send(Message $message): void;

    /**
     * @throws DnsException
     */
    abstract protected function receive(): Message;

    final protected function read(): ?string
    {
        return $this->input->read();
    }

    /**
     * @throws ClosedException
     */
    final protected function write(string $data): void
    {
        $this->output->write($data);
    }

    final protected function createMessage(Question $question, int $id): Message
    {
        $request = $this->messageFactory->create(MessageTypes::QUERY);
        $request->getQuestionRecords()->add($question);
        $request->isRecursionDesired(true);
        $request->setID($id);

        return $request;
    }

    private function handleError(\Throwable $exception): void
    {
        $this->input->close();
        $this->output->close();

        if (!$exception instanceof DnsException) {
            $message = "Unexpected error during resolution: " . $exception->getMessage();
            $exception = new DnsException($message, 0, $exception);
        }

        foreach ($this->pending as $id => $pendingQuestion) {
            $pendingQuestion->deferred?->error($exception);
            $pendingQuestion->deferred = null;

            unset($this->pending[$id]);
        }

        while (!$this->queue->isEmpty()) {
            $this->queue->dequeue()->throw($exception);
        }
    }

    private function matchesQuestion(Message $message, Question $question): bool
    {
        if ($message->getType() !== MessageTypes::RESPONSE) {
            return false;
        }

        $questionRecords = $message->getQuestionRecords();

        // We only ever ask one question at a time
        if (\count($questionRecords) !== 1) {
            return false;
        }

        $questionRecord = $questionRecords->getIterator()->current();

        if ($questionRecord->getClass() !== $question->getClass()) {
            return false;
        }

        if ($questionRecord->getType() !== $question->getType()) {
            return false;
        }

        if ($questionRecord->getName()->getValue() !== $question->getName()->getValue()) {
            return false;
        }

        return true;
    }
}
