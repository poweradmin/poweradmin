## Custom rules

There are several different types (and configuration keys) that can be disallowed:

1. `disallowedMethodCalls` - for detecting `$object->method()` calls
2. `disallowedStaticCalls` - for static calls `Class::method()`
3. `disallowedFunctionCalls` - for functions like `function()`
4. `disallowedConstants` - for constants like `DATE_ISO8601` or `DateTime::ISO8601` (which needs to be split to `class: DateTime` & `constant: ISO8601` in the configuration, see notes below)
5. `disallowedNamespaces` or `disallowedClasses` - for usages of classes or classes from a namespace
6. `disallowedSuperglobals` - for usages of superglobal variables like `$GLOBALS` or `$_POST`
7. `disallowedAttributes` - for attributes like `#[Entity(class: Foo::class, something: true)]`
8. `disallowedEnums` - for enums, both pure & backed, like `Suit::Hearts` (like class constants, enums need to be split to `enum: Suit` & `case: Hearts` in the configuration, see notes below)
9. `disallowedControlStructures` - for control structures like `if`, `else`, `elseif`, loops, `require` & `include`, and `goto`
10. `disallowedKeywords` - for selected keywords (currently supported: `global`)
11. `disallowedProperties` - for both instance and static properties (for example `$object->property`, `Class::$property`), and enum properties (e.g. `Suit::Clubs->value`)

Use them to add rules to your `phpstan.neon` config file. I like to use a separate file (`disallowed-calls.neon`) for these which I'll include later on in the main `phpstan.neon` config file. Here's an example, update to your needs:

```neon
parameters:
    disallowedMethodCalls:
        -
            method: 'PotentiallyDangerous\Logger::log()'  # `function` is an alias of `method`
            message: 'use our own logger instead'
            errorTip: 'see https://our-docs.example/logging on how logging should be used'
        -
            method: 'Redis::connect()'
            message: 'use our own Redis instead'
            errorIdentifier: 'redis.connect'

    disallowedStaticCalls:
        -
            method: 'PotentiallyDangerous\Debugger::log()'
            message: 'use our own logger instead'

    disallowedFunctionCalls:
        -
            function: 'var_dump()'  # `method` is an alias of `function`
            message: 'use logger instead'
        -
            function: 'print_r()'
            message: 'use logger instead'

    disallowedConstants:
        -
            constant: 'DATE_ISO8601'
            message: 'use DATE_ATOM instead'
        -
            class: 'DateTimeInterface'
            constant: 'ISO8601'
            message: 'use DateTimeInterface::ATOM instead'

    disallowedNamespaces:  # `disallowedClasses` is an alias of `disallowedNamespaces`
        -
            class: 'Symfony\Component\HttpFoundation\RequestStack'  # `class` is an alias of `namespace`
            message: 'pass Request via controller instead'
            allowIn:
                - tests/*
        -
            namespace: 'Assert\*'  # `namespace` is an alias of `class`
            message: 'use Webmozart\Assert instead'

    disallowedSuperglobals:
        -
            superglobal:
                - '$_GET'
                - '$_POST'
                - '$_REQUEST'
                - '$_COOKIE'
            message: 'use the respective methods of the Request object as provided by our framework'

    disallowedAttributes:
        -
            attribute: Entity
            message: 'use our own custom Entity instead'

    disallowedEnums:
        -
            enum: 'Suit'
            case: 'Hearts'
            message: 'use Diamonds instead'

    disallowedProperties:
        -
            property: 'Class::$value'
            message: 'call getValue() instead'
            allowIn:
                - tests/*
```

The `message` key is optional. Functions and methods can be specified with or without `()`. Omitting `()` is not recommended though to avoid confusing method calls with class constants.

### Disallowing multiple items

If you want to disallow multiple calls, constants, class constants (same-class only), enum cases (same-enum only), classes, namespaces or variables that share the same `message` and other config keys, you can use a list or an array to specify them all:
```neon
parameters:
    disallowedFunctionCalls:
        -
            function:
                - 'var_dump()'
                - 'print_r()'
            message: 'use logger instead'

    disallowedConstants:
        -
            class: 'DateTimeInterface'
            constant: ['ISO8601', 'RFC3339', 'W3C']
            message: 'use DateTimeInterface::ATOM instead'
```

### Error tips

The optional `errorTip` key can be used to show an additional message prefixed with 💡 that's rendered below the error message in the analysis result.

You can add multiple tips using an array:
```neon
parameters:
    disallowedMethodCalls:
        -
            method: ...
            message: ...
            errorTip:
                - 'See docs'
                - 'Call a friend'
```
Note that in such a case PHPStan will automatically prefix the messages with an extra `•` character.

### Error identifiers

The `errorIdentifier` key is optional. It can be used to provide a unique identifier to the PHPStan error.
If not specified, a default identifier will be used, see the `\Spaze\PHPStan\Rules\Disallowed\RuleErrors\ErrorIdentifiers` class for current list.

### Wildcards

