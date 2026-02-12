<?php declare(strict_types=1);

namespace Amp\Parallel\Context\Internal;

use Amp\ByteStream\StreamChannel;
use Amp\Cancellation;
use Amp\Future;
use Amp\Parallel\Ipc;
use Amp\Serialization\SerializationException;
use Revolt\EventLoop;

/** @internal */
function runContext(string $uri, string $key, Cancellation $connectCancellation, array $argv): void
{
    EventLoop::queue(function () use ($argv, $uri, $key, $connectCancellation): void {
        /** @noinspection PhpUnusedLocalVariableInspection */
        $argc = \count($argv);

        try {
            $socket = Ipc\connect($uri, $key, $connectCancellation);
            $ipcChannel = new StreamChannel($socket, $socket);

            $socket = Ipc\connect($uri, $key, $connectCancellation);
            $resultChannel = new StreamChannel($socket, $socket);
        } catch (\Throwable $exception) {
            \trigger_error($exception->getMessage(), E_USER_ERROR);
        }

        try {
            if (!isset($argv[0])) {
                throw new \Error("No script path given");
            }

            if (!\is_file($argv[0])) {
                throw new \Error(\sprintf(
                    "No script found at '%s' (be sure to provide the full path to the script)",
                    $argv[0],
                ));
            }

            try {
                // Protect current scope by requiring script within another function.
                // Using $argc, so it is available to the required script.
                $callable = (function () use ($argc, $argv): callable {
                    /** @psalm-suppress UnresolvableInclude */
                    return require $argv[0];
                })();
            } catch (\TypeError $exception) {
                throw new \Error(\sprintf(
                    "Script '%s' did not return a callable function: %s",
                    $argv[0],
                    $exception->getMessage(),
                ), 0, $exception);
            } catch (\ParseError $exception) {
                throw new \Error(\sprintf(
                    "Script '%s' contains a parse error: %s",
                    $argv[0],
                    $exception->getMessage(),
                ), 0, $exception);
            }

            $returnValue = $callable(new ContextChannel($ipcChannel));
            $result = new ExitSuccess($returnValue instanceof Future ? $returnValue->await() : $returnValue);
        } catch (\Throwable $exception) {
            $result = new ExitFailure($exception);
        }

        try {
            try {
                $resultChannel->send($result);
            } catch (SerializationException $exception) {
                // Serializing the result failed. Send the reason why.
                $resultChannel->send(new ExitFailure($exception));
            }
        } catch (\Throwable $exception) {
            \trigger_error(\sprintf(
                "Could not send result to parent: '%s'; be sure to shutdown the child before ending the parent",
                $exception->getMessage(),
            ), E_USER_ERROR);
        }
    });

    EventLoop::run();
}
