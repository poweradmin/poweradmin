<?php
declare(strict_types = 1);

namespace Spaze\PHPStan\Rules\Disallowed\Usages;

use PhpParser\Node;
use PhpParser\Node\Expr\PropertyFetch;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\PropertyReflection;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\ShouldNotHappenException;
use Spaze\PHPStan\Rules\Disallowed\DisallowedProperty;
use Spaze\PHPStan\Rules\Disallowed\DisallowedPropertyFactory;
use Spaze\PHPStan\Rules\Disallowed\PHPStan1Compatibility;
use Spaze\PHPStan\Rules\Disallowed\RuleErrors\DisallowedPropertyRuleErrors;

/**
 * Reports on an instance property usage.
 *
 * @package Spaze\PHPStan\Rules\Disallowed
 * @implements Rule<PropertyFetch>
 */
class InstancePropertyUsages implements Rule
{

	private DisallowedPropertyRuleErrors $disallowedPropertyRuleErrors;

	/** @var list<DisallowedProperty> */
	private array $disallowedProperties;


	/**
	 * @phpstan-param DisallowedPropertiesConfig $disallowedProperties
	 * @throws ShouldNotHappenException
	 */
	public function __construct(
		DisallowedPropertyFactory $disallowedPropertyFactory,
		DisallowedPropertyRuleErrors $disallowedPropertyRuleErrors,
		array $disallowedProperties
	) {
		$this->disallowedPropertyRuleErrors = $disallowedPropertyRuleErrors;
		$this->disallowedProperties = $disallowedPropertyFactory->createFromConfig($disallowedProperties);
	}


	public function getNodeType(): string
	{
		return PropertyFetch::class;
	}


	/**
	 * @param Node $node
	 * @param Scope $scope
	 * @return list<RuleError>
	 * @throws ShouldNotHappenException
	 */
	public function processNode(Node $node, Scope $scope): array
	{
		if (!$node instanceof PropertyFetch) {
			throw new ShouldNotHappenException(sprintf('$node should be %s but is %s', PropertyFetch::class, get_class($node)));
		}
		return $this->disallowedPropertyRuleErrors->get(
			$node->var,
			$node,
			$scope,
			fn(string $property, ClassReflection $class): bool => PHPStan1Compatibility::hasInstanceProperty($property, $class),
			fn(string $property, ClassReflection $class, Scope $scope): PropertyReflection => PHPStan1Compatibility::getInstanceProperty($property, $class, $scope),
			fn(string $property, ClassReflection $trait): bool => PHPStan1Compatibility::hasInstanceProperty($property, $trait),
			fn(string $property, ClassReflection $trait, Scope $scope): PropertyReflection => PHPStan1Compatibility::getInstanceProperty($property, $trait, $scope),
			$this->disallowedProperties
		);
	}

}
