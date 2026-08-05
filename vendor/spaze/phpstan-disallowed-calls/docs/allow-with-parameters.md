## Allow with specified parameters only

You can also narrow down the allowed items when called with some parameters (applies only to disallowed method, static & function calls, for obvious reasons).
Only scalar values and no arrays are supported with the `value` configuration directive, but with the `typeString` directive,
arrays and unions are also supported, and generally anything you can express with a PHPDoc type string, if it makes sense.
When `typeString` is specified, `value` directive is ignored for the given parameter.

For example, you want to disallow calling `print_r()` but want to allow `print_r(..., true)`.
This can be done with optional `allowParamsInAllowed` or `allowParamsAnywhere` configuration keys:

```neon
parameters:
    disallowedMethodCalls:
        -
            method: 'PotentiallyDangerous\Logger::log()'
            message: 'use our own logger instead'
            allowIn:
                - path/to/some/file-*.php
                - tests/*.test.php
            allowParamsInAllowed:
                -
                    position: 1
                    name: 'message'
                    value: 'foo'
                -
                    position: 2
                    name: 'alert'
                    value: true
            allowParamsAnywhere:
                -
                    position: 2
                    name: 'alert'
                    value: true
```

When using `allowParamsInAllowed`, the parameter condition is applied to the location matched by the accompanying allow directive — `allowIn`, `allowExceptIn`, `allowInInstanceOf`, `allowExceptInInstanceOf`, `allowInMethods`/`allowInFunctions`, or `allowExceptInMethods`/`allowExceptInFunctions`. For `allowIn`-style directives the call must be in a matching location *and* have the right params to be allowed. For `allowExceptIn`-style directives the call is normally disallowed in the matched location, but the right params can allow it there.

For example, to allow `redirect()` everywhere except in `handleRequest()`, where it remains disallowed unless the first parameter is of type `App\SafeUrl`:

```neon
parameters:
    disallowedMethodCalls:
        -
            method: 'Controller::redirect()'
            message: 'use a safe redirect instead'
            allowExceptInMethods:
                - Controller::handleRequest()
            allowParamsInAllowed:
                -
                    position: 1
                    name: 'url'
                    typeString: App\SafeUrl
```

With `allowParamsAnywhere`, calls are allowed when called with all parameters listed no matter in which file. In the example above, the `log()` method will be disallowed unless called as:
- `log(..., true)` (or `log(..., alert: true)`) anywhere
- `log('foo', true)` (or `log(message: 'foo', alert: true)`) in `another/file.php` or `optional/path/to/log.tests.php`

Use `allowParamsInAllowedAnyValue` and `allowParamsAnywhereAnyValue` if you don't care about the parameter's value but want to make sure the parameter is passed.
Following the previous example:

```neon
parameters:
    disallowedMethodCalls:
        -
            method: 'PotentiallyDangerous\Logger::log()'
            message: 'use our own logger instead'
            allowIn:
                - path/to/some/file-*.php
                - tests/*.test.php
            allowParamsInAllowedAnyValue:
                -
                    position: 2
                    name: 'alert'
            allowParamsAnywhereAnyValue:
                -
                    position: 1
                    name: 'message'
```
means that you should use (`...` means any value):
- `log(...)` (or `log(message: ...)`) anywhere
- `log(..., ...)` (or `log(message: ..., alert: ...)`) in `another/file.php` or `optional/path/to/log.tests.php`

Such configuration only makes sense when both the parameters of `log()` are optional. If they are required, omitting them would result in an error already detected by PHPStan itself.

### Allow calls except when a param has a specified value

Sometimes, it's handy to disallow a function or a method call only when a parameter matches a configured value but allow it otherwise. _Please note that currently only scalar values are supported, not arrays._

For example the `hash()` function, it's fine using it with algorithm families like SHA-2 & SHA-3 (not for passwords though) but you'd like PHPStan to report when it's used with MD5 like `hash('md5', ...)`.
You can use `allowExceptParamsAnywhere` (or `disallowParamsAnywhere`), `allowExceptCaseInsensitiveParams` (or `disallowCaseInsensitiveParams`), `allowExceptParamsInAllowed` (or `disallowParamsInAllowed`) config options to disallow only some calls:

