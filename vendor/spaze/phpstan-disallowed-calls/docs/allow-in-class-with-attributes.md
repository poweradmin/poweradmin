## Allow in class with given attributes

It is possible to allow a previously disallowed item when done in a class with specified attributes.
You can use the `allowInClassWithAttributes` configuration option.

For example, if you'd have a configuration like this:

```neon
parameters:
    disallowedMethodCalls:
        -
            method: 'PotentiallyDangerous\Logger::log()'
            allowInClassWithAttributes:
                - MyAttribute
```

Then the `log()` call would be allowed in a class that would look like this, note the attribute added on the class:

```php
#[MyAttribute]
class Foo
{
    public function bar(): void
    {
        $this->dangerousLogger->log('something');
    }
}
```

On the other hand, if you need to disallow an item only when present in a method from a class with a given attribute,
use `allowExceptInClassWithAttributes` (or the `disallowInClassWithAttributes` alias):

```neon
parameters:
    disallowedMethodCalls:
        -
            method: 'PotentiallyDangerous\Logger::log()'
            allowExceptInClassWithAttributes:
                - SomeAttribute
```

The `log()` method call would be allowed in the following class:

```php
class Foo
{
    public function bar(): void
    {
        $this->dangerousLogger->log('something');
    }
}
```

It would be disallowed in this class and in this class only because it has the `SomeAttribute` attribute:

```php
#[SomeAttribute]
class Foo
{
    public function bar(): void
    {
        $this->dangerousLogger->log('something');
    }
}
```

The attribute names in the _allow_ directives support [fnmatch()](https://www.php.net/function.fnmatch) patterns, and only one needs to match if multiple are specified.

### Allow namespace or classname use in `use` imports

You can allow a namespace or a classname to be used in `use` imports with `allowInUse: true`.
This can be useful when you want to disallow a namespace usage in a class with an attribute (with `allowExceptInClassWithAttributes` or `disallowInClassWithAttributes`),
but don't want the error to be reported on line with the `use` statement.

Let's have a class like this:

```php
use Foo\Bar\DisallowedClass; // line 1

#[MyAttribute]
class Waldo
{

    public function fred(DisallowedClass $param) // line 7
    {
    }

}
```

Then with a configuration like this:

```neon
parameters:
    disallowedNamespace:
        -
            namespace: 'Foo\Bar\DisallowedClass'
            allowExceptInClassWithAttributes:
                - MyAttribute
```

the error would be reported both on line 1, because `use Foo\Bar\DisallowedClass;` uses a disallowed namespace, and on line 7 because `$param` has the disallowed type.
But maybe you'd expect the error to be reported only on line 7, because _that_ is a disallowed class used in a class with the `MyAttribute` attribute.

To omit the `use` finding, you can add the `allowInUse` line, like this:

```neon
parameters:
    disallowedNamespace:
        -
            namespace: 'Foo\Bar\DisallowedClass'
            allowExceptInClassWithAttributes:
                - MyAttribute
            allowInUse: true
```
