<?php declare(strict_types=1);

namespace Amp\Parallel\Context\Internal;

use Amp\ByteStream;
use Amp\Parallel\Context\ProcessContext;
use Amp\Parallel\Ipc;
use Amp\TimeoutCancellation;
use Revolt\EventLoop;

\define("AMP_CONTEXT", "process");
\define("AMP_CONTEXT_ID", \getmypid());

// Doesn't exist in phpdbg...
if (\function_exists("cli_set_process_title")) {
    \set_error_handler(static fn () => true);
    try {
        \cli_set_process_title("amp-process");
    } finally {
        \restore_error_handler();
    }
}

(function (): void {
    $paths = [
        \dirname(__DIR__, 5) . "/autoload.php",
        \dirname(__DIR__, 3) . "/vendor/autoload.php",
    ];

    foreach ($paths as $path) {
        if (\file_exists($path)) {
            $autoloadPath = $path;
            break;
        }
    }

    if (!isset($autoloadPath)) {
        \trigger_error(
            "Could not locate autoload.php in any of the following files: " . \implode(", ", $paths),
            E_USER_ERROR,
        );
    }

    /** @psalm-suppress UnresolvableInclude */
    require $autoloadPath;
})();

// Wrap in a closure to keep local variables out of global scope
(function () use ($argv, $argc): void {
    try {
        foreach (ProcessContext::getIgnoredSignals() as $signal) {
            EventLoop::unreference(EventLoop::onSignal($signal, static fn () => null));
        }
    } catch (EventLoop\UnsupportedFeatureException) {
        // Signal handling not supported on current event loop driver.
    }

    /** @var list<string> $argv */

    if (!isset($argv[1])) {
        \trigger_error("No socket path provided", E_USER_ERROR);
    }

    if (!isset($argv[2]) || !\is_numeric($argv[2])) {
        \trigger_error("No key length provided", E_USER_ERROR);
    }

    if (!isset($argv[3]) || !\is_numeric($argv[3])) {
        \trigger_error("No timeout provided", E_USER_ERROR);
    }

    [, $uri, $length, $timeout] = $argv;
    $length = (int) $length;
    $timeout = (int) $timeout;

    \assert($length > 0 && $timeout > 0);

    // Remove script path, socket path, key length, and timeout from process arguments.
    $argv = \array_slice($argv, 4);

    try {
        $cancellation = new TimeoutCancellation($timeout);
        $key = Ipc\readKey(ByteStream\getStdin(), $cancellation, $length);
    } catch (\Throwable $exception) {
        \trigger_error($exception->getMessage(), E_USER_ERROR);
    }

    runContext($uri, $key, $cancellation, $argv);
})();
