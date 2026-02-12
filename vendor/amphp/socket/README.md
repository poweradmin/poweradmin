# amphp/socket

AMPHP is a collection of event-driven libraries for PHP designed with fibers and concurrency in mind.
`amphp/socket` is a library for establishing and encrypting non-blocking sockets.
It provides a socket abstraction for clients and servers.
It abstracts the really low levels of non-blocking streams in PHP.

[![Latest Release](https://img.shields.io/github/release/amphp/socket.svg?style=flat-square)](https://github.com/amphp/socket/releases)
[![MIT License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](https://github.com/amphp/socket/blob/master/LICENSE)

## Installation

This package can be installed as a [Composer](https://getcomposer.org/) dependency.

```bash
composer require amphp/socket
```

## Requirements

`amphp/socket` heavily relies on `amphp/byte-stream`, specifically its `ReadableStream` and `WritableStream` interfaces.

## Connecting to a Server

`amphp/socket` allows clients to connect to servers via TCP, UDP, or Unix domain sockets.
You can establish a socket connection using `Amp\Socket\connect()`.
It will automatically resolve DNS names and retries other IPs if a connection fails and multiple IPs are available.

```php
// You can customize connect() options using ConnectContext
$connectContext = (new Amp\Socket\ConnectContext)
        ->withConnectTimeout(5);

// You can optionally pass a Cancellation object to cancel a pending connect() operation
$deferredCancellation = new Amp\DeferredCancellation();

$socket = connect('amphp.org:80', $connectContext, $deferredCancellation->getCancellation());
```

### Encrypted Connections / TLS

If you want to connect via TLS, use `Amp\Socket\connectTls()` instead or call `$socket->setupTls()` on the returned socket.

### Handling Connections

`Socket` implements `ReadableStream` and `WritableStream`, so everything from [`amphp/byte-stream`](https://v3.amphp.org/byte-stream) applies for receiving and sending data.

```php
#!/usr/bin/env php
<?php // basic (and dumb) HTTP client

require __DIR__ . '/../vendor/autoload.php';

// This is a very simple HTTP client that just prints the response without parsing.
// league/uri required for this example.

use Amp\ByteStream;
use Amp\Socket\ClientTlsContext;
use Amp\Socket\ConnectContext;
use League\Uri\Http;
use function Amp\Socket\connect;
use function Amp\Socket\connectTls;

$stdout = ByteStream\getStdout();

if (\count($argv) !== 2) {
    $stdout->write('Usage: examples/simple-http-client.php <url>' . PHP_EOL);
    exit(1);
}

$uri = Http::createFromString($argv[1]);
$host = $uri->getHost();
$port = $uri->getPort() ?? ($uri->getScheme() === 'https' ? 443 : 80);
$path = $uri->getPath() ?: '/';

$connectContext = (new ConnectContext)
        ->withTlsContext(new ClientTlsContext($host));

$socket = $uri->getScheme() === 'http'
        ? connect($host . ':' . $port, $connectContext)
        : connectTls($host . ':' . $port, $connectContext);

$socket->write("GET {$path} HTTP/1.1\r\nHost: $host\r\nConnection: close\r\n\r\n");

ByteStream\pipe($socket, $stdout);
```

## Server

`amphp/socket` allows listening for incoming TCP connections as well as connections via Unix domain sockets.
It defaults to secure TLS settings if you decide to enable TLS.

### Listening and Accepting Connections

Use `Amp\Socket\Socket\listen()` to listen on a port or unix domain socket.
It's a wrapper around `stream_socket_server` that gives useful error message on failures via exceptions.

Once you're listening, accept clients using `Server::accept()`.
It returns a `Socket` that returns once a new client has been accepted.
It's usually called within a `while` loop:

```php
$server = Socket\listen("tcp://127.0.0.1:1337");

while ($client = $server->accept()) {
    // You shouldn't spend too much time here, because that blocks accepting another client, so we use async():
    async(function () use ($client) {
        // Handle client connection here
    });
}
```

### Handling Connections

`Socket` implements `ReadableStream` and `WritableStream`, so everything from [`amphp/byte-stream`](https://v3.amphp.org/byte-stream) applies for receiving and sending data.
It's best to handle clients in their own coroutine, while letting the server accept all clients as soon as there are new clients.

```php
#!/usr/bin/env php
<?php // basic (and dumb) HTTP server

require __DIR__ . '/../vendor/autoload.php';

// This is a very simple HTTP server that just prints a message to each client that connects.
// It doesn't check whether the client sent an HTTP request.

// You might notice that your browser opens several connections instead of just one,
// even when only making one request.

use Amp\Socket;
use function Amp\async;

$server = Socket\listen('127.0.0.1:0');

echo 'Listening for new connections on ' . $server->getAddress() . ' ...' . PHP_EOL;
echo 'Open your browser and visit http://' . $server->getAddress() . '/' . PHP_EOL;

while ($socket = $server->accept()) {
    async(function () use ($socket) {
        $address = $socket->getRemoteAddress();
        $ip = $address->getHost();
        $port = $address->getPort();

        echo "Accepted connection from {$address}." . PHP_EOL;

        $body = "Hey, your IP is {$ip} and your local port used is {$port}.";
        $bodyLength = \strlen($body);

        $socket->write("HTTP/1.1 200 OK\r\nConnection: close\r\nContent-Length: {$bodyLength}\r\n\r\n{$body}");
        $socket->end();
    });
}
```

### Closing Connections

Once you're done with a client, close the connection using `Socket::close()`.
If you want to wait for all data to be successfully written before closing the connection, use `Socket::end()`.
See above for an example.

### Server Address

Sometimes you don't know the address the server is listening on, e.g. because you listed to `tcp://127.0.0.1:0`, which assigns a random free port. You can use `Server::getAddress()` to get the address the server is bound to.

### Server Shutdown

Once you're done with the server socket, close the socket.
That means, the server won't listen on the specified location anymore.
Use `Server::close()` to close the server socket.

### Encrypted Connections / TLS

As already mentioned in the documentation for `Amp\Socket\Socket\listen()`, you need to enable TLS manually after accepting connections.
For a TLS server socket, you listen on the `tcp://` protocol on a specified address.
After accepting clients, call `$socket->setupTls()` where `$socket` is the socket returned from `SocketServer::accept()`.

> **Warning**
> Any data transmitted before `Socket::setupTls()` completes will be transmitted in clear text.
> Don't attempt to read from the socket or write to it manually.
> Doing so will read the raw TLS handshake data that's supposed to be read by OpenSSL.

#### Self-Signed Certificates

There's no option to allow self-signed certificates in `ClientTlsContext` since it is no more secure than disabling peer verification.
To safely use a self-signed certificate, disable peer verification and require fingerprint verification of the certificate using `ClientTlsContext::withPeerFingerprint()`.

## Security

If you discover any security related issues, please email [`me@kelunik.com`](mailto:me@kelunik.com) instead of using the issue tracker.

## License

The MIT License (MIT). Please see [`LICENSE`](./LICENSE) for more information.
