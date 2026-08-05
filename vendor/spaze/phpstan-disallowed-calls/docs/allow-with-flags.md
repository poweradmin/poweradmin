## Allow with specified parameter flags only

Some functions can be called with _flags_ or _bitmasks_, for example

```php
json_encode($foo, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
```
Let's say you want to disallow `json_encode()` except when called with `JSON_HEX_APOS` (integer `4`) flag. In the call above, the value of the second parameter (`JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT`) is `13` (`1 | 4 | 8`).
For the extension to be able to "find" the `4` in `13`, you need to use the `ParamFlags` family of config options:

- `allowParamFlagsInAllowed`
- `allowParamFlagsAnywhere`
- `allowExceptParamFlagsInAllowed` or `disallowParamFlagsInAllowed`
- `allowExceptParamFlags` or `disallowParamFlags`

They work like their non-flags `Param` counterparts except they're looking if specific bits in the mask parameter are set.

The `json_encode()` example mentioned above would look like the following snippet:

```neon
parameters:
    disallowedFunctionCalls:
            function: 'json_encode'
            allowParamFlagsAnywhere:
                -
                    position: 2
                    value: ::JSON_HEX_APOS
```

Just like with regular parameters, you can also use `typeString` instead of `value`.
The extra bonus this brings is unions: if you want to (dis)allow a parameter when either the flag `1` or `2` is set, use `typeString: 1 | 2`. Note that the `|` operator here is not the PHP's _bitwise or_ operator.
