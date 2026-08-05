<?php
declare(strict_types = 1);

namespace Spaze\PHPStan\Rules\Disallowed\RuleErrors;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MissingPropertyFromReflectionException;
use PHPStan\Reflection\PropertyReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;
use Spaze\PHPStan\Rules\Disallowed\Allowed\Allowed;
use Spaze\PHPStan\Rules\Disallowed\DisallowedProperty;
use Spaze\PHPStan\Rules\Disallowed\Formatter\Formatter;
use Spaze\PHPStan\Rules\Disallowed\Identifier\Identifier;
use Spaze\PHPStan\Rules\Disallowed\Normalizer\Normalizer;
use Spaze\PHPStan\Rules\Disallowed\Type\TypeResolver;

class DisallowedPropertyRuleErrors
{

	private Allowed $allowed;

	private TypeResolver $typeResolver;

	private ReflectionProvider $reflectionProvider;

	private Normalizer $normalizer;

	private Formatter $formatter;

	private Identifier $identifier;

	private ErrorTips $errorTips;


	public function __construct(
		Allowed $allowed,
		TypeResolver $typeResolver,
		ReflectionProvider $reflectionProvider,
		Normalizer $normalizer,
		Formatter $formatter,
		Identifier $identifier,
		ErrorTips $errorTips
	) {
		$this->allowed = $allowed;
		$this->typeResolver = $typeResolver;
		$this->reflectionProvider = $reflectionProvider;
		$this->normalizer = $normalizer;
		$this->formatter = $formatter;
		$this->identifier = $identifier;
		$this->errorTips = $errorTips;
	}


	/**
	 * @param Expr|Name $usedOn
	 * @param PropertyFetch|StaticPropertyFetch $node
	 * @param Scope $scope
	 * @param callable(string, ClassReflection): bool $hasProperty
	 * @param callable(string, ClassReflection, Scope): PropertyReflection $getProperty
	 * @param callable(string, ClassReflection): bool $traitHasProperty
	 * @param callable(string, ClassReflection, Scope): PropertyReflection $traitGetProperty
	 * @param list<DisallowedProperty> $disallowedProperties
	 * @return list<RuleError>
	 * @throws MissingPropertyFromReflectionException
	 */
	public function get(
		Node $usedOn,
		Node $node,
		Scope $scope,
		callable $hasProperty,
		callable $getProperty,
		callable $traitHasProperty,
		callable $traitGetProperty,
		array $disallowedProperties
	): array {
		$usedOnType = $this->typeResolver->getType($usedOn, $scope);
		$classNames = $this->typeResolver->getClassNames($usedOnType);
		$propertyNames = $this->typeResolver->getNames($node, $scope);

		$errors = [];
		foreach ($classNames as $className) {
			if (!$this->reflectionProvider->hasClass($className)) {
				continue;
			}
			$classReflection = $this->reflectionProvider->getClass($className);
			foreach ($propertyNames as $propertyName) {
				$ruleErrors = $this->getErrorMessages(
					$propertyName->toString(),
					$classReflection,
					$hasProperty,
					$getProperty,
					$traitHasProperty,
					$traitGetProperty,
					$node,
					$scope,
					$disallowedProperties,
				);
				if ($ruleErrors !== []) {
					$errors = array_merge($errors, $ruleErrors);
				}
			}
		}
		return $errors;
	}


	private function getPropertyDisplayName(ClassReflection $classReflection, string $property): string
	{
		return $this->normalizer->normalizeNamespace($classReflection->getDisplayName(false)) . '::$' . $property;
	}


	/**
	 * @param string $propertyName
	 * @param callable(string, ClassReflection): bool $hasProperty
	 * @param callable(string, ClassReflection, Scope): PropertyReflection $getProperty
	 * @param callable(string, ClassReflection): bool $traitHasProperty
	 * @param callable(string, ClassReflection, Scope): PropertyReflection $traitGetProperty
	 * @param Scope $scope
	 * @param list<DisallowedProperty> $disallowedProperties
	 * @return list<IdentifierRuleError>
	 */
	private function getErrorMessages(
		string $propertyName,
		ClassReflection $classReflection,
		callable $hasProperty,
		callable $getProperty,
		callable $traitHasProperty,
		callable $traitGetProperty,
		Node $node,
		Scope $scope,
		array $disallowedProperties
	): array {
		if (!$hasProperty($propertyName, $classReflection)) {
			return [];
		}
		$propertyReflection = $getProperty($propertyName, $classReflection, $scope);
		$declaringClass = $propertyReflection->getDeclaringClass();
		$properties = [
			$this->getPropertyDisplayName($declaringClass, $propertyName),
		];
		$traits = $declaringClass->getTraits();
		foreach ($traits as $trait) {
			$this->addTraitProperties(
				$propertyName,
				$traitHasProperty,
				$traitGetProperty,
				$trait,
				$scope,
				$properties,
			);
		}
		$displayName = $this->getPropertyDisplayName($classReflection, $propertyName);
		foreach ($disallowedProperties as $disallowedProperty) {
			foreach ($properties as $property) {
				if (
					!$this->identifier->matches($disallowedProperty->getProperty(), $property)
					|| $this->allowed->isAllowed($node, $scope, null, $disallowedProperty)
				) {
					continue;
				}
				$errorBuilder = RuleErrorBuilder::message(sprintf(
					'Using %s is forbidden%s%s',
					($displayName && $displayName !== $property) ? "{$property} (as {$displayName})" : "{$property}",
					$this->formatter->formatDisallowedMessage($disallowedProperty->getMessage()),
					$disallowedProperty->getProperty() !== $property ? " [{$property} matches {$disallowedProperty->getProperty()}]" : ''
				));
				$errorBuilder->identifier($disallowedProperty->getErrorIdentifier() ?? ErrorIdentifiers::DISALLOWED_PROPERTY);
				$this->errorTips->add($disallowedProperty->getErrorTip(), $errorBuilder);
				return [
					$errorBuilder->build(),
				];
			}
		}
		return [];
	}


	/**
	 * @param string $propertyName
	 * @param callable(string, ClassReflection): bool $hasProperty
	 * @param callable(string, ClassReflection, Scope): PropertyReflection $getProperty
	 * @param ClassReflection $trait
	 * @param Scope $scope
	 * @param list<string> $properties
	 */
	private function addTraitProperties(
		string $propertyName,
		callable $hasProperty,
		callable $getProperty,
		ClassReflection $trait,
		Scope $scope,
		array &$properties
	): void {
		if ($hasProperty($propertyName, $trait)) {
			$properties[] = $this->getPropertyDisplayName($getProperty($propertyName, $trait, $scope)->getDeclaringClass(), $propertyName);
		}
	}

}