```neon
parameters:
    disallowedFunctionCalls:
        -
            function: 'hash()'
            allowExceptCaseInsensitiveParams:
                -
                    position: 1
                    name: 'algo'
                    value: 'md5'
```

This will disallow `hash()` call where the first parameter (or the named parameter `algo`) is `'md5'`. `allowExceptCaseInsensitiveParams` is used because the first parameter of `hash()` is case-insensitive (so you can also use `'MD5'`, or even `'Md5'` & `'mD5'` if you wish).
To disallow only exact matches, use `allowExceptParamsAnywhere` (or `disallowParamsAnywhere`):

```neon
parameters:
    disallowedFunctionCalls:
        -
            function: 'foo()'
            allowExceptParamsAnywhere:
                -
                    position: 2
                    value: 'baz'
```
will disallow `foo('bar', 'baz')` but not `foo('bar', 'BAZ')`.

The older `allowExceptParams` (or `disallowParams`) config option is still supported but new configs should use `allowExceptParamsAnywhere` (or `disallowParamsAnywhere`) as they are more self-describing.

If you don't care about the value but would like to disallow a call based just on the parameter presence, you can use `allowExceptParamsAnyValue` (or `disallowParamsAnyValue`):
```neon
parameters:
    disallowedFunctionCalls:
        -
            function: 'waldo()'
            disallowParamsAnyValue:
                -
                    position: 1
                    name: 'message'
                -
                    position: 2
                    name: 'alert'
```
This configuration will disallow calls like `waldo('foo', 'bar')` or `waldo('*', '*')`, but `waldo('foo')` or `waldo()` will be still allowed.

It's also possible to disallow functions and methods previously allowed by path (using `allowIn`), by function/method name (`allowInMethods`), or by class hierarchy (`allowInInstanceOf`) when they're called with specified parameters, and allow when called with any other parameter. The same applies in reverse for the `allowExceptIn`-style directives: a call that would otherwise be disallowed in the matched location is allowed unless the parameters match. This is done using the `allowExceptParamsInAllowed` config option.

Take this example configuration:

```neon
parameters:
    disallowedFunctionCalls:
        -
            function: 'waldo()'
            allowIn:
                - 'views/*'
            allowExceptParamsInAllowed:
                -
                    position: 2
                    value: 'quux'
```

Calling `waldo()` is disallowed, and allowed back again only when the file is in the `views/` subdirectory **and** `waldo()` is called in the file with a 2nd parameter **not** being the string `quux`.

As already demonstrated above, named parameters are also supported:

```neon
parameters:
    disallowedFunctionCalls:
        -
            function: 'json_decode()'
            message: 'set the $flags parameter to `JSON_THROW_ON_ERROR` to throw a JsonException'
            allowParamsAnywhere:
                -
                    position: 4
                    name: 'flags'
                    value: 4194304 # JSON_THROW_ON_ERROR; using ::constant(JSON_THROW_ON_ERROR) is discouraged as it may break when PHPStan updates nette/di
```

This format allows to detect the value in both cases whether it's used with a traditional positional parameter (e.g. `json_decode($foo, null, 512, JSON_THROW_ON_ERROR)`) or a named parameter (e.g. `json_decode($foo, flags: JSON_THROW_ON_ERROR)`).
All keys are optional but if you don't specify `name`, the named parameter will not be found in a call like e.g. `json_decode($foo, null, 512, flags: JSON_THROW_ON_ERROR)`.
And vice versa, if you don't specify the `position` key, only the named parameter will be found matching this definition, not the positional one.

You can use shortcuts like
```neon
parameters:
    disallowedFunctionCalls:
            # ...
            allowParamsAnywhere:
                2: true
                foo: 'bar'
            allowParamsAnywhereAnyValue:
                - 2
                - foo
```

which internally expands to

```neon
parameters:
    disallowedFunctionCalls:
            # ...
            allowParamsAnywhere:
                -
                    position: 2
                    value: true
                -
                    name: foo
                    value: 'bar'
            allowParamsAnywhereAnyValue:
                -
                    position: 2
                -
                    name: foo
```

But because the "positional _or_ named" limitation described above applies here as well, I generally don't recommend using these shortcuts and instead recommend specifying both `position` and `name` keys.

