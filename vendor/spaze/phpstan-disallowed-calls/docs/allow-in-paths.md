## Allow in paths

You can allow some previously disallowed calls and usages using the `allowIn` configuration key, for example:

```neon
parameters:
    disallowedMethodCalls:
        -
            method: 'PotentiallyDangerous\Logger::log()'
            message: 'use our own logger instead'
            allowIn:
                - path/to/some/file-*.php
                - tests/*.test.php
```

Paths in `allowIn` support [fnmatch()](https://www.php.net/function.fnmatch) patterns.

Relative paths in `allowIn` are resolved based on the current working directory. When running PHPStan from a directory or subdirectory which is not your "root" directory, the paths will probably not work.
Use `filesRootDir` in that case to specify an absolute root directory for all `allowIn` paths. Absolute paths might change between machines (for example your local development machine and a continuous integration machine) but you
can use [`%rootDir%`](https://phpstan.org/config-reference#expanding-paths) to start with PHPStan's root directory (usually `/something/something/vendor/phpstan/phpstan`) and then `..` from there to your "root" directory.

For example when PHPStan is installed in `/home/foo/vendor/phpstan/phpstan` and you're using a configuration like this:
```neon
parameters:
    filesRootDir: %rootDir%/../../..
    disallowedMethodCalls:
        -
            method: 'PotentiallyDangerous\Logger::log()'
            allowIn:
                - path/to/some/file-*.php
```
then `Logger::log()` will be allowed in `/home/foo/path/to/some/file-bar.php`.

If you need to disallow a methods or a function call, a constant, a namespace, a class, a superglobal, or an attribute usage only in certain paths, as an inverse of `allowIn`, you can use `allowExceptIn` (or the `disallowIn` alias):
```neon
parameters:
    filesRootDir: %rootDir%/../../..
    disallowedMethodCalls:
        -
            method: 'PotentiallyDangerous\Logger::log()'
            allowExceptIn:
                - path/to/some/dir/*.php
```
This will disallow `PotentiallyDangerous\Logger::log()` calls in `%rootDir%/../../../path/to/some/dir/*.php`.

Both `allowIn` and `allowExceptIn` can be combined with `allowParamsInAllowed` or `allowExceptParamsInAllowed` (also known as `disallowParamsInAllowed`) to further narrow the allow condition by parameter values - see [allowing with specified parameters](allow-with-parameters.md).

Please note that before version 2.15, `filesRootDir` was called `allowInRootDir` which is still supported, but deprecated.
