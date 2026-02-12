<?php

namespace Radebatz\TypeInfoExtras\TypeResolver;

use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use Radebatz\TypeInfoExtras\Type\ClassLikeType;
use Radebatz\TypeInfoExtras\Type\ExplicitType;
use Radebatz\TypeInfoExtras\Type\IntRangeType;
use Radebatz\TypeInfoExtras\Type\Type;
use Symfony\Component\TypeInfo\Type as BaseType;
use Symfony\Component\TypeInfo\Type\ObjectType;

trait ResolverExtrasTrait
{
    protected function resolveIntRange(GenericTypeNode $node): IntRangeType
    {
        $getBoundaryFromNode = function (TypeNode $node) {
            if ($node instanceof IdentifierTypeNode) {
                return match ($node->name) {
                    'min' => \PHP_INT_MIN,
                    'max' => \PHP_INT_MAX,
                    default => throw new \DomainException(\sprintf('Invalid int range value "%s".', $node->name)),
                };
            }

            if ($node instanceof ConstTypeNode && $node->constExpr instanceof ConstExprIntegerNode) {
                return (int) $node->constExpr->value;
            }

            throw new \DomainException(\sprintf('Invalid int range expression "%s".', \get_class($node)));
        };

        $boundaries = array_map(fn (TypeNode $t): int => $getBoundaryFromNode($t), $node->genericTypes);

        return Type::intRange($boundaries[0], $boundaries[1]);
    }

    /**
     * @param array<BaseType> $variableTypes
     */
    protected function tryAsClassLike(BaseType $type, array $variableTypes): ?ClassLikeType
    {
        if ($type instanceof ExplicitType
            && \in_array($type->getExplicitType(), ['class-string', 'interface-string', 'trait-string'], true)
            && 1 === \count($variableTypes) && $variableTypes[0] instanceof ObjectType) {
            return Type::classLike($type->getExplicitType(), $variableTypes[0]);
        }

        return null;
    }
}
