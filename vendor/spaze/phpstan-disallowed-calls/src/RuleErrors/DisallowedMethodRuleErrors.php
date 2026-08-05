<?php
declare(strict_types = 1);

namespace Spaze\PHPStan\Rules\Disallowed\RuleErrors;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\ShouldNotHappenException;
use PHPStan\Type\Type;
use Spaze\PHPStan\Rules\Disallowed\DisallowedCall;
use Spaze\PHPStan\Rules\Disallowed\Formatter\Formatter;
use Spaze\PHPStan\Rules\Disallowed\PHPStan1Compatibility;
use Spaze\PHPStan\Rules\Disallowed\Type\TypeResolver;

class DisallowedMethodRuleErrors
{

	private DisallowedCallsRuleErrors $disallowedCallsRuleErrors;

	private TypeResolver $typeResolver;

	private Formatter $formatter;


	public function __construct(
		DisallowedCallsRuleErrors $disallowedCallsRuleErrors,
		TypeResolver $typeResolver,
		Formatter $formatter
	) {
		$this->disallowedCallsRuleErrors = $disallowedCallsRuleErrors;
		$this->typeResolver = $typeResolver;
		$this->formatter = $formatter;
	}


	/**
	 * @param Name|Expr $class
	 * @param MethodCall|StaticCall $node
	 * @param Scope $scope
	 * @param list<DisallowedCall> $disallowedCalls
	 * @return list<IdentifierRuleError>
	 * @throws ShouldNotHappenException
	 */
	public function get($class, CallLike $node, Scope $scope, array $disallowedCalls): array
	{
		$calledOnType = $this->typeResolver->getType($class, $scope);
		if (PHPStan1Compatibility::isClassString($calledOnType)->yes()) {
			$calledOnType = $calledOnType->getClassStringObjectType();
		}
		$errors = [];
		foreach ($this->typeResolver->getNames($node, $scope) as $name) {
			$methodErrors = $this->getErrors($calledOnType, $name->toString(), $node, $scope, $disallowedCalls);
			if ($methodErrors) {
				$errors = array_merge($errors, $methodErrors);
			}
		}
		return $errors;
	}


	/**
	 * @param string $class
	 * @param string $method
	 * @param Scope $scope
	 * @param list<DisallowedCall> $disallowedCalls
	 * @return list<IdentifierRuleError>
	 * @throws ShouldNotHappenException
	 */
	public function getByString(string $class, string $method, Scope $scope, array $disallowedCalls): array
	{
		$className = new Name($class);
		$calledOnType = $this->typeResolver->getType($className, $scope);
		return $this->getErrors($calledOnType, $method, null, $scope, $disallowedCalls);
	}


	/**
	 * @param Type $calledOnType
	 * @param string $methodName
	 * @param MethodCall|StaticCall|null $node
	 * @param Scope $scope
	 * @param list<DisallowedCall> $disallowedCalls
	 * @return list<IdentifierRuleError>
	 * @throws ShouldNotHappenException
	 */
	private function getErrors(Type $calledOnType, string $methodName, ?CallLike $node, Scope $scope, array $disallowedCalls): array
	{
		if (!$calledOnType->canCallMethods()->yes() || !$calledOnType->hasMethod($methodName)->yes()) {
			return [];
		}

		$method = $calledOnType->getMethod($methodName, $scope);
		$declaringClass = $method->getDeclaringClass();
		$classNames = $this->typeResolver->getClassNames($calledOnType);
		if (count($classNames) === 0) {
			$calledAs = null;
		} else {
			$calledAs = $this->formatter->getFullyQualified($this->formatter->formatIdentifier($classNames), $method);
		}

		$classes = [$declaringClass];
		$traits = $declaringClass->getTraits();
		if ($traits) {
			$classes = array_merge($classes, array_values($traits));
		}
		$interfaces = $declaringClass->getInterfaces();
		if ($interfaces) {
			$classes = array_merge($classes, array_values($interfaces));
		}
		return $this->getRuleErrors($classes, $method, $node, $scope, $calledAs, $disallowedCalls);
	}


	/**
	 * @param list<ClassReflection> $classes
	 * @param list<DisallowedCall> $disallowedCalls
	 * @return list<IdentifierRuleError>
	 * @throws ShouldNotHappenException
	 */
	private function getRuleErrors(array $classes, MethodReflection $method, ?CallLike $node, Scope $scope, ?string $calledAs, array $disallowedCalls): array
	{
		foreach ($classes as $class) {
			if ($class->hasMethod($method->getName())) {
				$declaredAs = $this->formatter->getFullyQualified($class->getDisplayName(false), $method);
				$ruleErrors = $this->disallowedCallsRuleErrors->get(
					$node,
					$scope,
					$declaredAs,
					$calledAs,
					$class->getFileName(),
					$class->isBuiltin(),
					$disallowedCalls,
					ErrorIdentifiers::DISALLOWED_METHOD,
				);
				if ($ruleErrors) {
					return $ruleErrors;
				}
			}
		}
		return [];
	}

}
