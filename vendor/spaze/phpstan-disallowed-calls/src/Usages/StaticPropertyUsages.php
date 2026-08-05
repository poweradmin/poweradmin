<?php
declare(strict_types = 1);

namespace Spaze\PHPStan\Rules\Disallowed\Usages;

use PhpParser\Node;
use PhpParser\Node\Expr\StaticPropertyFetch;
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
 * Reports on a static property usage.
 *
 * @package Spaze\PHPStan\Rules\Disallowed
 * @implements Rule<StaticPropertyFetch>
 */
class StaticPropertyUsages implements Rule
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
		return StaticPropertyFetch::class;
	}


	/**
	 * @param Node $node
	 * @param Scope $scope
	 * @return list<RuleError>
	 * @throws ShouldNotHappenException
	 */
	public function processNode(Node $node, Scope $scope): array
	{
		if (!$node instanceof StaticPropertyFetch) {
			throw new ShouldNotHappenException(sprintf('$node should be %s but is %s', StaticPropertyFetch::class, get_class($node)));
		}
		return $this->disallowedPropertyRuleErrors->get(
			$node->class,
			$node,
			$scope,
			fn(string $property, ClassReflection $class): bool => PHPStan1Compatibility::hasStaticProperty($property, $class),
			fn(string $property, ClassReflection $class, Scope $scope): PropertyReflection => PHPStan1Compatibility::getStaticProperty($property, $class, $scope),
			fn(string $property, ClassReflection $trait): bool => PHPStan1Compatibility::hasStaticProperty($property, $trait),
			fn(string $property, ClassReflection $trait, Scope $scope): PropertyReflection => PHPStan1Compatibility::getStaticProperty($property, $trait, $scope),
			$this->disallowedProperties
		);
	}

}
