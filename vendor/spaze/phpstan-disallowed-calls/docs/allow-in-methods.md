## Allow in methods or functions

To allow a previously disallowed item like method or function etc. only when called from a different method or function in any file, use `allowInFunctions` (or `allowInMethods` alias):

```neon
parameters:
    disallowedMethodCalls:
        -
            method: 'PotentiallyDangerous\Logger::log()'
            message: 'use our own logger instead'
            allowInMethods:
                - Foo\Bar\Baz::method()
```

And vice versa, if you need to disallow a method or a function call only when done from a particular method or function, use `allowExceptInFunctions` (with aliases `allowExceptInMethods`, `disallowInFunctions`, `disallowInMethods`):

```neon
parameters:
    disallowedMethodCalls:
        -
            method: 'Controller::redirect()'
            message: 'redirect in startup() instead'
            allowExceptInMethods:
                - Controller\Foo\Bar\*::__construct()
```

The function or method names support [fnmatch()](https://www.php.net/function.fnmatch) patterns.

`allowInFunctions` and `allowInMethods` are aliases for one list of patterns matched against both method and function names - when one config entry sets both keys, only `allowInFunctions` is used, so put all the patterns in a list under one of the keys.
The same applies to `allowExceptInFunctions` and its aliases.

Both `allowInMethods` and `allowExceptInMethods` can be combined with `allowParamsInAllowed` or `allowExceptParamsInAllowed` (also known as `disallowParamsInAllowed`) to further narrow the allow condition by parameter values - see [allowing with specified parameters](allow-with-parameters.md).

### Attributes on methods

In case of disallowing attributes and then re-allowing them in methods or functions by name, the disallowed attributes can be both _inside_ the method or the function, and _on_ the method or the function.
This means that the following code with the following configuration is also valid, even though `CrossOriginRedirect` is technically not used _inside_ the method.

```php
class Presenter
{

    #[CrossOriginRedirect]
    public function actionFoo(): void
    {
    }

}
```

```neon
parameters:
    disallowedAttributes:
        -
            attribute: CrossOriginRedirect
            allowInMethods:
                - '*::action*()'
```

No error would be reported for `CrossOriginRedirect` as it is used "in" a method whose name matches one of the `allowInMethods` patterns.
The same applies to `allowExceptInMethods` (and the aliases): an attribute placed on a method or a function whose name matches one of the patterns will be reported.

For a named function declared inside a method or another function, the enclosing method or function is the one matched against the patterns, the same way `allowInMethodsWithAttributes` uses the enclosing method's or function's attributes.
