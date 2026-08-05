<?php
declare(strict_types = 1);

namespace Spaze\PHPStan\Rules\Disallowed\Calls;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\ShouldNotHappenException;
use Spaze\PHPStan\Rules\Disallowed\DisallowedCall;
use Spaze\PHPStan\Rules\Disallowed\DisallowedCallFactory;
use Spaze\PHPStan\Rules\Disallowed\RuleErrors\DisallowedCallableParameterRuleErrors;
use Spaze\PHPStan\Rules\Disallowed\RuleErrors\DisallowedCallsRuleErrors;
use Spaze\PHPStan\Rules\Disallowed\RuleErrors\ErrorIdentifiers;

/**
 * Reports on creating objects (calling constructors).
 *
 * @package Spaze\PHPStan\Rules\Disallowed
 * @implements Rule<New_>
 */
class NewCalls implements Rule
{
	public const CONSTRUCT = '__construct';

	private DisallowedCallsRuleErrors $disallowedCallsRuleErrors;

	private DisallowedCallableParameterRuleErrors $disallowedCallableParameterRuleErrors;

	/** @var list<DisallowedCall> */
	private array $disallowedCalls;


	/**
	 * @param DisallowedCallsRuleErrors $disallowedCallsRuleErrors
	 * @param DisallowedCallableParameterRuleErrors $disallowedCallableParameterRuleErrors
	 * @param DisallowedCallFactory $disallowedCallFactory
	 * @param array $forbiddenCalls
	 * @phpstan-param ForbiddenCallsConfig $forbiddenCalls
	 * @noinspection PhpUndefinedClassInspection ForbiddenCallsConfig is a type alias defined in PHPStan config
	 * @throws ShouldNotHappenException
	 */
	public function __construct(
		DisallowedCallsRuleErrors $disallowedCallsRuleErrors,
		DisallowedCallableParameterRuleErrors $disallowedCallableParameterRuleErrors,
		DisallowedCallFactory $disallowedCallFactory,
		array $forbiddenCalls
	) {
		$this->disallowedCallsRuleErrors = $disallowedCallsRuleErrors;
		$this->disallowedCallableParameterRuleErrors = $disallowedCallableParameterRuleErrors;
		$this->disallowedCalls = $disallowedCallFactory->createFromConfig($forbiddenCalls);
	}


	public function getNodeType(): string
	{
		return New_::class;
	}


	/**
	 * @param New_ $node
	 * @param Scope $scope
	 * @return list<RuleError>
	 * @throws ShouldNotHappenException
	 */
	public function processNode(Node $node, Scope $scope): array
	{
		$classNames = $names = $errors = [];
		if ($node->class instanceof Name) {
			$classNames[] = $node->class;
		} elseif ($node->class instanceof Expr) {
			$type = $scope->getType($node->class);
			foreach ($type->getConstantStrings() as $constantString) {
				$classNames[] = new Name($constantString->getValue());
			}
		}
		if ($classNames === []) {
			return [];
		}

		foreach ($classNames as $className) {
			$type = $scope->resolveTypeByName($className);
			$names[] = $type->getClassName();
			$reflection = $type->getClassReflection();
			if ($reflection) {
				foreach ($reflection->getParents() as $parent) {
					$names[] = $parent->getName();
				}
				foreach ($reflection->getInterfaces() as $interface) {
					$names[] = $interface->getName();
				}
			}
			foreach ($names as $name) {
				$ruleErrors = $this->disallowedCallsRuleErrors->get(
					$node,
					$scope,
					$name . '::' . self::CONSTRUCT,
					$type->getClassName() . '::' . self::CONSTRUCT,
					$reflection ? $reflection->getFileName() : null,
					$reflection && $reflection->isBuiltin(),
					$this->disallowedCalls,
					ErrorIdentifiers::DISALLOWED_NEW,
				);
				$paramErrors = $this->disallowedCallableParameterRuleErrors->getForConstructor(new Name($name), $node, $scope);
				if ($errors || $ruleErrors || $paramErrors) {
					$errors = array_merge($errors, $ruleErrors, $paramErrors);
				}
			}
		}

		return $errors;
	}

}
