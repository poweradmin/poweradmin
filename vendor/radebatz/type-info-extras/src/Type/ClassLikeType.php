<?php declare(strict_types=1);

namespace Radebatz\TypeInfoExtras\Type;

use Symfony\Component\TypeInfo\Type\ObjectType;
use Symfony\Component\TypeInfo\TypeIdentifier;

/**
 * Class-like string type.
 *
 * @author Martin Rademacher <mano@radebatz.net>
 *
 * @extends ExplicitType<TypeIdentifier::STRING>
 */
final class ClassLikeType extends ExplicitType
{
    public function __construct(string $explicitType, private ObjectType $objectType)
    {
        parent::__construct(TypeIdentifier::STRING, $explicitType);
    }

    public function getObjectType(): ObjectType
    {
        return $this->objectType;
    }

    public function __toString(): string
    {
        return \sprintf('%s<%s>', $this->getExplicitType(), $this->objectType);
    }
}
