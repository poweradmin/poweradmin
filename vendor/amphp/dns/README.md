# amphp/dns

AMPHP is a collection of event-driven libraries for PHP designed with fibers and concurrency in mind.
`amphp/dns` provides hostname to IP address resolution and querying specific DNS records.

[![Latest Release](https://img.shields.io/github/release/amphp/dns.svg?style=flat-square)](https://github.com/amphp/dns/releases)
[![MIT License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](https://github.com/amphp/dns/blob/master/LICENSE)

## Installation

This package can be installed as a [Composer](https://getcomposer.org/) dependency.

```bash
composer require amphp/dns
```

## Usage

### Configuration

`amphp/dns` automatically detects the system configuration and uses it. On Unix-like systems it reads `/etc/resolv.conf` and respects settings for nameservers, timeouts, and attempts. On Windows it looks up the correct entries in the Windows Registry and takes the listed nameservers. You can pass a custom `ConfigLoader` instance to `Rfc1035StubResolver` to load another configuration, such as a static config.

It respects the system's hosts file on Unix and Windows based systems, so it works just fine in environments like Docker with named containers.

The package uses a global default resolver which can be accessed and changed via `Amp\Dns\resolver()`. If an argument other than `null` is given, the resolver is used as global instance.

Usually you don't have to change the resolver. If you want to use a custom configuration for a certain request, you can create a new resolver instance and use that instead of changing the global one.

### Hostname to IP Resolution

`Amp\Dns\resolve` provides hostname to IP address resolution. It returns an array of IPv4 and IPv6 addresses by default. The type of IP addresses returned can be restricted by passing a second argument with the respective type.

```php
// Example without type restriction. Will return IPv4 and / or IPv6 addresses.
// What's returned depends on what's available for the given hostname.

/** @var Amp\Dns\DnsRecord[] $records */
$records = Amp\Dns\resolve("github.com");
```

```php
// Example with type restriction. Will throw an exception if there are no A records.

/** @var Amp\Dns\DnsRecord[] $records */
$records = Amp\Dns\resolve("github.com", Amp\Dns\DnsRecord::A);
```

### Custom Queries

`Amp\Dns\query` supports the various other DNS record types such as `MX`, `PTR`, or `TXT`. It automatically rewrites passed IP addresses for `PTR` lookups.

```php
/** @var Amp\Dns\DnsRecord[] $records */
$records = Amp\Dns\query("google.com", Amp\Dns\DnsRecord::MX);
```

```php
/** @var Amp\Dns\DnsRecord[] $records */
$records = Amp\Dns\query("8.8.8.8", Amp\Dns\DnsRecord::PTR);
```

### Caching

The `Rfc1035StubResolver` caches responses by default in an `Amp\Cache\LocalCache`. You can set any other `Amp\Cache\Cache` implementation by creating a custom instance of `Rfc1035StubResolver` and setting that via `Amp\Dns\resolver()`, but it's usually unnecessary. If you have a lot of very short running scripts, you might want to consider using a local DNS resolver with a cache instead of setting a custom cache implementation, such as `dnsmasq`.

### Reloading Configuration

The `Rfc1035StubResolver` (which is the default resolver shipping with that package) will cache the configuration of `/etc/resolv.conf` / the Windows Registry and the read host files by default. If you wish to reload them, you can set a periodic timer that requests a background reload of the configuration.

```php
EventLoop::repeat(600, function () use ($resolver) {
    Amp\Dns\dnsResolver()->reloadConfig();
});
```

> **Note**
> The above code relies on the resolver not being changed. `reloadConfig` is specific to `Rfc1035StubResolver` and is not part of the `Resolver` interface.

## Example

```php
<?php

require __DIR__ . '/examples/_bootstrap.php';

$githubIpv4 = Amp\Dns\resolve("github.com", Dns\Record::A);
pretty_print_records("github.com", $githubIpv4);

$firstGoogleResult = Amp\Future\awaitFirst([
  Amp\async(fn() => Amp\Dns\resolve("google.com", Dns\Record::A)),
  Amp\async(fn() => Amp\Dns\resolve("google.com", Dns\Record::AAAA)),
]);

pretty_print_records("google.com", $firstGoogleResult);

$combinedGoogleResult = Amp\Dns\resolve("google.com");
pretty_print_records("google.com", $combinedGoogleResult);

$googleMx = Amp\Dns\query("google.com", Amp\Dns\DnsRecord::MX);
pretty_print_records("google.com", $googleMx);
```
