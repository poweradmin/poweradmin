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
use Symfony\Component\TypeInfo\Type\UnionType;

trait ResolverExtrasTrait
{
    /**
     * Resolve the bounds of an `int<…>` node.
     *
     * A malformed range, such as `int<0>`, `int<0, 5, 9>`, `int<5, 1>` or `int<0, foo>`, gives `null`,
     * and the caller falls back to plain `int`. So does `int<min, max>`, a range without bounds.
     */
    protected function resolveIntRange(GenericTypeNode $node): ?IntRangeType
    {
        if (2 !== \count($node->genericTypes)) {
            return null;
        }

        $getBoundaryFromNode = function (TypeNode $node, string $keyword): ?int {
            if ($node instanceof IdentifierTypeNode) {
                return $keyword === $node->name ? ('min' === $keyword ? \PHP_INT_MIN : \PHP_INT_MAX) : null;
            }

            if ($node instanceof ConstTypeNode && $node->constExpr instanceof ConstExprIntegerNode) {
                return (int) $node->constExpr->value;
            }

            return null;
        };

        $from = $getBoundaryFromNode($node->genericTypes[0], 'min');
        $to = $getBoundaryFromNode($node->genericTypes[1], 'max');

        if (null === $from || null === $to || $from > $to || (\PHP_INT_MIN === $from && \PHP_INT_MAX === $to)) {
            return null;
        }

        return Type::intRange($from, $to);
    }

    /**
     * @param list<BaseType> $variableTypes
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

    public function reduceToBuiltinType(?BaseType $type): ?BaseType
    {
        // a union, such as "non-zero-int", is reduced member by member, and members that become the same are merged
        if ($type instanceof UnionType) {
            $types = [];
            foreach ($type->getTypes() as $member) {
                $member = $this->reduceToBuiltinType($member);
                $types[(string) $member] = $member;
            }

            $types = array_values($types);

            return 1 === \count($types) ? $types[0] : BaseType::union(...$types);
        }

        return $type instanceof ExplicitType
            ? $type->getBuiltinType()
            : $type;
    }
}