### First-class callables

When a function or method is used as a [first-class callable](https://www.php.net/functions.first_class_callable_syntax) (`strlen(...)`), no arguments are present at the call site - the callable is invoked later with whatever arguments the caller passes. Because parameter conditions that restrict which calls are *allowed* (`allowParamsInAllowed`, `allowParamsInAllowedAnyValue`, `allowParamFlagsInAllowed`, `allowParamsAnywhere`, `allowParamsAnywhereAnyValue`, `allowParamFlagsAnywhere`) cannot be evaluated without arguments, any first-class callable is always reported when such a condition is configured, no matter where in the code it appears.

Conditions that restrict which calls are *disallowed* behave differently - the forbidden parameter condition cannot be triggered without arguments. For the `*Anywhere` variants (`allowExceptParamsAnywhere`, `allowExceptParamsAnyValue`, `allowExceptParamFlags`, `allowExceptCaseInsensitiveParams`, and their aliases), first-class callables are never reported. For the `*InAllowed` variants (`allowExceptParamsInAllowed`, `allowExceptParamFlagsInAllowed`, and their aliases), first-class callables are not reported inside the relevant zone; outside it, the zone rule alone determines whether the call is reported.

To allow a first-class callable of a disallowed function, use a zone-based directive without a parameter condition, for example `allowIn`, `allowInMethods`, or `allowInInstanceOf`. Alternatively, use an anonymous function that calls the function with the required argument: `fn($x) => hash('sha256', $x)` instead of `hash(...)`.

### PHPDoc type strings

Instead of the `value` directive, you can use the `typeString` directive which allows you to specify arrays, unions, and anything that can be expressed with PHPDoc:

```neon
parameters:
    disallowedFunctionCalls:
            # ...
            allowParamsInAllowed:
                -
                    position: 1
                    name: 'message'
                    typeString: "'foo'"
```

The above example is the same as writing `value: foo` but because you want to specify a literal type string, you need to enclose the string in single quotes to indicate it's a string, not a class name.  With integers, `typeString: 1` is the same as `value: 1`.

Type string allows you to specify:
- Arrays, e.g. `typeString: array{}` meaning empty array, or vice versa with `typeString: non-empty-array`, or even `typeString: array{foo:'bar'}` meaning an array with a `foo` key and `bar` string value
- Unions, e.g. `typeString: 1|2`, `typeString: "'foo'|'bar'"`, where the former example means the value must be an integer `1` or an integer `2`, and the latter means the value must be a string `foo` or `bar`
- Classes, e.g. `typeString: DateTime` which means an object of that class, or a child class od that class
- Any type as [understood by PHPStan](https://phpstan.org/writing-php-code/phpdoc-types), but not everything may make sense in your case

If both `typeString` and `value` directives are specified, the `value` directive is ignored.

### Class name patterns

When you need to match a parameter's class by a wildcard pattern rather than an exact type, use the `classPattern` directive instead of `typeString`. It works with all param directives (`allowParamsAnywhere`, `allowParamsInAllowed`, `allowExceptParamsAnywhere`, `allowExceptParamsInAllowed`, etc.):

```neon
parameters:
    disallowedFunctionCalls:
        -
            function: 'myFunc()'
            allowExceptParamsAnywhere:
                -
                    position: 1
                    classPattern: 'Vendor\Contracts\*'
```

This will disallow `myFunc()` when the first argument is an instance of any class whose fully-qualified name matches `Vendor\Contracts\*`. The pattern uses [`fnmatch`](https://www.php.net/function.fnmatch) syntax.

The matching is done on the argument's **declared class name only** — it does not traverse parent classes or interfaces. An argument typed as `Vendor\Contracts\Foo` matches `Vendor\Contracts\*`, but an argument typed as a subclass defined outside that namespace would not, even if it extends a class inside the namespace. For interface/parent traversal, use `typeString` with a base class or interface name instead. Note also that if PHPStan only knows the argument is `object` (not a specific class), the pattern will not match. The same applies to non-object types such as `int` or `string` — they have no class name, so the pattern never matches.

If both `classPattern` and `typeString` are specified, `classPattern` takes precedence and `typeString` is ignored.
