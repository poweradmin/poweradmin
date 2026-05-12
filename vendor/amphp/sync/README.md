# amphp/sync

AMPHP is a collection of event-driven libraries for PHP designed with fibers and concurrency in mind.
`amphp/sync` specifically provides synchronization primitives such as locks and semaphores for asynchronous and concurrent programming.

[![Latest Release](https://img.shields.io/github/release/amphp/sync.svg?style=flat-square)](https://github.com/amphp/sync/releases)
[![MIT License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](https://github.com/amphp/sync/blob/master/LICENSE)

## Installation

This package can be installed as a [Composer](https://getcomposer.org/) dependency.

```bash
composer require amphp/sync
```

## Usage

The weak link when managing concurrency is humans; so `amphp/sync` provides abstractions to hide some complexity.

### Mutex

[Mutual exclusion](https://en.wikipedia.org/wiki/Mutual_exclusion) can be achieved using `Amp\Sync\synchronized()` and any `Mutex` implementation, or by manually using the `Mutex` instance to acquire a `Lock`.

As long as the resulting `Lock` object isn't released using `Lock::release()` or by being garbage collected, the holder of the lock can exclusively run some code as long as all other parties running the same code also acquire a lock before doing so.

```php
function writeExclusively(Amp\Sync\Mutex $mutex, string $filePath, string $data) {
    $lock = $mutex->acquire();
    
    try {
        Amp\File\write($filePath, $data);
    } finally {
        $lock->release();
    }
}
```

```php
function writeExclusively(Amp\Sync\Mutex $mutex, string $filePath, string $data) {
    Amp\Sync\synchronized($mutex, fn () => Amp\File\write($filePath, $data));
}
```

### Semaphore

[Semaphores](https://en.wikipedia.org/wiki/Semaphore_%28programming%29) are another synchronization primitive in addition to [mutual exclusion](#mutex).

Instead of providing exclusive access to a single party, they provide access to a limited set of N parties at the same time.
This makes them great to control concurrency, e.g. limiting an HTTP client to X concurrent requests, so the HTTP server doesn't get overwhelmed.

Similar to [`Mutex`](#mutex), `Lock` instances can be acquired using `Semaphore::acquire()`.
Please refer to the [`Mutex`](#mutex) documentation for additional usage documentation, as they're basically equivalent except for the fact that `Mutex` is always a `Semaphore` with a count of exactly one party.

In many cases you can use [`amphp/pipeline`](https://github.com/amphp/pipeline) instead of directly using a `Semaphore`.

### Parcel

A Parcel is used to synchronize access to a value across multiple execution contexts, such as multiple coroutines or multiple processes. The example below demonstrates using a `LocalParcel` to share an integer between two coroutines.

```php
use Amp\Future;
use Amp\Sync\LocalMutex;
use Amp\Sync\LocalParcel;
use function Amp\async;
use function Amp\delay;

$parcel = new LocalParcel(new LocalMutex(), 42);

$future1 = async(function () use ($parcel): void {
    echo "Coroutine 1 started\n";

    $result = $parcel->synchronized(function (int $value): int {
        delay(1); // Delay for 1s to simulate I/O.
        return $value * 2;
    });

    echo "Value after access in coroutine 1: ", $result, "\n";
});

$future2 = async(function () use ($parcel): void {
    echo "Coroutine 2 started\n";

    $result = $parcel->synchronized(function (int $value): int {
        delay(1); // Delay again in this coroutine.
        return $value + 8;
    });

    echo "Value after access in coroutine 2: ", $result, "\n";
});

Future\await([$future1, $future2]); // Wait until both coroutines complete.
```

### Channels

Channels are used to send data between execution contexts, such as multiple coroutines or multiple processes. The example below shares two `Channel` between two coroutines. These channels are connected. Data sent on a channel is received on the paired channel and vice-versa.

```php
use Amp\Future;
use function Amp\async;
use function Amp\delay;

[$left, $right] = createChannelPair();

$future1 = async(function () use ($left): void {
    echo "Coroutine 1 started\n";
    delay(1); // Delay to simulate I/O.
    $left->send(42);
    $received = $left->receive();
    echo "Received ", $received, " in coroutine 1\n";
});

$future2 = async(function () use ($right): void {
    echo "Coroutine 2 started\n";
    $received = $right->receive();
    echo "Received ", $received, " in coroutine 2\n";
    delay(1); // Delay to simulate I/O.
    $right->send($received * 2);
});

Future\await([$future1, $future2]); // Wait until both coroutines complete.
```

### Sharing data between processes

To share data between processes in PHP, the data must be serializable and use external storage or an IPC (inter-process communication) channel.

#### Parcels in external storage

`SharedMemoryParcel` uses shared memory conjunction with `PosixSemaphore` wrapped in `SemaphoreMutex` (though another cross-context mutex implementation may be used, such as `RedisMutex` in [`amphp/redis`](https://github.com/amphp/redis)).

> **Note**
> `ext-shmop` and `ext-sysvmsg` are required for `SharedMemoryParcel` and `PosixSemaphore` respectively.

[`amphp/redis`](https://github.com/amphp/redis) provides `RedisParcel` for storing shared data in Redis.

#### Channels over pipes

Channels between processes can be created by layering serialization (native PHP serialization, JSON serialization, etc.) on a pipe between those processes.

`StreamChannel` in [`amphp/byte-stream`](https://github.com/amphp/byte-stream) creates a channel from any `ReadableStream` and `WritableStream`. This allows a channel to be created from a variety of stream sources, such as sockets or process pipes.

`ProcessContext` in [`amphp/parallel`](https://github.com/amphp/parallel) implements `Channel` to send data between parent and child processes.

Task `Execution` objects, also in [`amphp/parallel`](https://github.com/amphp/parallel) contain a `Channel` to send data between the task run and the process which submitted the task.

### Concurrency Approaches

Given you have a list of URLs you want to crawl, let's discuss a few possible approaches. For simplicity, we will assume a `fetch` function already exists, which takes a URL and returns the HTTP status code (which is everything we want to know for these examples).

#### Approach 1: Sequential

Simple loop using non-blocking I/O, but no concurrency while fetching the individual URLs; starts the second request as soon as the first completed.

```php
$urls = [...];

$results = [];

foreach ($urls as $url) {
    $results[$url] = fetch($url);
}

var_dump($results);
```

#### Approach 2: Everything Concurrently

Almost the same loop, but awaiting all operations at once; starts all requests immediately. Might not be feasible with too many URLs.

```php
$urls = [...];

$results = [];

foreach ($urls as $url) {
    $results[$url] = Amp\async(fetch(...), $url);
}

$results = Amp\Future\await($results);

var_dump($results);
```

#### Approach 3: Concurrent Chunks

Splitting the jobs into chunks of ten; all requests within a chunk are made concurrently, but each chunk sequentially, so the timing for each chunk depends on the slowest response; starts the eleventh request as soon as the first ten requests completed.

```php
$urls = [...];

$results = [];

foreach (\array_chunk($urls, 10) as $chunk) {
    $futures = [];

    foreach ($chunk as $url) {
        $futures[$url] = Amp\async(fetch(...), $url);
    }

    $results = \array_merge($results, Amp\Future\await($futures));
}

var_dump($results);
```

#### Approach 4: ConcurrentIterator

The [`amphp/pipeline`](https://github.com/amphp/pipeline) library provides concurrent iterators which can be used to process and consume data concurrently in multiple fibers.

```php
use Amp\Pipeline\Pipeline;
use function Amp\delay;

$urls = [...];

$results = Pipeline::fromIterable($urls)
    ->concurrent(10) // Process up to 10 URLs concurrently
    ->unordered() // Results may arrive out of order
    ->map(fetch(...)) // Map each URL to fetch(...)
    ->toArray();

var_dump($results);
```

See the documentation in [`amphp/pipeline`](https://github.com/amphp/pipeline) for more information on using Pipelines for concurrency.

## Versioning

`amphp/sync` follows the [semver](http://semver.org/) semantic versioning specification like all other `amphp` packages.

## Security

If you discover any security related issues, please use the private security issue reporter instead of using the public issue tracker.

## License

The MIT License (MIT). Please see [`LICENSE`](./LICENSE) for more information.
