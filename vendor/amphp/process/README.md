# amphp/process

AMPHP is a collection of event-driven libraries for PHP designed with fibers and concurrency in mind.
`amphp/process` provides an asynchronous process dispatcher that works on all major platforms (including Windows).
It makes running child processes simple.

As Windows pipes are file handles and do not allow non-blocking access, this package makes use of a [process wrapper](https://github.com/amphp/windows-process-wrapper), that provides access to these pipes via sockets.
On Unix-like systems it uses the standard pipes, as these can be accessed without blocking there.
Concurrency is managed by the [Revolt](https://revolt.run/) event loop.

[![Latest Release](https://img.shields.io/github/release/amphp/process.svg?style=flat-square)](https://github.com/amphp/process/releases)
[![MIT License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](https://github.com/amphp/process/blob/master/LICENSE)

## Installation

This package can be installed as a [Composer](https://getcomposer.org/) dependency.

```
composer require amphp/process
```

The package requires PHP 8.1 or later.

## Usage

Processes are started with `Process::start()`:

```php
<?php

require __DIR__ . "/vendor/autoload.php";

use Amp\ByteStream;
use Amp\Process\Process;

// "echo" is a shell internal command on Windows and doesn't work.
$command = DIRECTORY_SEPARATOR === "\\" ? "cmd /c echo Hello World!" : "echo 'Hello, world!'";

$process = Process::start($command);

Amp\async(fn () => Amp\ByteStream\pipe($process->getStdout(), ByteStream\getStdout()));
Amp\async(fn () => Amp\ByteStream\pipe($process->getStderr(), ByteStream\getStderr()));

$exitCode = $process->join();

echo "Process exited with {$exitCode}.\n";
```

### Custom Working Directory

Processes are started with the working directory of the current process by default.
The working directory can be customized to another directory if needed:

```php
$process = Amp\Process\Process::start($command, workingDirectory: '/path/of/your/dreams');
```

### Custom Environment Variables

Processes are started with the environment variables of the current process by default.
The environment can be customized by passing an associative array mapping variables names to their values:

```php
$process = Amp\Process\Process::start($command, environment: [
    'PATH' => '/usr/bin/local'
]);
```

## Versioning

`amphp/process` follows the [semver](http://semver.org/) semantic versioning specification like all other `amphp` packages.

## Security

If you discover any security related issues, please use the private security issue reporter instead of using the public issue tracker.

## License

The MIT License (MIT). Please see [`LICENSE`](./LICENSE) for more information.
