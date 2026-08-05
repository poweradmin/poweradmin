# Disallowed calls for PHPStan
[PHPStan](https://github.com/phpstan/phpstan) rules to detect disallowed calls and more, without running the code.

[![PHP Tests](https://github.com/spaze/phpstan-disallowed-calls/workflows/PHP%20Tests/badge.svg)](https://github.com/spaze/phpstan-disallowed-calls/actions?query=workflow%3A%22PHP+Tests%22)

There are some functions, methods, constants, namespaces, attributes, variables and properties which should not be used in production code. One good example is the `var_dump()` function,
it is often used to quickly debug problems but should be removed before committing the code. And sometimes it's not.

Another example would be a generic logger. Let's say you're using one of the generic logging libraries but you have your own logger
that will add some more info, or sanitize data, before calling the generic logger. Your code should not call the generic logger directly
but should instead use your custom logger.

This [PHPStan](https://github.com/phpstan/phpstan) extension will detect such usage, if configured. It should be noted that this extension
is not a way to defend against or detect hostile developers, as they can obfuscate the calls for example. This extension is meant to be
another pair of eyes, detecting your own mistakes, it doesn't aim to detect-all-the-things.

[Tests](tests) will provide examples what is ***currently*** detected. If it's not covered by tests, it might be, but most probably will not be detected.
`*Test.php` files are the tests, start with those, the analyzed test code is in [src](tests/src), required test classes in [libs](tests/libs).

Feel free to file [issues](https://github.com/spaze/phpstan-disallowed-calls/issues) or create [pull requests](https://github.com/spaze/phpstan-disallowed-calls/pulls) if you need to detect more calls.

## Installation

Install the extension using [Composer](https://getcomposer.org/):
```
composer require --dev spaze/phpstan-disallowed-calls
```

[PHPStan](https://github.com/phpstan/phpstan), the PHP Static Analysis Tool, is a requirement.

If you use [phpstan/extension-installer](https://github.com/phpstan/extension-installer), you are all set and can skip to configuration.

For manual installation, add this to your `phpstan.neon`:

```neon
includes:
    - vendor/spaze/phpstan-disallowed-calls/extension.neon
```

## Configuration files

You can start with [bundled configuration files](docs/configuration-bundled.md).

## Custom rules

The extension supports versatile [custom rules](docs/custom-rules.md), too.

### Allow some previously disallowed calls or usages

Let's say you have disallowed the `foo()` function (or any other supported items like constants or method calls etc.) with custom rules. But you want to re-allow it when used in your custom wrapper, or when the first parameter equals, or not, a specified value. The extension offers multiple ways of doing that:

- [Ignore errors](docs/allow-ignore-errors.md) the PHPStan way
- [Allow in paths](docs/allow-in-paths.md)
- [Allow in methods or functions](docs/allow-in-methods.md)
- [Allow with specified parameters](docs/allow-with-parameters.md)
- [Allow with specified flags](docs/allow-with-flags.md)
- [Allow in classes, child classes, classes implementing an interface](docs/allow-in-instance-of.md) (same as the `instanceof` operator)
- [Allow in class with given attributes](docs/allow-in-class-with-attributes.md)
- [Allow in methods or functions with given attributes](docs/allow-in-methods-with-attributes.md)
- [Allow in class with given attributes on any method](docs/allow-in-class-with-method-attributes.md)
- [Allow in type hint positions](docs/allow-in-type-hint-positions.md) (param types, return types - `disallowedNamespaces` and `disallowedClasses` only)

These directives can be combined in one configuration entry and are evaluated one after another - the item is allowed as soon as one of the allow directives matches (a match can be further narrowed by [parameter conditions](docs/allow-with-parameters.md)).
The `allowExceptIn*` directives (also known as `disallowIn*`) are not "allow" directives despite the name - when present, the item is reported when a pattern matches and allowed when it doesn't, and directives evaluated after them are not consulted.
The `allowExceptParams`-family [parameter conditions](docs/allow-with-parameters.md) (those without the `InAllowed` suffix) behave the same way.

A config entry also replaces any previous entry for the same disallowed item (for calls, the `allowExceptParams` values have to match, too), which is how entries from the [bundled configurations](docs/configuration-bundled.md) can be customized.

[Re-allowing attributes](docs/allow-attributes.md) uses a similar [configuration](docs/allow-attributes.md).


## Disallow disabled functions & classes

Use the [provided generator](docs/disallow-disabled-functions-classes.md) to generate a configuration snippet from PHP's `disable_functions` & `disable_classes` configuration directives.

## Example output

```
 ------ --------------------------------------------------------
  Line   libraries/Report/Processor/CertificateTransparency.php
 ------ --------------------------------------------------------
  116    Calling var_dump() is forbidden, use logger instead
 ------ --------------------------------------------------------
```

## Case-(in)sensitivity

Function names, method names, class names, namespaces are matched irrespective of their case (disallowing `print_r` will also find `print_R` calls), while anything else like constants, file names, paths are not.

## No other rules

You can also use this extension [without any other PHPStan rules](docs/phpstan-custom-ruleset.md). This may be useful if you want to for example check a third-party code for some calls or usage of something.

## Running tests

If you want to contribute (awesome, thanks!), you should add/run tests for your contributions.
First install dev dependencies by running `composer install`, then run PHPUnit tests with `composer test`, see `scripts` in `composer.json`. Tests are also run on GitHub with Actions on each push.

You can fix coding style issues automatically by running `composer cs-fix`.

## See also
There's a similar project with a slightly different configuration, created almost at the same time (just a few days difference): [PHPStan Banned Code](https://github.com/ekino/phpstan-banned-code).

## Framework or package-specific configurations
- For [Nette Framework](https://github.com/spaze/phpstan-disallowed-calls-nette)
- For [Symfony](https://github.com/spaze/phpstan-disallowed-calls-symfony)
