<?php declare(strict_types=1);

namespace Radebatz\TypeInfoExtras;

use Radebatz\TypeInfoExtras\Type\ClassLikeType;
use Radebatz\TypeInfoExtras\Type\ExplicitType;
use Radebatz\TypeInfoExtras\Type\IntRangeType;
use Symfony\Component\TypeInfo\Type\ObjectType;
use Symfony\Component\TypeInfo\TypeFactoryTrait as BaseTypeFactoryTrait;
use Symfony\Component\TypeInfo\TypeIdentifier;

trait TypeFactoryTrait
{
    use BaseTypeFactoryTrait;

    public static function explicit(TypeIdentifier|string $identifier, string $explicitType): ExplicitType
    {
        return new ExplicitType($identifier, $explicitType);
    }

    public static function classLike(string $explicitType, ObjectType $objectType): ClassLikeType
    {
        return new ClassLikeType($explicitType, $objectType);
    }

    public static function intRange(int $from = \PHP_INT_MIN, int $to = \PHP_INT_MAX, ?string $explicitType = null): IntRangeType
    {
        return new IntRangeType($from, $to, $explicitType);
    }
}
