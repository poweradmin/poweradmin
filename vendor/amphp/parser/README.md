# amphp/parser

AMPHP is a collection of event-driven libraries for PHP designed with fibers and concurrency in mind.
`amphp/parser` allows easily building streaming generator parsers.

## Installation

This package can be installed as a [Composer](https://getcomposer.org/) dependency.

```bash
composer require amphp/parser
```

## Requirements

- PHP 7.4+

## Usage

PHP's generators are a great way for building incremental parsers.

## Example

This simple parser parses a line delimited protocol and prints a message for each line. Instead of printing a message, you could also invoke a data callback.

```php
$parser = new Parser((function () {
    while (true) {
        $line = yield "\r\n";

        if (trim($line) === "") {
            continue;
        }

        print "New item: {$line}" . PHP_EOL;
    }
})());

for ($i = 0; $i < 100; $i++) {
    $parser->push("bar\r");
    $parser->push("\nfoo");
}
```

Furthere examples can be found in other AMPHP packages which this library to build streaming parsers.
- [`ChannelParser`](https://github.com/amphp/byte-stream/blob/5c7eb399b746a582e9598935b26483b214250c34/src/Internal/ChannelParser.php#L28) in [`amphp/byte-stream`](https://github.com/amphp/byte-stream)
- [`RespParser`](https://github.com/amphp/redis/blob/649cff6d5e6b4c579dcab1a20511a437cbe3d62a/src/Connection/RespParser.php#L31) in [`amphp/redis`](https://github.com/amphp/redis)

## Yield Behavior

You can either `yield` a `string` that's used as delimiter, an `integer` that's used as length, or `null` to flush any remaining buffer in the parser (if any) or await the next call to `Parser::push()`.

## Versioning

`amphp/parser` follows the [semver](http://semver.org/) semantic versioning specification like all other `amphp` packages.

## Security

If you discover any security related issues, please email [`me@kelunik.com`](mailto:me@kelunik.com) instead of using the issue tracker.

## License

The MIT License (MIT). Please see [`LICENSE`](./LICENSE) for more information.
