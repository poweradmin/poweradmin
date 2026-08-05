## Disallowing enums

Similar to disallowing constants, enums have some limitations, because internally, enums are disallowed as constants.

First, disallowing enums doesn't support wildcards. The only use case I could think of would be disallowing all cases, e.g. `Enum::*`, which can be achieved by adding the enum to `disallowedClasses`.

Second, enums have to be specified using two keys: `enum` and `case`:
```neon
parameters:
    disallowedEnums:
        -
            enum: 'Suit'
            case: 'Hearts'
            message: 'use Diamonds instead'
```
This is to prevent the enum case being treated like a real enum by the config parser.

Both pure enums and backed enums are supported.
