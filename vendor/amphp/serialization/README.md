# amphp/serialization

AMPHP is a collection of event-driven libraries for PHP designed with fibers and concurrency in mind.
`amphp/serialization` is a library providing serialization tools for IPC and data storage in PHP.

[![Latest Release](https://img.shields.io/github/release/amphp/serialization.svg?style=flat-square)](https://github.com/amphp/serialization/releases)
[![MIT License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](https://github.com/amphp/serialization/blob/master/LICENSE)

## Installation

This package can be installed as a [Composer](https://getcomposer.org/) dependency.

```bash
composer require amphp/serialization
```

## Serializer

The main interface of this library is `Amp\Serialization\Serializer`.

```php
<?php

namespace Amp\Serialization;

interface Serializer
{
    /**
     * @param mixed $data
     *
     * @throws SerializationException
     */
    public function serialize($data): string;

    /**
     * @return mixed
     *
     * @throws SerializationException
     */
    public function unserialize(string $data);
}
```

### JSON

JSON serialization can be used with the `JsonSerializer`.

### Native Serialization

Native serialization (`serialize` / `unserialize`) can be used with the `NativeSerializer`.

### Passthrough Serialization

Sometimes you already have a `string` and don't want to apply additional serialization. In these cases, you can use the `PassthroughSerializer`.

### Compression

Often, serialized data can be compressed quite well. If you don't need interoperability with other systems deserializing the data, you can compress your serialized payloads by wrapping your `Serializer` instance in an `CompressingSerializer`.

## Security

If you discover any security related issues, please email [`me@kelunik.com`](mailto:me@kelunik.com) instead of using the issue tracker.

## License

The MIT License (MIT). Please see [`LICENSE`](./LICENSE) for more information.
