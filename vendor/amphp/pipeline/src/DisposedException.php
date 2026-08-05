<?php declare(strict_types=1);

namespace Amp\Pipeline;

/**
 * Will be thrown from {@see Queue::push()} or used to fail the future returned from {@see Queue::pushAsync()}
 * if the associated iterator is disposed.
 */
final class DisposedException extends \Exception
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct("The iterator has been disposed", 0, $previous);
    }
}
