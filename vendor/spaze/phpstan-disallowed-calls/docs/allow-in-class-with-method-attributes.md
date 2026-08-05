## Allow in class with given attributes on any method

You can allow or disallow an item in a class where any method, including the method the call is done in, but not the class itself, has the specified attribute.
This is done with `allowInClassWithMethodAttributes` and `allowExceptInClassWithMethodAttributes` (or `disallowInClassWithMethodAttributes` which is an alias). The method with the attribute can also be static, final or even abstract, and the method visibility doesn't matter.

```neon
parameters:
    disallowedNamespace:
        -
            class: 'Foo\Bar\DisallowedClass'
            allowInClassWithMethodAttributes:
                - MyAttribute
```

Given the configuration above, no error would be reported in the following source code even though `MyAttribute` is not on the method the DisallowedClass is used in, but on some other method:

```php
class Waldo
{

    public function function1(): void
    {
        Foo\Bar\DisallowedClass::method();
    }


    #[MyAttribute]
    private function checkThis(): void
    {
    }

}
```

The attribute names support [fnmatch()](https://www.php.net/function.fnmatch) patterns, and only one needs to match if multiple are specified.
