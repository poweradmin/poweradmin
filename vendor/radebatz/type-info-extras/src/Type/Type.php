<?php declare(strict_types=1);

namespace Radebatz\TypeInfoExtras\Type;

use Radebatz\TypeInfoExtras\TypeFactoryTrait;
use Symfony\Component\TypeInfo\Type as BaseType;

abstract class Type extends BaseType
{
    use TypeFactoryTrait;
}
