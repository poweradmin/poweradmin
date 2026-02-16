[![build](https://github.com/DerManoMann/type-info-extras/actions/workflows/build.yml/badge.svg)](https://github.com/DerManoMann/type-info-extras/actions/workflows/build.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

TypeInfoExtras
==============

Library adding some extra features to the Symfony [Type Info](https://github.com/symfony/type-info) component.

Compatible wit [type-info:7.3.8+](https://github.com/symfony/type-info/tree/7.3) branch.

Basic Usage
-----------

```php
<?php

use Radebatz\TypeInfoExtras\TypeResolver\StringTypeResolver as ExtraStringTypeResolver;

$resolver = new ExtraStringTypeResolver();

$type = $resolver->resolve('html-escaped-string');
echo get_class($type); //  Radebatz\TypeInfoExtras\Type\ExplicitType
echo $type->getExplicitType() // "html-escaped-string"

$type = $resolver->resolve('class-string<Foo>');
echo get_class($type); //  Radebatz\TypeInfoExtras\Type\ClassLikeType
echo $type->getExplicitType(); // "class-string"
echo get_class($type->getObjectType()); // Symfony\Component\TypeInfo\Type\ObjectType
echo $type->getObjectType(); // Foo

$type = $resolver->resolve('int<5,20>');
echo get_class($type); //  Radebatz\TypeInfoExtras\Type\IntRangeType

```

If your code is doing `instanceof` checks on the returned `Type`, then you will need to add another case and treat
`Radebatz\TypeInfoExtras\Tests\Type\ExplicitType` same as `BuiltinType`.
