<?php
declare(strict_types = 1);

namespace Spaze\PHPStan\Rules\Disallowed\RuleErrors;

use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\ShouldNotHappenException;
use Spaze\PHPStan\Rules\Disallowed\DisallowedCall;
use Spaze\PHPStan\Rules\Disallowed\Type\TypeResolver;

class DisallowedFunctionRuleErrors
{

	private DisallowedCallsRuleErrors $disallowedCallsRuleErrors;

	private ReflectionProvider $reflectionProvider;

	private TypeResolver $typeResolver;


	public function __construct(
		DisallowedCallsRuleErrors $disallowedCallsRuleErrors,
		ReflectionProvider $reflectionProvider,
		TypeResolver $typeResolver
	) {
		$this->disallowedCallsRuleErrors = $disallowedCallsRuleErrors;
		$this->reflectionProvider = $reflectionProvider;
		$this->typeResolver = $typeResolver;
	}


	/**
	 * @param FuncCall $node
	 * @param Scope $scope
	 * @param list<DisallowedCall> $disallowedCalls
	 * @return list<IdentifierRuleError>
	 * @throws ShouldNotHappenException
	 */
	public function get(FuncCall $node, Scope $scope, array $disallowedCalls): array
	{
		$displayName = $node->name->getAttribute('originalName');
		if ($displayName !== null && !($displayName instanceof Name)) {
			throw new ShouldNotHappenException();
		}
		foreach ($this->typeResolver->getNames($node, $scope) as $name) {
			$errors = $this->getErrors($name, $scope, $node, $displayName, $disallowedCalls);
			if ($errors) {
				return $errors;
			}
		}
		return [];
	}


	/**
	 * @param string $name
	 * @param Scope $scope
	 * @param list<DisallowedCall> $disallowedCalls
	 * @return list<IdentifierRuleError>
	 * @throws ShouldNotHappenException
	 */
	public function getByString(string $name, Scope $scope, array $disallowedCalls): array
	{
		return $name === '' ? [] : $this->getErrors(new Name($name), $scope, null, null, $disallowedCalls);
	}


	/**
	 * @param Name $name
	 * @param Scope $scope
	 * @param FuncCall|null $node
	 * @param Name|null $displayName
	 * @param list<DisallowedCall> $disallowedCalls
	 * @return list<IdentifierRuleError>
	 * @throws ShouldNotHappenException
	 */
	private function getErrors(Name $name, Scope $scope, ?FuncCall $node, ?Name $displayName, array $disallowedCalls): array
	{
		if (!$this->reflectionProvider->hasFunction($name, $scope)) {
			return [];
		}

		$functionReflection = $this->reflectionProvider->getFunction($name, $scope);
		return $this->disallowedCallsRuleErrors->get(
			$node,
			$scope,
			(string)$name,
			(string)($displayName ?? $name),
			$functionReflection->getFileName(),
			$functionReflection->isBuiltin(),
			$disallowedCalls,
			ErrorIdentifiers::DISALLOWED_FUNCTION,
		);
	}

}
