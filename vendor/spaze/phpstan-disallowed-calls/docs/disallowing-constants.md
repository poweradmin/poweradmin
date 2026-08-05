## Disallowing constants

Constants are a special breed. First, a constant needs to be disallowed on the declaring class. That means, that instead of disallowing `Date::ISO8601` or `DateTimeImmutable::ISO8601`, you need to disallow `DateTimeInterface::ISO8601`.
The reason for this is that one might expect that disallowing e.g. `Date::ISO8601` (disallowing on a "used on" class) would also disallow `DateTimeImmutable::ISO8601`, which unfortunately wouldn't be the case.

Second, disallowing constants doesn't support wildcards. The only real-world use case I could think of is the `Date*::CONSTANT` case and that can be easily solved by disallowing `DateTimeInterface::CONSTANT` already.

Last but not least, class constants have to be specified using two keys: `class` and `constant`:
```neon
parameters:
    disallowedConstants:
        -
            class: 'DateTimeInterface'
            constant: 'ISO8601'
            message: 'use DateTimeInterface::ATOM instead'
```
Using the fully-qualified name would result in the constant being replaced with its actual value. Otherwise, the extension would see `constant: "Y-m-d\TH:i:sO"` instead of `constant: DateTimeInterface::ISO8601` for example.
