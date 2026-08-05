## Allowing previously disallowed attributes

Disallowed PHP attributes can be allowed again using the same configuration as what methods and functions use. For example, to require `#[Entity]` attribute to always specify `$repositoryClass` argument, you can use configuration similar to the one below. First, we disallow all `#[Entity]` attributes, then re-allow them only if they contain the parameter (with any value):
```neon
parameters:
    disallowedAttributes:
        -
            attribute: 'Doctrine\ORM\Mapping\Entity'
            message: 'you must specify $repositoryClass parameter with Entity'
            allowParamsAnywhereAnyValue:
                -
                    position: 1
                    name: repositoryClass
```

You can also use `value` or `typeString` directives, just like with functions or methods.