Use wildcard (`*`) to ignore all functions, methods, classes, namespaces starting with a prefix, for example:
```neon
parameters:
    disallowedFunctionCalls:
        -
            function: 'pcntl_*()'
```
The wildcard makes most sense when used as the rightmost character of the function or method name, optionally followed by `()`, but you can use it anywhere for example to disallow all functions that end with `y`: `function: '*y()'`. The matching is powered by [`fnmatch`](https://www.php.net/function.fnmatch) so you can use even multiple wildcards if you wish because w\*y n\*t.

### Wildcards, but exclude this one function

If there's this one function, method, namespace, attribute (or multiple of them) that you'd like to exclude from the set, you can do that with `exclude`:
```neon
parameters:
    disallowedFunctionCalls:
        -
            function: 'pcntl_*()'
            exclude:
                - 'pcntl_foobar()'
```
This config would disallow all `pcntl` functions except (an imaginary) `pcntl_foobar()`.
Please note `exclude` also accepts [`fnmatch`](https://www.php.net/function.fnmatch) patterns so please be careful to not create a contradicting config, and that it can accept both a string and an array of strings.

### Wildcards, except when having an attribute

If there's this one class (or multiple of them) that you'd like to exclude from the set, you can do that with `excludeWithAttribute`:

```neon
parameters:
    disallowedClasses:
        -
            class: 'App\PrivateModule\*'
            excludeWithAttribute:
                - '\App\Support\IsPublic'
```

This config would disallow all `App\PrivateModule\*` classes except those classes marked with a `#[\App\Support\IsPublic] attribute`.

Please note `excludeWithAttribute` also accepts [`fnmatch`](https://www.php.net/function.fnmatch) patterns, and that it can accept both a string and an array of strings.

### Wildcards, except when defined in this path

Another option how to limit the set of functions or methods selected by the `function` or `method` directive is a file path in which these are defined which mostly makes sense when a [`fnmatch`](https://www.php.net/function.fnmatch) pattern is used in those directives.
Imagine a use case in which you want to disallow any function or method defined in any namespace, or none at all, by this legacy package:
```neon
parameters:
    disallowedFunctionCalls:
        -
            function: '*'
            definedIn:
                - 'vendor/foo/bar'
    disallowedMethodCalls:
        -
            method: '*'
            definedIn:
                - 'vendor/foo/bar'
    filesRootDir: %rootDir%/../../..
```

### Resolving relative paths

Relative paths in `definedIn` are resolved based on the current working directory. When running PHPStan from a directory or subdirectory which is not your "root" directory, the paths will probably not work.
Use `filesRootDir` in that case to specify an absolute root directory, you can use [`%rootDir%`](https://phpstan.org/config-reference#expanding-paths) to start with PHPStan's root directory (usually `/something/something/vendor/phpstan/phpstan`) and then `..` from there to your "root" directory.
`filesRootDir` is also used to configure all `allowIn` directives, see below.

The extension supports multiple directives you can use to re-allow previously disallowed items. The item is allowed as soon as one of the allow directives in a config entry matches (a match can be further narrowed by [parameter conditions](allow-with-parameters.md)).
The `allowExceptIn*` directives (also known as `disallowIn*`) decide the result on their own despite the name - a match reports the item, no match allows it - and so do the `allowExceptParams`-family [parameter conditions](allow-with-parameters.md) without the `InAllowed` suffix.
A config entry also replaces any previous entry for the same item (for calls, the `allowExceptParams` values have to match, too), which is how the [bundled config](configuration-bundled.md) entries can be customized.

Many directives have aliases (`disallowInMethods` is `allowExceptInMethods`, `disallowParamsAnywhere` is `allowExceptParams` etc.) which all refer to one single directive, so when a config entry sets multiple aliases of the same directive, only one of them is used.

### Language constructs and constructors

You can treat some language constructs as functions and disallow it in `disallowedFunctionCalls`. Currently detected language constructs are:
- `die()`
- `echo()`
- `empty()`
- `eval()`
- `exit()`
- `isset()`
- `print()`
- `unset()`

To disallow naive object creation (`new ClassName()` or `new $classname`), disallow `NameSpace\ClassName::__construct` in `disallowedMethodCalls`. Works even when there's no constructor defined in that class.

You can also disallow control structures, see below.

### Constants

When [disallowing constants](disallowing-constants.md) please be aware of limitations and special requirements, see [docs](disallowing-constants.md).

### Enums

Similar to disallowing constants, enums have some limitations, see [docs](disallowing-enums.md).

### Control structures

You can forbid [control structures](https://www.php.net/language.control-structures) like `if`, `else`, `elseif` (don't write it as `else if` because `else if` is parsed as `else` followed by `if` producing unexpected results), loops, `break`, `continue`, `goto`, `require`, `include` (and the `_once` variants).

Currently, parameters are not checked, so it's not possible to disallow for example `declare`, but re-allow `declare(strict-types = 1)`.

You can use `controlStructure` or `structure` directives, and both can be specified as arrays:
```neon
parameters:
    disallowedControlStructures:
        -
            controlStructure:
                - 'elseif'
                - 'return'
            allowIn:
                - 'tests/foo/bar.php'
        -
            structure: 'goto'
            message: "restructure the program's flow"
            errorTip: 'https://xkcd.com/292/'
            allowIn:
                - 'tests/waldo.php'
```

### Keywords

In addition to control structures, you can forbid other selected PHP keywords using `disallowedKeywords`:
```neon
parameters:
    disallowedKeywords:
        -
            keyword: 'global'
            allowIn:
                - 'legacy/*'
```

The list of implemented keyword detections is currently very short:
- `global`
