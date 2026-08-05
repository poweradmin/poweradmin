## Allow some previously disallowed calls or attributes

Sometimes, the method, the function, or the constant needs to be called or used once in your code, for example in a custom wrapper. You can use PHPStan's [`ignoreErrors` feature](https://github.com/phpstan/phpstan#ignore-error-messages-with-regular-expressions) to ignore that one call:

```neon
ignoreErrors:
    -
        message: '#^Calling Redis::connect\(\) is forbidden, use our own Redis instead#'  # Needed for the constructor
        path: application/libraries/Redis/Redis.php
    -
        message: '#^Calling print_r\(\) is forbidden, use logger instead#'  # Used with $return = true
        paths:
            - application/libraries/Tls/Certificate.php
            - application/libraries/Tls/CertificateSigningRequest.php
            - application/libraries/Tls/PublicKey.php
```

The extension's configuration using custom rules  is flexible enough to allow a call with specified attributes only for example.
