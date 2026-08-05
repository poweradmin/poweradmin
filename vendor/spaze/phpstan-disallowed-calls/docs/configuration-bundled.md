## Using bundled configuration files

You can start by including `disallowed-dangerous-calls.neon` in your `phpstan.neon`:

```neon
includes:
    - vendor/spaze/phpstan-disallowed-calls/disallowed-dangerous-calls.neon
```

`disallowed-dangerous-calls.neon` can also serve as a template when you'd like to extend the configuration to disallow some other functions or methods, copy it and modify to your needs.
You can also allow a previously disallowed dangerous call in a defined path (see below) in your own config by using the same `call` or `method` key.

If you want to disallow program execution functions (`exec()`, `shell_exec()` & friends) including the backtick operator (`` `...` ``, disallowed when `shell_exec()` is disallowed), include `disallowed-execution-calls.neon`:

```neon
includes:
    - vendor/spaze/phpstan-disallowed-calls/disallowed-execution-calls.neon
```

I'd recommend you include both:

```neon
includes:
    - vendor/spaze/phpstan-disallowed-calls/disallowed-dangerous-calls.neon
    - vendor/spaze/phpstan-disallowed-calls/disallowed-execution-calls.neon
```

To disallow some insecure or potentially insecure calls (like `md5()`, `sha1()`, `mysql_query()`), include `disallowed-insecure-calls.neon`:

```neon
includes:
    - vendor/spaze/phpstan-disallowed-calls/disallowed-insecure-calls.neon
```

Some function calls are better when done for example with some parameters set to a defined value ("strict calls"). For example `in_array()` better also check for types to prevent some type juggling bugs. Include `disallowed-loose-calls.neon` to disallow calls without such parameters set ("loose calls").

```neon
includes:
    - vendor/spaze/phpstan-disallowed-calls/disallowed-loose-calls.neon
```
