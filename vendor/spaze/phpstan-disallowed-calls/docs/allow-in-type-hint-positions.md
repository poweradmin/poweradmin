## Allow in type hint positions

These configuration keys apply only to `disallowedNamespaces` and `disallowedClasses`.
They let you restrict or permit a class or namespace based on whether it appears as a **parameter type hint** or a **return type hint** in a function or method signature.

### Allow only in param types

Use `allowInParamTypes: true` when a class is normally disallowed everywhere but should be allowed when used as a parameter type hint.

```neon
parameters:
    disallowedClasses:
        -
            class: 'OldService'
            message: 'use NewService instead'
            allowInParamTypes: true
```

Given this configuration, the following applies:

```php
use OldService;  // disallowed - use import

class Foo
{
    public function __construct(
        private OldService $service,  // allowed - parameter type hint
    ) {}

    public function handle(OldService $svc): void  // allowed - parameter type hint
    {
        $old = new OldService();  // disallowed - not a type hint
    }

    public function get(): OldService  // disallowed - return type hint, not param
    {
    }
}
```

### Disallow only in param types

Use `allowExceptInParamTypes: true` (or the `disallowInParamTypes: true` alias) to disallow a class only when it appears as a parameter type hint, allowing it everywhere else.

```neon
parameters:
    disallowedClasses:
        -
            class: 'LegacyValue'
            message: 'do not accept LegacyValue as a parameter type'
            disallowInParamTypes: true
```

```php
use LegacyValue;  // allowed

class Bar
{
    public function process(LegacyValue $v): void  // disallowed - parameter type hint
    {
    }

    public function get(): LegacyValue  // allowed - return type hint, not param
    {
    }

    public function create(): void
    {
        $v = new LegacyValue();  // allowed - not a type hint
    }
}
```

### Allow only in return types

Use `allowInReturnType: true` when a class should be allowed only as a return type hint.

```neon
parameters:
    disallowedClasses:
        -
            class: 'InternalResult'
            message: 'use PublicResult outside of internal layers'
            allowInReturnType: true
```

### Disallow only in return types

Use `allowExceptInReturnType: true` (or the `disallowInReturnType: true` alias) to disallow a class only when it appears as a return type hint.

```neon
parameters:
    disallowedClasses:
        -
            class: 'RawData'
            message: 'do not expose RawData as a return type'
            disallowInReturnType: true
```

```php
class Processor
{
    public function transform(RawData $input): void  // allowed - parameter type hint
    {
    }

    public function fetch(): RawData  // disallowed - return type hint
    {
    }

    public function create(): void
    {
        $r = new RawData();  // allowed - not a type hint
    }
}
```

### Use imports

Note that `use` imports are not type hints and are therefore not affected by these configuration keys - the `use` line will still be reported as a separate occurrence. To suppress that, combine with `allowInUse: true`:

```neon
parameters:
    disallowedClasses:
        -
            class: 'RawData'
            message: 'do not expose RawData as a return type'
            disallowInReturnType: true
            allowInUse: true
```
